<?php

use App\Models\Appointment;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Encender el "Gracias por tu visita" al terminar el servicio.
 *
 * La etapa «Lista y cobrada» se sembró SIN acciones, así que hoy no sale
 * nada cuando la manicurista termina: se pierde el gracias, el estado de la
 * tarjeta de sellos y la invitación a calificar -- las tres cosas que Luxury
 * sí manda desde ManyChat.
 *
 * SOLO se enciende donde nadie configuró nada. Una etapa con acciones ya
 * puestas se deja como está: el negocio decidió qué pasa ahí, y agregarle un
 * mensaje que no pidió es mandarle WhatsApps a sus clientas a su nombre.
 */
return new class extends Migration
{
    public function up(): void
    {
        $etapas = DB::table('appointment_workflow_stages')
            ->where('maps_to_status', Appointment::STATUS_COMPLETED)
            ->get(['id', 'actions']);

        foreach ($etapas as $etapa) {
            $acciones = json_decode((string) $etapa->actions, true);

            if (is_array($acciones) && $acciones !== []) {
                continue;
            }

            DB::table('appointment_workflow_stages')
                ->where('id', $etapa->id)
                ->update(['actions' => json_encode(
                    [['type' => 'notify_client', 'config' => ['template' => '']]],
                    JSON_UNESCAPED_UNICODE,
                )]);
        }
    }

    /**
     * Se puede apagar, y por eso este `down` sí existe: deja la etapa como
     * estaba, sin acciones. Lo que no se puede es adivinar cuáles tenía si
     * alguien le agregó otras después, así que solo se limpia la que quedó
     * exactamente como la dejó `up`.
     */
    public function down(): void
    {
        $nuestra = json_encode(
            [['type' => 'notify_client', 'config' => ['template' => '']]],
            JSON_UNESCAPED_UNICODE,
        );

        DB::table('appointment_workflow_stages')
            ->where('maps_to_status', Appointment::STATUS_COMPLETED)
            ->where('actions', $nuestra)
            ->update(['actions' => json_encode([])]);
    }
};
