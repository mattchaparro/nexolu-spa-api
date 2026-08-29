<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Fotos del trabajo hecho. Es lo que la profesional mira antes de
        // atender: que se le hizo la vez pasada, con que color, que forma.
        // Sin esto la ficha del cliente es una lista de fechas.
        Schema::create('client_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();

            // Nula si la foto se sube desde la ficha y no desde una cita
            // concreta. Si la cita se borra, la foto sobrevive: el trabajo se
            // hizo igual y sigue siendo referencia.
            $table->foreignId('appointment_item_id')->nullable()->constrained()->nullOnDelete();

            $table->string('image_path');
            $table->string('caption')->nullable();
            $table->dateTime('taken_at');
            $table->foreignId('uploaded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['client_id', 'taken_at']);
        });

        Schema::table('clients', function (Blueprint $table) {
            // Lo que hay que saber ANTES de atender: alergias, que no le
            // gusta, como prefiere las cosas. Distinto de `notes`, que es el
            // cajon general.
            $table->text('care_notes')->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('clients', fn (Blueprint $t) => $t->dropColumn('care_notes'));
        Schema::dropIfExists('client_photos');
    }
};
