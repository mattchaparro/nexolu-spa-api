<?php

use App\Models\Appointment;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Quitar «En la silla» de los flujos que la tengan sin usar.
 *
 * Lo pidió Alejandro con una frase que vale más que un diagrama: «la
 * manicurista simplemente carga el servicio cuando lo terminó; eso de en la
 * silla y que se paró es mucha vaina». Un estado que nadie mueve no informa
 * nada y sí ensucia el tablero: o se marca tarde, o no se marca, y en los dos
 * casos miente.
 *
 * SOLO se borra si NINGUNA cita la está usando. Un negocio que sí la mueva se
 * queda con ella: borrarle la etapa dejaría citas apuntando a una fila que ya
 * no existe, y esas citas son su historia.
 */
return new class extends Migration
{
    public function up(): void
    {
        $etapas = DB::table('appointment_workflow_stages')
            ->where('maps_to_status', Appointment::STATUS_IN_PROGRESS)
            ->get(['id']);

        foreach ($etapas as $etapa) {
            $enUso = DB::table('appointments')->where('stage_id', $etapa->id)->exists()
                || DB::table('appointment_stage_events')
                    ->where(fn ($q) => $q->where('to_stage_id', $etapa->id)->orWhere('from_stage_id', $etapa->id))
                    ->exists();

            if (! $enUso) {
                DB::table('appointment_workflow_stages')->where('id', $etapa->id)->delete();
            }
        }
    }

    /**
     * Irreversible a propósito.
     *
     * Volver a crearla significaría inventarle un `sort_order` y un color a
     * una etapa que el negocio ya decidió que no usa. Quien la quiera de
     * vuelta la crea desde la configuración del flujo, que es donde se
     * deciden estas cosas.
     */
    public function down(): void {}
};
