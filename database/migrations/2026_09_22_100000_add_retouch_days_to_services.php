<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cada cuánto se retoca un servicio.
 *
 * El semipermanente se levanta a las tres semanas, el acrílico pide relleno
 * al mes, y un retiro no se retoca nunca. Ese dato vive en la cabeza de
 * quien atiende y por eso el recordatorio de retoque no existía: sin saber
 * cada cuánto, no hay a quién escribirle.
 *
 * `null` = el valor por defecto de la plataforma (config spa.defaults);
 * `0` = este servicio NO se retoca, y nunca dispara recordatorio.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->unsignedSmallInteger('retouch_days')->nullable()->after('duration_min');
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn('retouch_days');
        });
    }
};
