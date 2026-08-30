<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Campanas de descuento: el mes de la madre, la semana de pestanas.
 *
 * Se distingue de un combo en QUIEN la decide y por cuanto tiempo: un combo es
 * un producto permanente del catalogo, una campana es una decision de
 * temporada del negocio para traer gente. Por eso su descuento lo absorbe el
 * NEGOCIO y no baja la comision de quien atiende (ver `CommissionPolicy`), al
 * reves que un premio de fidelizacion.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('discount_campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();

            $table->string('discount_type');           // percent | amount
            $table->decimal('discount_value', 12, 2);

            // all | services | categories
            $table->string('applies_to')->default('all');

            /*
             * Vigencia por FECHA, no por hora: una campana se anuncia "del 1
             * al 15 de mayo", y quien reserva el 15 a las 6pm espera que
             * aplique. Sin fecha de fin, corre hasta que se apague a mano.
             */
            $table->date('starts_on');
            $table->date('ends_on')->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['business_id', 'is_active', 'starts_on']);
        });

        Schema::create('campaign_services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained('discount_campaigns')->cascadeOnDelete();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->unique(['campaign_id', 'service_id']);
        });

        Schema::create('campaign_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained('discount_campaigns')->cascadeOnDelete();
            $table->foreignId('service_category_id')->constrained()->cascadeOnDelete();
            $table->unique(['campaign_id', 'service_category_id']);
        });

        /*
         * Que campana se le aplico a una cita cobrada.
         *
         * Se guarda en la cita y no se recalcula: un reporte de hace seis
         * meses tiene que decir que campana estaba corriendo ese dia, no cual
         * correria hoy. Es la misma regla que el precio y la comision.
         */
        Schema::table('appointments', function (Blueprint $table) {
            $table->foreignId('discount_campaign_id')->nullable()->after('service_package_id')
                ->constrained('discount_campaigns')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('discount_campaign_id');
        });

        Schema::dropIfExists('campaign_categories');
        Schema::dropIfExists('campaign_services');
        Schema::dropIfExists('discount_campaigns');
    }
};
