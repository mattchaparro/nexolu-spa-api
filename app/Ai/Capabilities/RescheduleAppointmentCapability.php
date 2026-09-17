<?php

namespace App\Ai\Capabilities;

use App\Ai\AiArgumentException;
use App\Ai\AiCaller;
use App\Ai\Capability;
use App\Ai\Resolves;
use App\Models\Appointment;
use App\Services\ClientPortalService;
use App\Services\Scheduling\BookingService;
use App\Services\Scheduling\Exceptions\OutsideWorkingHoursException;
use App\Services\Scheduling\Exceptions\SlotUnavailableException;
use Carbon\CarbonImmutable;

/**
 * Mover una cita de hora.
 *
 * Existe para que el agente NO resuelva "cámbiame la hora" cancelando y
 * volviendo a crear: entre una llamada y la otra la hora nueva se puede
 * ocupar, y la clienta se queda sin nada. `reschedule` lo hace en una sola
 * transacción -- si la hora nueva no sirve, la vieja sigue en pie.
 *
 * Mismas guardas que cancelar: solo la cita de quien escribe, y solo si
 * todavía está a tiempo según las políticas del negocio.
 */
class RescheduleAppointmentCapability implements Capability
{
    use Resolves;

    public function __construct(
        private readonly BookingService $booking,
        private readonly ClientPortalService $portal,
    ) {}

    public function requiredPermission(): ?string
    {
        return 'citas.editar';
    }

    public function requiredFeature(): ?string
    {
        return 'scheduling';
    }

    public function allowsCustomers(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'cita_id' => ['required', 'integer'],
            'fecha' => ['required', 'date_format:Y-m-d'],
            'hora' => ['required', 'date_format:H:i'],
            'empleado' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function execute(AiCaller $caller, array $arguments): array
    {
        $business = $caller->business;

        $cita = Appointment::withoutGlobalScope('business')
            ->where('business_id', $business->id)
            ->find($arguments['cita_id']);

        // Una cita ajena y una inexistente se responden igual: que exista no
        // es asunto de quien pregunta.
        if ($cita === null || ($caller->isCustomer() && $cita->client_id !== $caller->client?->id)) {
            return ['movida' => false, 'motivo' => 'No encuentro esa cita a tu nombre.'];
        }

        if ($caller->isCustomer() && ! $this->portal->canBeChanged($cita, $business)) {
            return [
                'movida' => false,
                'motivo' => $this->portal->reasonToRefuse($cita, $business)
                    ?? 'Esa cita ya no se puede mover. Dile que escriba al negocio.',
            ];
        }

        $persona = null;

        if (! empty($arguments['empleado'])) {
            try {
                $persona = $this->resolveResource($business->id, $arguments['empleado'], $cita->location_id);
            } catch (AiArgumentException $e) {
                return ['movida' => false, 'motivo' => $e->getMessage()];
            }
        }

        $nuevoInicio = CarbonImmutable::parse(
            $arguments['fecha'].' '.$arguments['hora'],
            $business->businessTimezone(),
        );

        try {
            $cita = $this->booking->reschedule($cita, $nuevoInicio, $persona);
        } catch (SlotUnavailableException) {
            // Dato, no error: el agente ofrece otras horas en vez de
            // disculparse por una falla que no existe.
            return ['movida' => false, 'motivo' => 'Esa hora ya se ocupó. Ofrécele otras del mismo día.'];
        } catch (OutsideWorkingHoursException) {
            return ['movida' => false, 'motivo' => 'A esa hora el local no atiende ese día.'];
        } catch (\DomainException $e) {
            return ['movida' => false, 'motivo' => $e->getMessage()];
        }

        return [
            'movida' => true,
            'id' => $cita->id,
            'fecha' => $cita->starts_at->setTimezone($business->businessTimezone())->format('Y-m-d'),
            'hora' => $cita->starts_at->setTimezone($business->businessTimezone())->format('H:i'),
        ];
    }
}
