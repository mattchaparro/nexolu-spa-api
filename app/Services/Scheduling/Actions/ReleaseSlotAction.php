<?php

namespace App\Services\Scheduling\Actions;

use App\Models\ResourceOccupancy;
use App\Support\Scheduling\StageActionCatalog;

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

        return $borradas > 0
            ? StageActionResult::ok('Horario liberado.')
            : StageActionResult::skipped('El horario ya estaba libre.');
    }
}
