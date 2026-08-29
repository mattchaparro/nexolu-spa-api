<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Catalogo global, administrado por la plataforma. Cada negocio elige
        // cuales usa; ninguno inventa los suyos.
        //
        // Que "efectivo entra al cajon" y "datafono no" es una propiedad del
        // MEDIO, no del negocio: dejar que cada uno lo definiera permitiria
        // marcar el datafono como efectivo y descuadrar el cierre sin que
        // nada lo impida.
        Schema::create('platform_payment_methods', function (Blueprint $table) {
            $table->id();
            $table->string('key', 60)->unique();
            $table->string('label', 100);
            $table->boolean('counts_as_cash')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::table('payment_methods', function (Blueprint $table) {
            // La fila del negocio apunta al catalogo. Se conserva la fila por
            // negocio y no se apunta directo al catalogo global porque las
            // citas y los gastos ya la referencian: un cobro historico tiene
            // que seguir sabiendo con que se pago aunque el negocio despues
            // deje de aceptar ese medio.
            $table->foreignId('platform_payment_method_id')
                ->nullable()
                ->after('business_id')
                ->constrained()
                ->nullOnDelete();

            $table->unique(['business_id', 'platform_payment_method_id'], 'business_platform_method_unique');
        });
    }

    public function down(): void
    {
        Schema::table('payment_methods', function (Blueprint $table) {
            $table->dropUnique('business_platform_method_unique');
            $table->dropConstrainedForeignId('platform_payment_method_id');
        });

        Schema::dropIfExists('platform_payment_methods');
    }
};
