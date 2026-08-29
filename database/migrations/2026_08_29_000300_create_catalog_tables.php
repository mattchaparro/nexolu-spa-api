<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Un servicio del catalogo: que se presta, cuanto dura, cuanto cuesta.
        Schema::create('services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->text('description')->nullable();
            $table->foreignId('service_category_id')->nullable();

            // La duracion es del servicio, no una constante global. Blue Souls
            // asumia bloques de 120 minutos para todo.
            $table->unsignedSmallInteger('duration_min');

            // Ocupan el recurso pero no se cobran: montaje y limpieza.
            $table->unsignedSmallInteger('buffer_before_min')->default(0);
            $table->unsignedSmallInteger('buffer_after_min')->default(0);

            $table->decimal('price', 12, 2)->default(0);
            $table->decimal('commission_rate', 5, 4)->nullable(); // 0.3000 = 30%
            $table->boolean('is_bookable_online')->default(true);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['business_id', 'slug']);
            $table->index(['business_id', 'is_active']);
        });

        Schema::create('service_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        // Un recurso es cualquier cosa que una cita consume en exclusiva:
        // la profesional, la silla, la cabina, el lavacabezas. Blue Souls solo
        // modelaba personas, por eso no podia representar "manicurista Y cabina".
        Schema::create('resources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('type')->default('staff'); // staff | station | room | equipment
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('color', 7)->nullable(); // para la agenda visual
            $table->boolean('is_bookable_online')->default(true);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['business_id', 'type', 'is_active']);
        });

        // Que recurso puede prestar que servicio, y si tarda distinto.
        Schema::create('service_resource', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->foreignId('resource_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('duration_override_min')->nullable();
            $table->decimal('commission_rate_override', 5, 4)->nullable();

            $table->unique(['service_id', 'resource_id']);
        });

        // Cuando un servicio necesita mas de un recurso a la vez
        // (profesional + cabina), cada requisito es una fila.
        Schema::create('service_resource_requirements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->string('resource_type'); // staff | station | room | equipment
            $table->unsignedTinyInteger('quantity')->default(1);

            $table->unique(['service_id', 'resource_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_resource_requirements');
        Schema::dropIfExists('service_resource');
        Schema::dropIfExists('resources');
        Schema::dropIfExists('service_categories');
        Schema::dropIfExists('services');
    }
};
