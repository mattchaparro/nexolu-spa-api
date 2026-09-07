<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Historias de Instagram, ahora o programadas.
 *
 * La API de Meta NO tiene programacion: publicar es una llamada que ocurre
 * en el momento. Por eso la fila existe -- guarda la intencion hasta que
 * llega la hora, igual que una difusion.
 *
 * Y por eso guarda tambien el RESULTADO: publicar sale de este servidor
 * hacia Communications y de ahi a Meta, con tres formas de fallar (la
 * imagen no es JPEG, el token caduco, Meta esta caido). Sin el motivo
 * escrito, quien administra ve "no se publico" y no puede hacer nada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('instagram_stories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();

            /*
             * La imagen vive en el disco del sistema, no en la fila.
             *
             * Meta la DESCARGA por URL publica en el momento de publicar --
             * no se le puede mandar el binario. Eso obliga a que el archivo
             * siga ahi y siga siendo publico cuando llegue la hora: una
             * historia programada para el sabado con una imagen borrada el
             * viernes falla, y el motivo queda en `error`.
             */
            $table->string('image_path');

            /**
             * Cuentas a mencionar. Es lo UNICO que la API deja poner encima
             * de una historia: no hay stickers, enlaces, encuestas,
             * ubicacion, musica ni texto.
             *
             * @var list<string>
             */
            $table->json('mentions')->nullable();

            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('published_at')->nullable();

            // borrador | programada | publicada | fallida | cancelada
            $table->string('status', 16)->default('borrador');

            // Lo que devolvio Meta, para poder mirarla despues.
            $table->string('media_id', 64)->nullable();
            $table->string('error', 500)->nullable();

            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['business_id', 'status', 'scheduled_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('instagram_stories');
    }
};
