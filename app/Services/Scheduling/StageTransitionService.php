<?php

namespace App\Services\Scheduling;

use App\Models\Appointment;
use App\Models\AppointmentStageEvent;
use App\Models\AppointmentWorkflowStage;
use App\Models\User;
use App\Services\Scheduling\Actions\StageAction;
use App\Services\Scheduling\Actions\StageActionContext;
use App\Services\Scheduling\Actions\StageActionResult;
use App\Services\Scheduling\Exceptions\StageActionFailedException;
use App\Support\Scheduling\AppointmentStateMachine;
use App\Support\Scheduling\StageActionCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * La unica puerta por la que una cita cambia de estado.
 *
 * Antes las transiciones estaban repartidas -- reservar, cobrar, deshacer,
 * cancelar, cada una escribiendo `status` por su cuenta -- y nadie comprobaba
 * que el salto tuviera sentido ni dejaba rastro de quien lo hizo. Con
 * automatizaciones encima, eso deja de ser tolerable: si el mensaje se dispara
 * desde cinco sitios distintos, se dispara cinco veces o ninguna.
 *
 * Tres garantias:
 *
 * 1. **La transicion es legal o no ocurre.** Contra AppointmentStateMachine.
 * 2. **Las acciones criticas van dentro de la transaccion.** Si el cobro
 *    automatico falla, la cita no queda marcada como cobrada. Las demas van en
 *    mejor esfuerzo: un WhatsApp caido no puede atascar el mostrador.
 * 3. **Queda registrado**, incluidos los fallos. Una accion que fallo en
 *    silencio es peor que una que no existio.
 */
class StageTransitionService
{
    /** @var array<string, StageAction>|null */
    private ?array $actions = null;

    /**
     * Mueve la cita a una etapa concreta del flujo del negocio.
     */
    public function moveToStage(
        Appointment $appointment,
        AppointmentWorkflowStage $stage,
        ?User $actor = null,
        string $actorKind = AppointmentStageEvent::ACTOR_USER,
    ): Appointment {
        return $this->apply($appointment, $stage->maps_to_status, $stage, $actor, $actorKind);
    }

    /**
     * Mueve la cita a un estado nucleo, resolviendo sola la etapa que le
     * corresponde en el flujo del negocio, si tiene uno.
     *
     * Es la puerta que usan cobrar, cancelar y el resto del sistema: saben a
     * que estado quieren llegar, no como lo llama cada negocio.
     */
    public function moveToStatus(
        Appointment $appointment,
        string $status,
        ?User $actor = null,
        string $actorKind = AppointmentStageEvent::ACTOR_USER,
    ): Appointment {
        $workflow = $appointment->business?->appointmentWorkflow;
        $stage = $workflow?->loadMissing('stages')->stageForStatus($status);

        return $this->apply($appointment, $status, $stage, $actor, $actorKind);
    }

    /**
     * A donde puede ir esta cita, ya traducido al vocabulario del negocio.
     *
     * @return list<array<string, mixed>>
     */
    public function availableStages(Appointment $appointment): array
    {
        $workflow = $appointment->business?->appointmentWorkflow;

        if (! $workflow) {
            // Sin flujo configurado se ofrecen los estados nucleo con su
            // nombre por defecto: el negocio igual tiene que poder confirmar y
            // marcar inasistencias.
            return array_map(fn (string $status) => [
                'stage_id' => null,
                'key' => $status,
                'label' => AppointmentStateMachine::label($status),
                'color' => null,
                'maps_to_status' => $status,
            ], AppointmentStateMachine::allowedFrom($appointment->status));
        }

        return $workflow->loadMissing('stages')->stages
            ->filter(fn (AppointmentWorkflowStage $s) => $s->id !== $appointment->stage_id
                && AppointmentStateMachine::canTransition($appointment->status, $s->maps_to_status))
            ->map(fn (AppointmentWorkflowStage $s) => [
                'stage_id' => $s->id,
                'key' => $s->key,
                'label' => $s->label,
                'color' => $s->color,
                'maps_to_status' => $s->maps_to_status,
            ])
            ->values()
            ->all();
    }

    /*
    |--------------------------------------------------------------------------
    | Internos
    |--------------------------------------------------------------------------
    */

