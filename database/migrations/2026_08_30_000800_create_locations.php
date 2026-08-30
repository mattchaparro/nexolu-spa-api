<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sedes: varios locales bajo un mismo negocio.
 *
 * La decision de fondo es que una sede NO es un negocio aparte. Podria haberse
 * modelado asi -- cada local su propio `business_id` -- y habria sido gratis en
 * codigo, pero rompe lo que de verdad importa: la clienta que se hace las manos
 * en Chapinero y los pies en Cedritos es LA MISMA PERSONA, con la misma
 * tarjeta de sellos y el mismo historial. Con un negocio por local serian dos
 * fichas que nadie vuelve a unir.
 *
 * Asi que la sede es una dimension DENTRO del negocio: catalogo y clientes
 * compartidos, gente y agenda por local.
 *
 * ESTA MIGRACION NO CAMBIA NINGUN COMPORTAMIENTO. A cada negocio se le crea su
 * sede "Principal" y todo lo existente queda apuntando ahi. Un negocio de un
 * solo local no se entera de que existe la dimension; el dia que abra el
 * segundo, la estructura ya esta y sus datos viejos no hay que migrarlos con
 * el local abierto.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            // Para la pagina publica de esa sede: /reservar/{negocio}/{sede}.
            $table->string('slug');
            $table->string('address')->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('city')->nullable();
            $table->string('maps_url', 500)->nullable();

            /*
             * La sede principal. Es a donde cae todo lo que no diga otra cosa
             * -- una cita heredada, un recurso recien creado desde una
             * pantalla que todavia no pregunta sede.
             */
            $table->boolean('is_primary')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['business_id', 'slug']);
            $table->index(['business_id', 'is_active']);
        });

        // Cada negocio existente estrena su sede Principal.
        foreach (DB::table('businesses')->get(['id', 'name', 'address', 'phone']) as $business) {
            DB::table('locations')->insert([
                'business_id' => $business->id,
                'name' => 'Principal',
                'slug' => 'principal',
                'address' => $business->address,
                'phone' => $business->phone,
                'is_primary' => true,
                'is_active' => true,
                'sort_order' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        /*
         * Donde trabaja cada persona, y donde ocurrio cada cita.
         *
         * En la cita se CONGELA al agendar y no se deriva del recurso al
         * leerla: si manana esa persona se traslada a otra sede, el cierre de
         * caja de hace tres meses no puede cambiar de local. Es la misma regla
         * que el precio y la comision.
         */
        Schema::table('resources', function (Blueprint $table) {
            $table->foreignId('location_id')->nullable()->after('business_id')
                ->constrained()->nullOnDelete();
            $table->index(['location_id', 'is_active']);
        });

        Schema::table('appointments', function (Blueprint $table) {
            $table->foreignId('location_id')->nullable()->after('business_id')
                ->constrained()->nullOnDelete();
            $table->index(['location_id', 'starts_at']);
        });

        // Todo lo que ya existe queda en la sede principal de su negocio.
        DB::statement('
            UPDATE resources r
            JOIN locations l ON l.business_id = r.business_id AND l.is_primary = 1
            SET r.location_id = l.id
        ');

        DB::statement('
            UPDATE appointments a
            JOIN locations l ON l.business_id = a.business_id AND l.is_primary = 1
            SET a.location_id = l.id
        ');
    }

    public function down(): void
    {
        /*
         * La foranea PRIMERO, el indice despues.
         *
         * MySQL adopta el indice compuesto como respaldo de la llave foranea
         * -- es el unico que empieza por `location_id` -- y entonces se niega
         * a dejarlo caer: "needed in a foreign key constraint". Con
         * `dropConstrainedForeignId` al final el orden queda invertido y el
         * rollback muere a mitad de camino, dejando la base en un estado que
         * hay que reparar a mano. Ya paso una vez con el indice de garantias.
         */
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropForeign(['location_id']);
            $table->dropIndex(['location_id', 'starts_at']);
            $table->dropColumn('location_id');
        });

        Schema::table('resources', function (Blueprint $table) {
            $table->dropForeign(['location_id']);
            $table->dropIndex(['location_id', 'is_active']);
            $table->dropColumn('location_id');
        });

        Schema::dropIfExists('locations');
    }
};
