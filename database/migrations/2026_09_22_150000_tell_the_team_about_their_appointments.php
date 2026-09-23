<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El WhatsApp de quien atiende, para avisarle de SUS citas.
 *
 * Hasta ahora el equipo se enteraba mirando la agenda. Quien trabaja por
 * comisión quiere saber lo mismo que la clienta --que le agendaron, y que le
 * cancelaron-- sin tener que abrir el panel; y una cancelación que nadie ve
 * es una hora que alguien se queda esperando en el salón.
 *
 * Va en `resources` y no en `users` a propósito: muchas manicuristas no
 * tienen cuenta con la que entrar al sistema, y las que la tienen pueden
 * usar otro número para trabajar. Si el recurso no trae número, se cae al de
 * su usuario (ver Resource::notificationPhone).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('resources', 'phone')) {
            Schema::table('resources', function (Blueprint $table) {
                $table->string('phone', 32)->nullable()->after('bio');
            });
        }

        Schema::table('messages', function (Blueprint $table) {
            /*
             * "Uno por cita y tipo" pasa a ser "uno por cita, tipo y
             * DESTINATARIO".
             *
             * Una cita de manos y pies la atienden dos personas, y las dos
             * tienen que enterarse: con el índice viejo, el aviso de la
             * segunda chocaba con el de la primera y se descartaba en
             * silencio -- justo el mecanismo que garantiza no duplicar
             * habría garantizado no avisar.
             *
             * Para los mensajes a la clienta no cambia nada: el destinatario
             * es siempre el mismo. Salvo un caso que además mejora: si el
             * teléfono estaba mal y alguien lo corrige en la ficha, el aviso
             * puede volver a salir en vez de quedar bloqueado para siempre.
             */
            /*
             * El nuevo PRIMERO y el viejo despues, y no al reves: la llave
             * foranea de `appointment_id` se apoya en ese indice, y MySQL se
             * niega a soltarlo mientras sea el unico que la sostiene. Como el
             * nuevo tambien empieza por `appointment_id`, sirve igual.
             */
            $table->unique(['appointment_id', 'kind', 'to'], 'mensajes_uno_por_cita_tipo_y_destino');
            $table->dropUnique('mensajes_uno_por_cita_y_tipo');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            // Mismo cuidado al revés: el que se queda tiene que existir antes
            // de soltar el otro, o la foránea se queda sin índice.
            $table->unique(['appointment_id', 'kind'], 'mensajes_uno_por_cita_y_tipo');
            $table->dropUnique('mensajes_uno_por_cita_tipo_y_destino');
        });

        Schema::table('resources', function (Blueprint $table) {
            $table->dropColumn('phone');
        });
    }
};
