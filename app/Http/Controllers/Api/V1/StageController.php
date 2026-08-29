<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\AppointmentResource;
use App\Models\Appointment;
use App\Models\AppointmentStageEvent;
use App\Models\AppointmentWorkflowStage;
use App\Services\Scheduling\StageTransitionService;
use App\Support\Scheduling\AppointmentStateMachine;
use App\Support\Scheduling\StageActionCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Mover una cita de etapa, y ver por donde paso.
 */
class StageController
{
    public function __construct(private readonly StageTransitionService $transitions) {}

    /** El flujo del negocio, para pintar el selector de etapas. */
    public function workflow(Request $request): JsonResponse
    {
        $workflow = $request->user()->business?->appointmentWorkflow;

        return response()->json([
            'name' => $workflow?->name,
            'stages' => $workflow
                ? $workflow->stages->map(fn (AppointmentWorkflowStage $s) => [
                    'id' => $s->id,
                    'key' => $s->key,
                    'label' => $s->label,
                    'color' => $s->color,
                    'maps_to_status' => $s->maps_to_status,
                    'is_initial' => $s->is_initial,
                    'actions' => array_map(
                        fn (array $a) => [
                            'type' => $a['type'],
                            'label' => StageActionCatalog::label($a['type']),
                        ],
                        $s->actionList(),
                    ),
                ])->values()
                : [],
        ]);
    }

    /** A donde puede ir ESTA cita desde donde esta. */
    public function options(Appointment $appointment): JsonResponse
    {
        return response()->json([
            'current' => [
                'status' => $appointment->status,
                'status_label' => AppointmentStateMachine::label($appointment->status),
                'stage_id' => $appointment->stage_id,
            ],
            'options' => $this->transitions->availableStages($appointment),
        ]);
    }

    public function move(Request $request, Appointment $appointment): JsonResponse
    {
        $data = $request->validate([
            // Una de las dos: la etapa del negocio, o el estado nucleo para
            // quien no configuro flujo.
            'stage_id' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in(Appointment::statuses())],
        ]);

        if (empty($data['stage_id']) && empty($data['status'])) {
            return response()->json(['message' => 'Falta la etapa a la que mover la cita.'], 422);
        }

        try {
            if (! empty($data['stage_id'])) {
                $stage = AppointmentWorkflowStage::whereKey($data['stage_id'])
                    // Del flujo de ESTE negocio: mandar el id de una etapa de
                    // otro flujo pondria la cita en un estado que su negocio
                    // no conoce.
                    ->where('workflow_id', $request->user()->business?->appointment_workflow_id)
                    ->firstOrFail();

                $moved = $this->transitions->moveToStage($appointment, $stage, $request->user());
            } else {
                $moved = $this->transitions->moveToStatus($appointment, $data['status'], $request->user());
            }
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'appointment' => new AppointmentResource($moved->load('items.service', 'items.resource')),
            // Que se disparo, en la misma respuesta: si el WhatsApp no salio,
            // quien movio la cita tiene que enterarse ahi y no nunca.
            'actions' => $this->lastActions($moved),
        ]);
    }

    /** Por donde paso esta cita. */
    public function history(Appointment $appointment): JsonResponse
    {
        $events = AppointmentStageEvent::with('user')
            ->where('appointment_id', $appointment->id)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get()
            ->map(fn (AppointmentStageEvent $e) => [
                'id' => $e->id,
                'at' => $e->created_at?->toIso8601String(),
                'from' => $e->from_status ? AppointmentStateMachine::label($e->from_status) : null,
                'to' => AppointmentStateMachine::label($e->to_status),
                'by' => $e->user?->fullName(),
                'actor' => $e->actor,
                'actions' => array_map(
                    fn (array $a) => [
                        'label' => StageActionCatalog::label($a['type']),
                        'status' => $a['status'],
                        'detail' => $a['detail'] ?? null,
                    ],
                    $e->actions ?? [],
                ),
            ]);

        return response()->json($events);
    }

    /** @return list<array<string, mixed>> */
    private function lastActions(Appointment $appointment): array
    {
        $event = AppointmentStageEvent::where('appointment_id', $appointment->id)
            ->latest('id')
            ->first();

        return array_map(
            fn (array $a) => [
                'label' => StageActionCatalog::label($a['type']),
                'status' => $a['status'],
                'detail' => $a['detail'] ?? null,
            ],
            $event?->actions ?? [],
        );
    }
}
