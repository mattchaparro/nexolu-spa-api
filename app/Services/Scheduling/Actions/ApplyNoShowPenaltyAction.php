<?php

namespace App\Services\Scheduling\Actions;

use App\Models\ClientPenalty;
use App\Support\Scheduling\StageActionCatalog;

/**
 * Anota la inasistencia en la ficha de la clienta.
 *
 * Sin monto: aca no se cobra nada. Lo que queda es el registro, para que quien
 * agenda sepa que esta es la tercera vez. Cobrar por no venir es una decision
 * comercial que cada negocio toma mirando a la persona, no una que deba
 * disparar sola.
 */
class ApplyNoShowPenaltyAction implements StageAction
{
    public function type(): string
    {
        return StageActionCatalog::APPLY_NO_SHOW_PENALTY;
    }

    public function execute(StageActionContext $context): StageActionResult
    {
        $appointment = $context->appointment;

        if (! $appointment->client_id) {
            // Sin ficha no hay donde anotarlo. Crear una clienta a partir de
            // una inasistencia llenaria la base de fantasmas.
            return StageActionResult::skipped('La cita no está asociada a una ficha de clienta.');
        }

        $yaExiste = ClientPenalty::withoutGlobalScope('business')
            ->where('appointment_id', $appointment->id)
            ->where('kind', ClientPenalty::KIND_NO_SHOW)
            ->exists();

        if ($yaExiste) {
            // Mover la cita a "No asistió" dos veces no son dos faltas.
            return StageActionResult::skipped('La inasistencia ya estaba anotada.');
        }

        ClientPenalty::create([
            'business_id' => $appointment->business_id,
            'client_id' => $appointment->client_id,
            'appointment_id' => $appointment->id,
            'kind' => ClientPenalty::KIND_NO_SHOW,
            'amount' => 0,
            'reason' => 'No asistió a su cita.',
        ]);

        return StageActionResult::ok('Inasistencia anotada en la ficha.');
    }
}
