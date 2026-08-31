<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El dinero y los permisos, por sede.
 *
 * La sede ya existia en la agenda; esto la lleva a donde de verdad duele. El
 * efectivo es FISICO: hay un cajon en Chapinero y otro en Cedritos, y cada uno
 * lo cuenta quien esta ahi. Un cierre del dia que sume los dos no se puede
 * cuadrar contra ningun cajon, y la diferencia -- que es el unico dato que
 * importa de un cierre -- deja de significar nada.
 *
 * Por eso el cambio de fondo es el indice unico de `cash_closings`: pasa de
 * "un dia se cierra una vez" a "un dia se cierra una vez POR SEDE".
 *
 * Y el dueno. Hasta hoy todos los administradores eran iguales, asi que no
 * habia forma de decir "este ve las dos sedes y este solo la suya". El primer
 * administrador de un negocio es el dueno: ve todo, siempre, y no se le puede
 * restringir. Los demas ven las sedes que el les asigne.
 */
return new class extends Migration
{
    public function up(): void
    {
        $primaria = '(SELECT l.id FROM locations l WHERE l.business_id = %s AND l.is_primary = 1 LIMIT 1)';

        /*
         * Un gasto es de un local: el arriendo de Chapinero no es un gasto de
         * Cedritos. Nulo se permite para el gasto que de verdad es del negocio
         * entero -- la contadora, el dominio -- que no debe descuadrarle la
         * caja a ninguna de las dos sedes.
         */
        Schema::table('expenses', function (Blueprint $table) {
            $table->foreignId('location_id')->nullable()->after('business_id')
                ->constrained()->nullOnDelete();
            $table->index(['location_id', 'date']);
        });

        DB::statement(sprintf('UPDATE expenses SET location_id = %s', sprintf($primaria, 'expenses.business_id')));

        // El turno es de una persona en un cajon concreto.
        Schema::table('cash_shifts', function (Blueprint $table) {
            $table->foreignId('location_id')->nullable()->after('business_id')
                ->constrained()->nullOnDelete();
            $table->index(['location_id', 'opened_at']);
        });

        DB::statement(sprintf('UPDATE cash_shifts SET location_id = %s', sprintf($primaria, 'cash_shifts.business_id')));

        Schema::table('cash_closings', function (Blueprint $table) {
            $table->foreignId('location_id')->nullable()->after('business_id')
                ->constrained()->nullOnDelete();
        });

        DB::statement(sprintf('UPDATE cash_closings SET location_id = %s', sprintf($primaria, 'cash_closings.business_id')));

        /*
         * De "un dia se cierra una vez" a "un dia se cierra una vez POR SEDE".
         *
         * La foranea de `business_id` se suelta primero: MySQL adopto el unico
         * viejo como su respaldo -- es el que empieza por `business_id` -- y se
         * niega a dejarlo caer mientras la restriccion exista. El unico nuevo
         * tambien empieza por `business_id`, asi que puede volver a respaldarla.
         */
        Schema::table('cash_closings', function (Blueprint $table) {
            $table->dropForeign(['business_id']);
            $table->dropUnique(['business_id', 'date']);
            $table->unique(['business_id', 'location_id', 'date']);
            $table->foreign('business_id')->references('id')->on('businesses')->cascadeOnDelete();
        });

        /*
         * El dueno.
         *
         * No es un rol ni un permiso: es una propiedad de la persona. Un
         * permiso se puede quitar sin querer desde la pantalla de permisos, y
         * un negocio que se queda sin nadie que vea todas sus sedes es un
         * negocio que ya no puede administrarse a si mismo.
         */
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_owner')->default(false)->after('is_active');
        });

        /*
         * Que sedes ve cada quien.
         *
         * Vacio NO significa "todas". Significa que se cae al criterio seguro
         * -- la sede donde trabaja -- porque la alternativa es que una
         * recepcionista recien creada vea la clientela de los dos locales sin
         * que nadie lo haya decidido.
         */
        Schema::create('location_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'location_id']);
        });

        /*
         * El administrador mas antiguo de cada negocio es su dueno.
         *
         * Es la unica lectura posible hacia atras, y es la correcta: quien
         * abrio el negocio en el sistema fue el primero que entro. Un negocio
         * sin administradores queda sin dueno, y eso se ve en el panel en vez
         * de inventarle uno.
         */
        DB::statement("
            UPDATE users u
            JOIN (
                SELECT MIN(u2.id) AS id
                FROM users u2
                JOIN model_has_roles mhr ON mhr.model_id = u2.id AND mhr.model_type = 'App\\\\Models\\\\User'
                JOIN roles r ON r.id = mhr.role_id AND r.name = 'admin'
                WHERE u2.business_id IS NOT NULL AND u2.deleted_at IS NULL
                GROUP BY u2.business_id
            ) primeros ON primeros.id = u.id
            SET u.is_owner = 1
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('location_user');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_owner');
        });

        Schema::table('cash_closings', function (Blueprint $table) {
            $table->dropForeign(['business_id']);
            $table->dropUnique(['business_id', 'location_id', 'date']);
            $table->unique(['business_id', 'date']);
            $table->foreign('business_id')->references('id')->on('businesses')->cascadeOnDelete();
            $table->dropConstrainedForeignId('location_id');
        });

        Schema::table('cash_shifts', function (Blueprint $table) {
            $table->dropForeign(['location_id']);
            $table->dropIndex(['location_id', 'opened_at']);
            $table->dropColumn('location_id');
        });

        Schema::table('expenses', function (Blueprint $table) {
            $table->dropForeign(['location_id']);
            $table->dropIndex(['location_id', 'date']);
            $table->dropColumn('location_id');
        });
    }
};
