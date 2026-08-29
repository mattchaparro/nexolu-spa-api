<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Regla recurrente, NO una fila por dia. Blue Souls materializaba un
        // TimeSlot por bloque por empleada por dia via cron: millones de filas
        // muertas y una granularidad congelada en 120 minutos.
        Schema::create('resource_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('resource_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('weekday'); // 1 = lunes ... 7 = domingo (ISO-8601)
            $table->time('start_time');
            $table->time('end_time');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->timestamps();

            $table->index(['resource_id', 'weekday']);
        });

        // Todo lo que resta disponibilidad sin ser una cita: vacaciones,
        // festivos, capacitaciones, un bloqueo de media hora para almorzar.
        Schema::create('schedule_exceptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            // Nulo = aplica a todo el negocio (un festivo, por ejemplo).
            $table->foreignId('resource_id')->nullable()->constrained()->cascadeOnDelete();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->string('kind')->default('block'); // block | vacation | holiday | extra_hours
            $table->string('reason')->nullable();
            $table->timestamps();

            $table->index(['business_id', 'starts_at', 'ends_at']);
            $table->index(['resource_id', 'starts_at']);
        });

        Schema::create('appointments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();

            // Se guarda ademas del client_id porque una reserva de WhatsApp
            // puede llegar antes de que el cliente exista en la base.
            $table->string('client_name')->nullable();
            $table->string('client_phone')->nullable();

            // Rango de la cita completa. Los items pueden subdividirlo.
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');

            $table->string('status')->default('pending');
            // pending | confirmed | in_progress | completed | cancelled | no_show

            $table->string('source')->default('admin');
            // admin | online | whatsapp_agent | phone

            $table->text('notes')->nullable();
            $table->dateTime('confirmed_at')->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->foreignId('cancelled_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cancellation_reason')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['business_id', 'starts_at']);
            $table->index(['business_id', 'status', 'starts_at']);
            $table->index(['client_id', 'starts_at']);
        });

        // Una cita puede ser varios servicios encadenados, cada uno con su
        // recurso y su ventana propia: manicure con Maria, luego pedicure con
        // Ana.
        Schema::create('appointment_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('appointment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->constrained();
            $table->foreignId('resource_id')->constrained();

            // Incluye buffers: es la ventana que el recurso queda ocupado.
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            // Sin buffers: es lo que el cliente ve y lo que se cobra.
            $table->dateTime('service_starts_at');
            $table->dateTime('service_ends_at');

            $table->decimal('price', 12, 2)->default(0);
            $table->decimal('commission_rate', 5, 4)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['resource_id', 'starts_at', 'ends_at']);
            $table->index(['appointment_id', 'sort_order']);
        });

        // La red de seguridad contra doble reserva.
        //
        // MySQL no tiene constraints de exclusion sobre rangos, asi que un
        // chequeo de solape en PHP siempre deja una ventana de carrera. Aca se
        // escribe una fila por unidad de granularidad dentro de la MISMA
        // transaccion que la cita: el indice unico hace que la segunda reserva
        // falle en la base de datos, no en la logica de aplicacion.
        Schema::create('resource_occupancy', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('resource_id')->constrained()->cascadeOnDelete();
            $table->foreignId('appointment_item_id')->constrained()->cascadeOnDelete();
            $table->dateTime('slot_start');

            $table->unique(['resource_id', 'slot_start'], 'resource_occupancy_unique_slot');
            $table->index(['business_id', 'slot_start']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resource_occupancy');
        Schema::dropIfExists('appointment_items');
        Schema::dropIfExists('appointments');
        Schema::dropIfExists('schedule_exceptions');
        Schema::dropIfExists('resource_schedules');
    }
};
