<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Garantias: rehacer un trabajo que fallo, sin cobrarle al cliente.
 *
 * Ocupa agenda como cualquier servicio -- la silla y el tiempo se gastan
 * igual -- pero vale 0 y no paga comision.
 *
 * La decision de diseno que importa es A QUIEN se le anota. No a quien rehace
 * el trabajo, sino a quien hizo el ORIGINAL: el sentido de llevar la cuenta es
 * saber quien esta recibiendo garantias. Si Maria hizo unas unas que se
 * cayeron y Ana las rehace, la garantia es de Maria.
 *
 * Por eso `warranty_for_resource_id` es la atribucion y manda, y
 * `warranty_for_item_id` es solo la evidencia -- opcional, porque el trabajo
 * original puede ser anterior al sistema o no haber quedado registrado, y en
 * ese caso igual hay que poder anotar la garantia.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointment_items', function (Blueprint $table) {
            $table->boolean('is_warranty')->default(false)->after('commission_rate');

            // A quien se le anota. Obligatorio cuando es garantia.
            $table->foreignId('warranty_for_resource_id')->nullable()->after('is_warranty')
                ->constrained('resources')->nullOnDelete();

            // El trabajo que fallo, si quedo registrado.
            $table->foreignId('warranty_for_item_id')->nullable()->after('warranty_for_resource_id')
                ->constrained('appointment_items')->nullOnDelete();

            /*
             * Que fallo, en palabras. No es decoracion: es lo unico que
             * permite distinguir una garantia por un esmalte que se corrio de
             * una por un trabajo mal hecho, y esa diferencia decide si la
             * conversacion con esa persona es una multa o una capacitacion.
             */
            $table->text('warranty_note')->nullable()->after('warranty_for_item_id');

            /*
             * Para contar garantias por persona en un periodo sin recorrer la
             * agenda entera.
             *
             * NO empieza por `business_id` a proposito: MySQL adopta como
             * indice de una foranea el primero que la cubra, y un indice que
             * arranque en `business_id` queda respaldando esa foranea. A
             * partir de ahi es indroppable, y el `down()` de esta migracion
             * -- que no tiene por que tocar la foranea de business_id --
             * falla con "needed in a foreign key constraint".
             */
            $table->index(['warranty_for_resource_id', 'starts_at'], 'items_warranty_idx');
        });
    }

    public function down(): void
    {
        /*
         * Las foraneas PRIMERO y el indice despues.
         *
         * MySQL eligio `items_warranty_idx` como indice de la foranea de
         * `warranty_for_resource_id`, asi que borrarlo antes falla con
         * "needed in a foreign key constraint" -- y un `down()` que revienta
         * deja la base a medio migrar, que es peor que no poder revertir.
         */
        Schema::table('appointment_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('warranty_for_resource_id');
            $table->dropConstrainedForeignId('warranty_for_item_id');
        });

        Schema::table('appointment_items', function (Blueprint $table) {
            $table->dropIndex('items_warranty_idx');
            $table->dropColumn(['is_warranty', 'warranty_note']);
        });
    }
};
