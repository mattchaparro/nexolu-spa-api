<?php

namespace App\Services\Scheduling\Actions;

use App\Models\ResourceOccupancy;
use App\Services\Waitlist\WaitlistService;
use App\Support\Scheduling\StageActionCatalog;
use Illuminate\Support\Facades\Log;

/**
 * Devuelve el horario a la agenda.
 *
 * CRITICA: si esto falla y la transicion siguiera, el hueco quedaria bloqueado
 * para siempre por una cita que ya no existe, y nadie sabria por que la agenda
 * dice ocupado a las tres de la tarde.
 */
class ReleaseSlotAction implements StageAction
{
    public function type(): string
    {
        return StageActionCatalog::RELEASE_SLOT;
    }

    public function execute(StageActionContext $context): StageActionResult
    {
        $ids = $context->appointment->items()->pluck('id');

        $borradas = ResourceOccupancy::whereIn('appointment_item_id', $ids)->delete();

        if ($borradas > 0) {
            /*
             * Una inasistencia tambien libera un cupo que alguien puede
             * querer. Blindado igual que en BookingService: la lista de
             * espera no puede impedir marcar la inasistencia.
             */
            try {
                app(WaitlistService::class)
                    ->appointmentFreed($context->appointment->fresh(['items.resource', 'business']));
            } catch (\Throwable $e) {
                Log::warning('Fallo el aviso a la lista de espera', [
                    'appointment_id' => $context->appointment->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $borradas > 0
            ? StageActionResult::ok('Horario liberado.')
            : StageActionResult::skipped('El horario ya estaba libre.');
    }
}
