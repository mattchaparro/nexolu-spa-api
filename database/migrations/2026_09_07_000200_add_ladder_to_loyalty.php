<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La tarjeta de sellos gana un segundo modo: ESCALERA.
 *
 * Hasta ahora habia uno solo -- junta N sellos, cobra el premio, la tarjeta
 * vuelve a cero -- y para muchos negocios es el correcto. Pero Luxury lleva
 * anos con otro, y sus clientas lo conocen:
 *
 *   5 visitas -> 10%    20 visitas -> 10%
 *  10 visitas -> 10%    25 visitas -> 15%
 *  15 visitas -> 15%    30 visitas -> un producto
 *                       35 visitas -> 25%
 *
 * La diferencia no es cosmetica: en la escalera los sellos NO SE GASTAN. El
 * contador sube para siempre, y cada hito entrega un premio distinto. Una
 * clienta con 22 visitas tiene 22 sellos, no 2.
 *
 * Los dos modos conviven en la misma tabla porque comparten todo lo demas --
 * sellos, premios congelados, canje en el cobro -- y separarlos en dos
 * sistemas obligaria a mantener dos veces cada pantalla.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loyalty_programs', function (Blueprint $table) {
            /*
             * `card` es el default a proposito: es lo que hacen hoy todos los
             * programas que existen, y una migracion no puede cambiarle las
             * reglas de fidelizacion a un negocio sin que nadie lo pida.
             */
            $table->string('mode', 16)->default('card')->after('name');
        });

        Schema::create('loyalty_tiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('program_id')->constrained('loyalty_programs')->cascadeOnDelete();

            $table->unsignedSmallInteger('stamps_required');

            // Mismo trio que el programa: cada escalon premia distinto.
            $table->string('reward_type');
            $table->decimal('reward_value', 12, 2)->nullable();
            $table->foreignId('reward_service_id')->nullable()
                ->constrained('services')->nullOnDelete();

            /*
             * Un escalon retirado se APAGA, no se borra.
             *
             * Borrarlo dejaria los premios que ya entrego apuntando a nada, y
             * si el negocio vuelve a poner ese escalon manana, todas las
             * clientas que ya lo ganaron lo ganarian OTRA VEZ. Apagado, la
             * historia se conserva y deja de entregar.
             */
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            /*
             * Un escalon por numero de sellos. Dos premios distintos a las 10
             * visitas es una pregunta sin respuesta en el mostrador.
             */
            $table->unique(['program_id', 'stamps_required'], 'loyalty_tiers_escalon_unico');
        });

        /*
         * De que escalon salio cada premio.
         *
         * Es lo que impide entregar dos veces el premio de las 10 visitas: en
         * la escalera los sellos no se gastan, asi que el saldo sigue siendo
         * >= 10 para siempre y sin esta marca cada cobro posterior volveria a
         * desbloquearlo.
         *
         * Nulo en el modo `card`, donde el reinicio de la tarjeta ya cumple
         * esa funcion.
         *
         * LA COLUMNA Y SU CLAVE FORANEA VAN EN LLAMADAS SEPARADAS. Declarar
         * `foreignId(...)->after(...)->constrained(...)` en un `Schema::table`
         * crea la columna y el indice pero NO la restriccion, en silencio: la
         * primera version de esta migracion dejo `tier_id` sin clave foranea,
         * y solo se noto al intentar revertirla.
         */
        Schema::table('loyalty_rewards', function (Blueprint $table) {
            $table->unsignedBigInteger('tier_id')->nullable()->after('program_id');
        });

        Schema::table('loyalty_rewards', function (Blueprint $table) {
            $table->foreign('tier_id')->references('id')->on('loyalty_tiers')->nullOnDelete();

            /*
             * `tier_id` PRIMERO, y no `client_id`. La unicidad es la misma,
             * pero el orden decide de que se puede colgar MySQL: con
             * client_id a la izquierda, este indice pasa a sostener la clave
             * foranea de client_id y ya no se puede soltar -- revertir la
             * migracion muere con "needed in a foreign key constraint".
             */
            $table->unique(['tier_id', 'client_id'], 'loyalty_rewards_escalon_una_vez');
        });
    }

    public function down(): void
    {
        /*
         * El orden importa y MySQL es explicito al respecto: primero la clave
         * foranea, despues el indice que la sostiene, y solo entonces la
         * columna. Al reves responde "Cannot drop index: needed in a foreign
         * key constraint" -- ya nos paso una vez en este proyecto.
         */
        Schema::table('loyalty_rewards', function (Blueprint $table) {
            $table->dropForeign(['tier_id']);
            $table->dropUnique('loyalty_rewards_escalon_una_vez');
            $table->dropColumn('tier_id');
        });

        Schema::dropIfExists('loyalty_tiers');

        Schema::table('loyalty_programs', function (Blueprint $table) {
            $table->dropColumn('mode');
        });
    }
};
