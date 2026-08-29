<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_methods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('name');

            // Si entra a la caja fisica. Un pago por transferencia suma a la
            // venta del dia pero NO al efectivo que debe haber en el cajon:
            // sin esta distincion el cierre nunca cuadra.
            $table->boolean('counts_as_cash')->default(false);

            // Comision de la pasarela o del datafono, que se descuenta del
            // ingreso real del negocio. 0.0250 = 2.5%.
            $table->decimal('provider_fee_rate', 5, 4)->default(0);

            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['business_id', 'is_active']);
        });

        Schema::table('appointments', function (Blueprint $table) {
            $table->foreignId('payment_method_id')->nullable()->after('source')->constrained()->nullOnDelete();
            $table->dateTime('checked_out_at')->nullable()->after('confirmed_at');
            $table->foreignId('checked_out_by_user_id')->nullable()->after('checked_out_at')->constrained('users')->nullOnDelete();

            // Totales congelados al cobrar. Se guardan en vez de recalcularse
            // porque los precios del catalogo cambian: un reporte de hace seis
            // meses tiene que seguir mostrando lo que de verdad se cobro.
            $table->decimal('subtotal', 12, 2)->nullable()->after('checked_out_by_user_id');
            $table->decimal('discount_amount', 12, 2)->default(0)->after('subtotal');
            $table->string('discount_reason')->nullable()->after('discount_amount');
            $table->decimal('total', 12, 2)->nullable()->after('discount_reason');
            $table->decimal('commission_total', 12, 2)->nullable()->after('total');

            $table->index(['business_id', 'checked_out_at']);
        });

        Schema::table('appointment_items', function (Blueprint $table) {
            // Lo que de verdad se cobro por esta linea, despues de repartir
            // el descuento de la cita. `price` conserva el de lista.
            $table->decimal('final_price', 12, 2)->nullable()->after('price');
            $table->decimal('commission_amount', 12, 2)->nullable()->after('commission_rate');
        });
    }

    public function down(): void
    {
        Schema::table('appointment_items', function (Blueprint $table) {
            $table->dropColumn(['final_price', 'commission_amount']);
        });

        Schema::table('appointments', function (Blueprint $table) {
            $table->dropForeign(['payment_method_id']);
            $table->dropForeign(['checked_out_by_user_id']);
            $table->dropColumn([
                'payment_method_id', 'checked_out_at', 'checked_out_by_user_id',
                'subtotal', 'discount_amount', 'discount_reason', 'total', 'commission_total',
            ]);
        });

        Schema::dropIfExists('payment_methods');
    }
};
