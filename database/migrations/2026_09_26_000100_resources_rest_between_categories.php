<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Días de descanso entre servicios de una categoría, por persona.
 *
 * Marcela no aguanta pedicures todos los días: si el lunes hizo uno, el
 * siguiente es el miércoles. Se guarda como [{category_id, rest_days}] en
 * la persona y la disponibilidad deja de ofrecerla para esa categoría los
 * días vecinos a uno en que ya tiene (ver AvailabilityService).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('resources', function (Blueprint $table) {
            $table->json('category_rest_days')->nullable()->after('is_public');
        });
    }

    public function down(): void
    {
        Schema::table('resources', function (Blueprint $table) {
            $table->dropColumn('category_rest_days');
        });
    }
};