    private function apply(
        Appointment $appointment,
        string $toStatus,
        ?AppointmentWorkflowStage $stage,
        ?User $actor,
        string $actorKind,
    ): Appointment {
        $fromStatus = $appointment->status;
        $fromStageId = $appointment->stage_id;

        if ($reason = AppointmentStateMachine::reasonToRefuse($fromStatus, $toStatus)) {
            throw new \DomainException($reason);
        }

        $appointment->loadMissing(['items.service', 'items.resource.user', 'client', 'business']);

        $results = [];

        DB::transaction(function () use (
            $appointment, $toStatus, $stage, $actor, $actorKind,
            $fromStatus, $fromStageId, &$results
        ) {
            $appointment->status = $toStatus;
            $appointment->stage_id = $stage?->id;

            // Sellos de tiempo que el resto del sistema ya lee. Se ponen aca
            // y no en cada llamador para que no dependan de por donde entro
            // la transicion.
            if ($toStatus === Appointment::STATUS_CONFIRMED && $appointment->confirmed_at === null) {
                $appointment->confirmed_at = now();
            }

            if ($toStatus === Appointment::STATUS_CANCELLED && $appointment->cancelled_at === null) {
                $appointment->cancelled_at = now();
                $appointment->cancelled_by_user_id = $actor?->id;
            }

            $appointment->save();

            $results = $this->runActions($appointment, $stage, $actor, $actorKind);

            AppointmentStageEvent::create([
                'business_id' => $appointment->business_id,
                'appointment_id' => $appointment->id,
                'from_status' => $fromStatus,
                'to_status' => $toStatus,
                'from_stage_id' => $fromStageId,
                'to_stage_id' => $stage?->id,
                'user_id' => $actor?->id,
                'actor' => $actorKind,
                'actions' => $results,
            ]);
        });

        return $appointment->refresh();
    }

    /**
     * Ejecuta lo que la etapa dispara. Las criticas propagan; las demas se
     * anotan y siguen.
     *
     * @return list<array{type:string, status:string, detail:?string}>
     */
    private function runActions(
        Appointment $appointment,
        ?AppointmentWorkflowStage $stage,
        ?User $actor,
        string $actorKind,
    ): array {
        if (! $stage) {
            return [];
        }

        $flags = $appointment->business?->resolvedFeatureFlags() ?? [];
        $results = [];

        foreach ($stage->actionList() as $declared) {
            $type = $declared['type'];
            $handler = $this->handlerFor($type);

            if (! $handler) {
                $results[] = $this->row($type, StageActionResult::skipped('Acción desconocida.'));

                continue;
            }

            $feature = StageActionCatalog::featureFor($type);

            if ($feature !== null && ! ($flags[$feature] ?? false)) {
                $results[] = $this->row($type, StageActionResult::skipped(
                    "La función «{$feature}» está apagada para este negocio."
                ));

                continue;
            }

            $context = new StageActionContext($appointment, $stage, $declared['config'], $actor, $actorKind);

            if (StageActionCatalog::isCritical($type)) {
                /*
                 * Sin try: si revienta, la transaccion se deshace entera. Y si
                 * en vez de reventar DEVUELVE un fallo, se convierte en
                 * excepcion aca mismo.
                 *
                 * Los dos caminos tienen que llevar al mismo sitio. Que el
                 * fallo llegue como excepcion o como resultado es un detalle
                 * de quien escribio la accion; el contrato -- "si esto falla,
                 * la cita no se mueve" -- no puede depender de eso. Sin esta
                 * conversion, un cobro automatico que no encuentra medio de
                 * pago dejaba la cita marcada como "lista y cobrada" sin
                 * cobro, y el descuadre aparecia en el cierre horas despues.
                 */
                $result = $handler->execute($context);

                if ($result->status === StageActionResult::FAILED) {
                    throw new StageActionFailedException(
                        StageActionCatalog::label($type).': '.$result->detail
                    );
                }

                $results[] = $this->row($type, $result);

                continue;
            }

            try {
                $results[] = $this->row($type, $handler->execute($context));
            } catch (\Throwable $e) {
                // Se anota y se sigue. El log queda para poder ir a mirarlo;
                // el mostrador no se atasca.
                Log::warning('Falló una acción de etapa', [
                    'appointment_id' => $appointment->id,
                    'stage' => $stage->key,
                    'action' => $type,
                    'error' => $e->getMessage(),
                ]);

                $results[] = $this->row($type, StageActionResult::failed($e->getMessage()));
            }
        }

        return $results;
    }

    /** @return array{type:string, status:string, detail:?string} */
    private function row(string $type, StageActionResult $result): array
    {
        return ['type' => $type, 'status' => $result->status, 'detail' => $result->detail];
    }

    private function handlerFor(string $type): ?StageAction
    {
        $this->actions ??= collect([
            Actions\NotifyClientAction::class,
            Actions\NotifyStaffAction::class,
            Actions\MarkPaidAction::class,
            Actions\ReleaseSlotAction::class,
            Actions\ApplyNoShowPenaltyAction::class,
        ])
            ->map(fn (string $class) => app($class))
            ->keyBy(fn (StageAction $action) => $action->type())
            ->all();

        return $this->actions[$type] ?? null;
    }
}
