<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cada nota sabe SOBRE CUANTO fue.
 *
 * La encuesta vieja de Luxury (ManyChat) no preguntaba las tres cosas con la
 * misma cantidad de botones, y se guardaron los numeros crudos como si si:
 *
 *     atencion      1 a 5   -- 132 de 141 pusieron 5
 *     servicio      1 a 4   -- 138 de 141 pusieron 4
 *     puntualidad   1 a 3   -- 127 de 141 pusieron 3
 *
 * Asi que la pantalla de nomina le viene diciendo al dueño "puntualidad 2,96"
 * al lado de "atencion 4,96", y eso se lee como si el local llegara tarde
 * siempre. Es al reves: 2,96 sobre 3 es casi el maximo. El numero no estaba
 * mal guardado, estaba mal leido, y no habia forma de leerlo bien porque la
 * fila no decia sobre cuanto era.
 *
 * La encuesta nueva pregunta las tres sobre 5, asi que sin esta columna una
 * nota vieja y una nueva no se pueden promediar: un 3 de 3 y un 3 de 5 son
 * cosas opuestas.
 *
 * Por defecto 5, que es lo que pide la encuesta propia. Solo lo migrado lleva
 * otra cosa.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_ratings', function (Blueprint $table) {
            $table->unsignedTinyInteger('service_scale')->default(5)->after('service_rating');
            $table->unsignedTinyInteger('staff_scale')->default(5)->after('staff_rating');
            $table->unsignedTinyInteger('punctuality_scale')->default(5)->after('punctuality_rating');
        });

        /*
         * Solo las filas que vinieron del sistema viejo, identificadas por el
         * libro de la migracion y no por una fecha o un "todas las de hoy":
         * cualquier nota propia que ya exista tiene que quedarse en 5.
         */
        if (! Schema::hasTable('legacy_map')) {
            return;
        }

        DB::table('service_ratings')
            ->whereIn('id', fn ($q) => $q->select('new_id')
                ->from('legacy_map')
                ->where('entity', 'rating'))
            ->update([
                'service_scale' => 4,
                'staff_scale' => 5,
                'punctuality_scale' => 3,
            ]);
    }

    public function down(): void
    {
        Schema::table('service_ratings', function (Blueprint $table) {
            $table->dropColumn(['service_scale', 'staff_scale', 'punctuality_scale']);
        });
    }
};
