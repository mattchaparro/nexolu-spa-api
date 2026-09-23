<?php

use App\Models\Appointment;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Que la confirmación del panel diga lo mismo que la del bot.
 *
 * La etapa «Confirmada» se sembró con un renglón suelto ("te confirmamos tu
 * cita en X: Y el Z a las W"). La confirmación buena --día, hora, servicio,
 * precio y quién atiende, cada cosa en su renglón-- ya existe y es la que
 * recibe quien agenda por el chat; vaciar el texto de la etapa es lo que hace
 * que salga también cuando la cita la agenda el salón (ver
 * StageMessage::render).
 *
 * SOLO se toca lo que todavía dice EXACTAMENTE el texto sembrado. A quien
 * escribió el suyo no se le borra: una migración que pisa lo que una persona
 * redactó es una sorpresa que se descubre por las quejas.
 */
return new class extends Migration
{
    private const SEMBRADO = 'Hola {cliente}, te confirmamos tu cita en {negocio}: {servicio} el {fecha} a las {hora} con {profesional}. ¡Te esperamos!';

    public function up(): void
    {
        foreach ($this->etapasConfirmadas() as $etapa) {
            $acciones = json_decode((string) $etapa->actions, true);

            if (! is_array($acciones)) {
                continue;
            }

            $cambio = false;

            foreach ($acciones as $i => $accion) {
                if (($accion['type'] ?? null) !== 'notify_client') {
                    continue;
                }

                if (($accion['config']['template'] ?? null) !== self::SEMBRADO) {
                    continue;
                }

                $acciones[$i]['config']['template'] = '';
                $cambio = true;
            }

            if ($cambio) {
                DB::table('appointment_workflow_stages')
                    ->where('id', $etapa->id)
                    ->update(['actions' => json_encode($acciones, JSON_UNESCAPED_UNICODE)]);
            }
        }
    }

    /**
     * Irreversible a propósito.
     *
     * Devolver el renglón suelto significaría reescribir un texto que a esta
     * altura alguien pudo haber ajustado a mano. Un `down` que pisa trabajo
     * ajeno es peor que no tener `down`.
     */
    public function down(): void {}

    /** @return iterable<object> */
    private function etapasConfirmadas(): iterable
    {
        return DB::table('appointment_workflow_stages')
            ->where('maps_to_status', Appointment::STATUS_CONFIRMED)
            ->whereNotNull('actions')
            ->get(['id', 'actions']);
    }
};
