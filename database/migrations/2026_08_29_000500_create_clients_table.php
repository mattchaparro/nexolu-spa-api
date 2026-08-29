<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('last_name')->nullable();

            // Siempre normalizado a E.164 (+573001234567). Blue Souls
            // concatenaba "57" a mano en ocho lugares distintos.
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->date('birth_date')->nullable();
            $table->string('gender')->nullable();
            $table->text('notes')->nullable();

            // Preferencia de profesional, para que la agenda y el agente de
            // WhatsApp puedan sugerir sin preguntar cada vez.
            $table->foreignId('preferred_resource_id')->nullable();

            $table->boolean('accepts_marketing')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['business_id', 'phone']);
            $table->index(['business_id', 'name']);
        });

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
        Schema::dropIfExists('clients');
    }
};
