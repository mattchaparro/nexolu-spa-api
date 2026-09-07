<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Un premio que no es plata necesita decirse con palabras.
 *
 * Hasta ahora los tres tipos de premio se describian solos: un porcentaje, un
 * monto, o un servicio del catalogo. El de Luxury a las 30 visitas es "un
 * producto de nuestra marca al azar" -- no tiene precio ni apunta al catalogo,
 * y sin un campo de texto no hay forma de guardarlo.
 *
 * La columna se llama `reward_note` y no `product_name` a proposito: el
 * proximo premio que se le ocurra a alguien tampoco va a caber en un numero
 * ("una copa de vino", "traiga una amiga y las dos con 20%"), y todos esos
 * caben aca sin otra migracion.
 *
 * Va en las TRES tablas porque el premio se congela al ganarlo, igual que el
 * precio de una cita cobrada: si el negocio cambia manana lo que regala, a
 * quien ya llego se le entrega lo que decia el dia que llego.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['loyalty_programs', 'loyalty_tiers', 'loyalty_rewards'] as $tabla) {
            Schema::table($tabla, function (Blueprint $table) {
                $table->string('reward_note', 200)->nullable()->after('reward_service_id');
            });
        }
    }

    public function down(): void
    {
        foreach (['loyalty_programs', 'loyalty_tiers', 'loyalty_rewards'] as $tabla) {
            Schema::table($tabla, function (Blueprint $table) {
                $table->dropColumn('reward_note');
            });
        }
    }
};
