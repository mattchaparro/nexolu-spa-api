<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Excepciones de tope por negocio.
 *
 * Mismo patron que `feature_flags`: el plan trae el default y esta columna
 * guarda SOLO lo que se le concedio distinto a ese negocio. Asi se le puede
 * vender una excepcion -- "el plan basico son 3, pero a este local le dejamos
 * 5" -- sin inventarle un plan nuevo ni tocar los presets de todos.
 *
 * Nulo = sin excepciones, manda el plan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->json('plan_limits')->nullable()->after('feature_flags');
        });
    }

    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->dropColumn('plan_limits');
        });
    }
};
