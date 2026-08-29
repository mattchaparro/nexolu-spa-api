<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Combos: varios servicios que se venden juntos, normalmente con descuento.
 *
 * Tabla propia y NO un `Service` con `is_package = true`. Un combo no tiene
 * duracion propia ni un recurso que lo preste ni un precio escrito -- todo eso
 * sale de sus partes -- y meterlo en `services` obligaria a que cada consulta
 * del catalogo se acuerde de filtrarlo. La que se olvide ofrece un combo como
 * si fuera un servicio agendable, y el motor de disponibilidad no sabe que
 * hacer con el.
 *
 * El descuento se guarda como REGLA (tipo + valor), no como precios por linea.
 * El checkout ya sabe repartir un descuento entre las lineas y calcular la
 * comision sobre lo cobrado; si el combo reescribiera los precios, habria dos
 * formas de rebajar en el sistema y solo una bajaria la comision -- y el
 * equipo cobraria comision sobre plata que el negocio no recibio.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_packages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->string('slug');
            $table->string('description')->nullable();
            $table->string('image_path')->nullable();

            // price | percent | fixed | none. Ver App\Support\Money\PackagePricing.
            $table->string('discount_type', 16)->default('none');
            // Su significado depende del tipo: pesos del combo, porcentaje, o
            // pesos de rebaja. Nulo mientras no se decida cuanto rebaja.
            $table->decimal('discount_value', 12, 2)->nullable();

            $table->boolean('is_active')->default(true);
            $table->boolean('is_bookable_online')->default(true);
            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();

            $table->unique(['business_id', 'slug']);
            $table->index(['business_id', 'is_active']);
        });

        Schema::create('service_package_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('package_id')->constrained('service_packages')->cascadeOnDelete();
            // Sin cascade: borrar un servicio que esta dentro de un combo tiene
            // que ser una decision consciente, no un efecto colateral que deja
            // el combo cobrando por algo que ya no existe.
            $table->foreignId('service_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('sort_order')->default(0);

            $table->unique(['package_id', 'service_id']);
        });

        Schema::table('appointments', function (Blueprint $table) {
            /*
             * De que combo salio esta cita.
             *
             * El combo se elige al AGENDAR y el descuento se aplica al COBRAR,
             * asi que hace falta recordarlo en el medio. Sin esto, quien cobra
             * tres dias despues no tiene forma de saber que esas tres lineas
             * eran un combo y las cobra a precio de lista.
             *
             * `nullOnDelete`: borrar el combo del catalogo no puede borrar las
             * citas que se vendieron con el.
             */
            $table->foreignId('service_package_id')->nullable()->after('source')
                ->constrained('service_packages')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropForeign(['service_package_id']);
            $table->dropColumn('service_package_id');
        });

        Schema::dropIfExists('service_package_items');
        Schema::dropIfExists('service_packages');
    }
};
