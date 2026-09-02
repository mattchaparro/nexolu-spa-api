<?php

namespace App\Ai\Capabilities;

use App\Ai\AiCaller;
use App\Ai\Capability;
use App\Models\Appointment;
use App\Services\ClientPortalService;
use App\Services\Scheduling\BookingService;

/**
 * Cancelar. Solo la cita de quien escribe, y solo si aun esta a tiempo.
 *
 * El id de la cita lo propone el modelo, asi que se trata como lo que es:
 * un numero que llego por la red. Se comprueba que la cita sea del negocio Y
 * de esa clienta antes de tocarla -- sin eso, probar ids consecutivos
 * cancelaria la agenda del local entera desde un chat.
 */
class CancelAppointmentCapability implements Capability
{
    public function __construct(
        private readonly BookingService $booking,
        private readonly ClientPortalService $portal,
    ) {}

    public function requiredPermission(): ?string
    {
        return 'citas.cancelar';
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
            'motivo' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function execute(AiCaller $caller, array $arguments): array
    {
        $cita = Appointment::withoutGlobalScopes()
            ->where('business_id', $caller->business->id)
            ->find($arguments['cita_id']);

        // Una cita ajena y una inexistente se responden igual: que exista no
        // es asunto de quien pregunta.
        if ($cita === null || ($caller->isCustomer() && $cita->client_id !== $caller->client?->id)) {
            return ['cancelada' => false, 'motivo' => 'No encuentro esa cita a tu nombre.'];
        }

        if ($caller->isCustomer() && ! $this->portal->canBeChanged($cita, $caller->business)) {
            return [
                'cancelada' => false,
                'motivo' => $this->portal->reasonToRefuse($cita, $caller->business)
                    ?? 'Esa cita ya no se puede cancelar. Dile que escriba al negocio.',
            ];
        }

        $this->booking->cancel($cita, $caller->user?->id, $arguments['motivo'] ?? null);

        return ['cancelada' => true, 'id' => $cita->id];
    }
}
