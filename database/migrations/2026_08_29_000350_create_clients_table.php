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

        // client_penalties vive en su propia migracion (000500) porque
        // referencia appointments, que se crea despues que esta tabla.
    }

    public function down(): void
    {
        Schema::dropIfExists('clients');
    }
};
