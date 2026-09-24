<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * De qué liquidación viene un saldo arrastrado.
 *
 * Cuando una liquidación cierra en negativo --pidió más anticipos de los que
 * devengó-- ese saldo se anota como descuento pendiente para el período
 * siguiente. Sin esta columna no había forma de saber cuál de los descuentos
 * pendientes nació así, y deshacer la liquidación dejaba el arrastre EN PIE
 * junto con los anticipos originales devueltos a pendientes: el mismo dinero
 * descontado dos veces.
 *
 * `nullOnDelete` y no `cascade`: si algún día se borrara la liquidación por
 * otra vía, el arrastre no puede desaparecer solo -- es plata que alguien
 * debe.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_adjustments', function (Blueprint $table) {
            $table->foreignId('origin_settlement_id')
                ->nullable()
                ->after('settlement_id')
                ->constrained('payroll_settlements')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payroll_adjustments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('origin_settlement_id');
        });
    }
};
