<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expense_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['business_id', 'name']);
        });

        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('expense_type_id')->nullable()->constrained()->nullOnDelete();

            // Fecha CONTABLE, separada de created_at. Un gasto registrado hoy
            // con fecha de ayer pertenece al cierre de ayer: la caja de hoy
            // nunca perdio esa plata.
            $table->date('date');

            $table->string('description');
            $table->decimal('value', 12, 2);

            // operacional cuenta contra la caja del dia; administrativo no.
            // Sin la distincion, pagar el arriendo por transferencia
            // descuadraria el efectivo del mostrador.
            $table->string('scope')->default('operacional');

            $table->foreignId('payment_method_id')->nullable()->constrained()->nullOnDelete();
            $table->string('receipt_path')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['business_id', 'date']);
        });

        // Turno de caja de UNA persona: desde que abre con su base hasta que
        // cuenta y cierra.
        Schema::create('cash_shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained();
            $table->dateTime('opened_at');
            $table->dateTime('closed_at')->nullable();

            $table->decimal('opening_cash', 12, 2)->default(0);
            $table->string('opening_note')->nullable();

            // Lo que la persona conto fisicamente al cerrar, frente a lo que
            // el sistema esperaba. La diferencia se guarda: es el dato que
            // importa, y esconderlo seria peor que mostrarlo.
            $table->decimal('counted_cash', 12, 2)->nullable();
            $table->decimal('expected_cash', 12, 2)->nullable();
            $table->decimal('difference', 12, 2)->nullable();
            $table->string('closing_note')->nullable();

            $table->decimal('total_charged', 12, 2)->nullable();
            $table->decimal('total_cash', 12, 2)->nullable();
            $table->decimal('total_other_methods', 12, 2)->nullable();
            $table->decimal('total_expenses', 12, 2)->nullable();
            $table->json('payment_breakdown')->nullable();

            $table->foreignId('closed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['business_id', 'opened_at']);
            $table->index(['user_id', 'closed_at']);
        });

        // Cierre del dia del NEGOCIO entero, distinto del turno de una
        // persona. Un dia puede tener varios turnos.
        Schema::create('cash_closings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->date('date');

            $table->decimal('total_charged', 12, 2)->default(0);
            $table->decimal('total_cash', 12, 2)->default(0);
            $table->decimal('total_other_methods', 12, 2)->default(0);
            $table->json('payment_breakdown')->nullable();

            $table->decimal('opening_cash', 12, 2)->default(0);
            $table->decimal('total_expenses', 12, 2)->default(0);
            $table->decimal('expected_cash', 12, 2)->default(0);
            $table->decimal('actual_cash', 12, 2)->default(0);
            $table->decimal('difference', 12, 2)->default(0);

            // Lo que queda de base para manana. Sin esto, cada dia arrancaria
            // en cero y el cierre siguiente nunca cuadraria.
            $table->decimal('base_for_next_day', 12, 2)->default(0);

            $table->decimal('total_commissions', 12, 2)->default(0);
            $table->string('note')->nullable();
            $table->foreignId('closed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Un dia se cierra una sola vez.
            $table->unique(['business_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_closings');
        Schema::dropIfExists('cash_shifts');
        Schema::dropIfExists('expenses');
        Schema::dropIfExists('expense_types');
    }
};
