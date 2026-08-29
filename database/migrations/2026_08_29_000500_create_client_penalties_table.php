<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Va despues de las tablas de agenda porque referencia `appointments`.
     */
    public function up(): void
    {
        // Inasistencias y cancelaciones tardias. Aplicar o no una multa es
        // decision del negocio (scheduling_settings), no del codigo.
        Schema::create('client_penalties', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('appointment_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind')->default('no_show'); // no_show | late_cancellation
            $table->decimal('amount', 12, 2)->default(0);
            $table->string('reason')->nullable();
            $table->dateTime('waived_at')->nullable();
            $table->foreignId('waived_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['business_id', 'client_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_penalties');
    }
};
