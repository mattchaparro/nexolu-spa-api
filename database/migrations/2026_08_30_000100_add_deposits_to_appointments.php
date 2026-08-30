<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El abono que separa una cita.
 *
 * `deposit_amount` se CONGELA al reservar, igual que el precio de cada linea.
 * Si el negocio sube el abono del 20% al 40% la semana entrante, a quien ya
 * reservo se le sigue pidiendo lo que le dijo la pantalla el dia que reservo.
 *
 * `deposit_paid_at` lleva su propia fecha y su propio metodo porque la plata
 * entra un dia y el servicio se cobra otro: el cierre de caja necesita contar
 * el abono el dia que llego, no el dia de la cita.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->decimal('deposit_amount', 12, 2)->default(0)->after('discount_reason');
            $table->timestamp('deposit_paid_at')->nullable()->after('deposit_amount');
            $table->foreignId('deposit_payment_method_id')->nullable()->after('deposit_paid_at')
                ->constrained('payment_methods')->nullOnDelete();
            $table->string('deposit_reference')->nullable()->after('deposit_payment_method_id');

            // Para listar "quien debe abono" sin recorrer la agenda entera.
            $table->index(['business_id', 'deposit_paid_at'], 'appointments_deposit_idx');
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropIndex('appointments_deposit_idx');
            $table->dropConstrainedForeignId('deposit_payment_method_id');
            $table->dropColumn(['deposit_amount', 'deposit_paid_at', 'deposit_reference']);
        });
    }
};
