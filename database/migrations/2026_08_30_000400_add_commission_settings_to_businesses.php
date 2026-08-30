<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sobre que valor se paga comision cuando hubo descuento.
 *
 * Columna propia y no dentro de `scheduling_settings`: esto no es una regla de
 * agenda sino de nomina, y meterla ahi obligaria a que quien busque como se le
 * paga al equipo la encuentre en la configuracion de horarios.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->json('commission_settings')->nullable()->after('scheduling_settings');
        });
    }

    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->dropColumn('commission_settings');
        });
    }
};
