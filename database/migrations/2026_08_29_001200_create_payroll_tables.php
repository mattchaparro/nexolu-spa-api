<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nomina: lo que el negocio le paga a cada profesional.
 *
 * No es nomina legal (prestaciones, seguridad social, PILA): eso lo lleva el
 * contador. Esto es el control operativo -- cuanto lleva ganado, que se le
 * adelanto, y cuanto se le entrego -- que hoy se hace en una libreta.
 *
 * Tres decisiones que vienen de como opera un spa de verdad:
 *
 * 1. El periodo NO es fijo. Se liquida cuando la profesional pide, asi que el
 *    inicio lo pone el sistema (el dia siguiente a su ultima liquidacion) y no
 *    la persona que escribe el formulario. En la app del local el "desde" se
 *    tecleaba a mano: nada impedia pagar dos veces la misma quincena.
 *
 * 2. Cada liquidacion congela sus lineas. Sin eso, abrir un comprobante viejo
 *    lo recalcula contra el catalogo de hoy y el papel que firmo la profesional
 *    deja de coincidir con la pantalla.
 *
 * 3. Los ajustes pendientes entran completos, no solo los que caen dentro de la
 *    ventana. Un anticipo con fecha anterior al periodo y sin liquidar se
 *    quedaba fuera para siempre.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('resources', function (Blueprint $table) {
            /*
             * Como se le paga a esta profesional:
             *
             *   commission            -> solo comision
             *   base_plus_commission  -> base del periodo MAS la comision
             *   guaranteed_minimum    -> la comision, y si no llega a la base
             *                            se le completa (el mayor de los dos)
             */
            $table->string('payroll_mode', 32)->default('commission')->after('sort_order');

            $table->decimal('base_amount', 12, 2)->default(0)->after('payroll_mode');
            // Sobre que unidad esta expresada la base. La liquidacion la
            // convierte a tarifa diaria y la prorratea por los dias del
            // periodo, porque el periodo es irregular por diseno.
            $table->string('base_period', 16)->default('month')->after('base_amount');

            // Base temporal: la que se da mientras la profesional arranca y
            // todavia no tiene clientela. Vencida esta fecha queda a comision.
            $table->date('base_until')->nullable()->after('base_period');

            // Desde cuando se le liquida. La primera liquidacion arranca aca;
            // las siguientes, el dia despues de la anterior.
            $table->date('payroll_started_on')->nullable()->after('base_until');
        });

        Schema::create('payroll_settlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('resource_id')->constrained()->cascadeOnDelete();

            $table->date('period_start');
            $table->date('period_end');

            // Configuracion congelada: cambiarle el modo o la base a alguien no
            // debe reescribir lo que ya se le pago.
            $table->string('mode', 32);
            $table->decimal('base_amount', 12, 2)->default(0);
            $table->string('base_period', 16);

            $table->unsignedInteger('services_count')->default(0);
            // Lo que facturo para el negocio en el periodo. No es lo que se le
            // paga: es contra que se calcula.
            $table->decimal('charged_total', 12, 2)->default(0);
            $table->decimal('commission_total', 12, 2)->default(0);
            $table->decimal('base_total', 12, 2)->default(0);
            $table->decimal('bonus_total', 12, 2)->default(0);
            $table->decimal('deduction_total', 12, 2)->default(0);
            $table->decimal('net_total', 12, 2)->default(0);

            $table->dateTime('paid_at');
            $table->foreignId('payment_method_id')->nullable()->constrained()->nullOnDelete();
            // El gasto que este pago genero. La nomina sale de la caja del
            // negocio: si no aparece como gasto, el cierre del dia no cuadra.
            $table->foreignId('expense_id')->nullable()->constrained()->nullOnDelete();

            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            // Dos liquidaciones no pueden arrancar el mismo dia para la misma
            // persona. Es la red de seguridad del solape; la validacion de que
            // el periodo siga al anterior vive en el servicio.
            $table->unique(['resource_id', 'period_start']);
            $table->index(['business_id', 'paid_at']);
        });

        Schema::create('payroll_settlement_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('settlement_id')->constrained('payroll_settlements')->cascadeOnDelete();

            // Referencia debil a proposito: el comprobante sobrevive aunque la
            // cita se borre. Los datos que importan estan copiados aca.
            $table->foreignId('appointment_item_id')->nullable()->constrained()->nullOnDelete();

            $table->dateTime('charged_at');
            $table->string('service_name');
            $table->string('client_name')->nullable();
            $table->decimal('charged', 12, 2)->default(0);
            $table->decimal('commission_rate', 5, 4)->nullable();
            $table->decimal('commission_amount', 12, 2)->default(0);
            $table->timestamps();

            $table->index(['settlement_id', 'charged_at']);
        });

        Schema::create('payroll_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('resource_id')->constrained()->cascadeOnDelete();

            // Nulo mientras esta pendiente. La liquidacion que lo cobra lo
            // reclama, y por eso un ajuste solo se descuenta una vez.
            $table->foreignId('settlement_id')->nullable()
                ->constrained('payroll_settlements')->nullOnDelete();

            $table->date('date');
            // `deduction` resta, `bonus` suma. Un solo campo con signo seria
            // mas corto y volveria ilegible cualquier consulta.
            $table->string('kind', 16);
            $table->string('category', 32);
            $table->decimal('amount', 12, 2);
            $table->string('description')->nullable();

            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['business_id', 'resource_id', 'settlement_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_adjustments');
        Schema::dropIfExists('payroll_settlement_items');
        Schema::dropIfExists('payroll_settlements');

        Schema::table('resources', function (Blueprint $table) {
            $table->dropColumn([
                'payroll_mode', 'base_amount', 'base_period', 'base_until', 'payroll_started_on',
            ]);
        });
    }
};
