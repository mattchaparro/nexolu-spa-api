<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Difusiones: un mensaje a muchas, programado.
 *
 * No se confunde con `campaigns`, que son DESCUENTOS que se aplican solos al
 * cobrar. Esto es lo que hoy se hace a mano desde ManyChat: escoger una
 * plantilla, escoger a quien, y mandarla -- ahora o el jueves a las 10.
 *
 * El envio en si NO vive aca: cada destinataria se convierte en una fila de
 * `messages`, que ya sabe mandar, reintentar, registrar el costo por negocio
 * y quedarse en la bandeja si el negocio opera a mano. Una difusion es un
 * generador de mensajes, no un segundo canal de salida.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('broadcasts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();

            // Para reconocerla en la lista: "Promo día de la madre".
            $table->string('name', 120);

            /*
             * La PLANTILLA es obligatoria y no es un capricho: una difusion
             * la inicia el negocio hacia gente que no le ha escrito hoy, o
             * sea fuera de la ventana de 24h. Como texto libre, WhatsApp la
             * rechaza entera.
             */
            $table->string('template_name', 128);
            $table->string('template_language', 12)->default('es');

            /**
             * Las variables de la plantilla, en orden, con marcadores:
             * ["{nombre}", "{negocio}", "20%"]. Se resuelven por destinataria.
             *
             * @var list<string>
             */
            $table->json('template_params')->nullable();

            // El mismo texto ya armado, para el modo manual y para que quien
            // programa vea que va a mandar antes de mandarlo.
            $table->text('body_template');

            /**
             * A quien. Se guarda el CRITERIO, no la lista: una difusion
             * programada para el jueves debe alcanzar a quien se volvio
             * clienta el miercoles.
             *
             * @var array{location_id?: int, visited_since?: string, not_visited_since?: string}
             */
            $table->json('audience')->nullable();

            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('sent_at')->nullable();

            // borrador | programada | enviando | enviada | cancelada
            $table->string('status', 16)->default('borrador');

            // Cuantas salieron de verdad. Se llena al despachar.
            $table->unsignedInteger('recipients')->default(0);

            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['business_id', 'status', 'scheduled_at']);
        });

        Schema::table('messages', function (Blueprint $table) {
            $table->foreignId('broadcast_id')->nullable()->after('appointment_id')
                ->constrained()->nullOnDelete();

            /*
             * Una sola vez por persona y por difusion.
             *
             * Es la misma leccion de los recordatorios: la garantia de no
             * mandar dos veces es una RESTRICCION, no un contador. Si el
             * comando corre dos veces -- o alguien toca "enviar" de nuevo --
             * la segunda choca contra el indice y no sale nada.
             */
            $table->unique(['broadcast_id', 'client_id'], 'messages_broadcast_client_unique');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            /*
             * El ORDEN importa y no es evidente: MySQL se apoya en este
             * indice unico para sostener la clave foranea, y se niega a
             * borrarlo mientras la clave exista ("Cannot drop index: needed
             * in a foreign key constraint"). Primero la clave, despues el
             * indice, y al final la columna.
             */
            $table->dropForeign(['broadcast_id']);
            $table->dropUnique('messages_broadcast_client_unique');
            $table->dropColumn('broadcast_id');
        });

        Schema::dropIfExists('broadcasts');
    }
};
