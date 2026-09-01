<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La reseña corta de cada persona del equipo, para la pagina publica.
 *
 * Corta de verdad -- 280 caracteres -- y no un textarea libre. Quien la
 * escribe es el dueño del spa entre cliente y cliente, y la lee alguien en el
 * navegador de WhatsApp con media pantalla: "Especialista en acrilicas, 8 años
 * de experiencia" se lee; tres parrafos, no.
 *
 * Se guarda en `resources` y no en un perfil aparte porque es un dato del
 * recurso, del mismo tipo que su foto y su color. Una tabla nueva para un
 * campo de texto es una junta mas en cada consulta del catalogo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('resources', function (Blueprint $table) {
            $table->string('bio', 280)->nullable()->after('photo_path');

            /*
             * Si sale en la pagina publica.
             *
             * Distinto de `is_bookable_online`: una manicurista puede no
             * aceptar reservas por internet -- porque su agenda la maneja el
             * mostrador -- y aun asi merecer estar en la vitrina del local.
             * Y al reves: la cabina 2 se puede reservar y no es una persona
             * que presentar.
             */
            $table->boolean('is_public')->default(true)->after('bio');
        });
    }

    public function down(): void
    {
        Schema::table('resources', function (Blueprint $table) {
            $table->dropColumn(['bio', 'is_public']);
        });
    }
};
