<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La encuesta que se manda cuando termina el servicio.
 *
 * El diseno sale de un desastre concreto del sistema viejo: Blue Souls tiene
 * un comando `ratings:reinsert` que recupera calificaciones **parseandolas de
 * los logs de Laravel** (`QOS Data Received {...}`). Es decir: las respuestas
 * llegaban, se escribian en el log, y NO se guardaban. La unica copia de lo
 * que opinaron los clientes durante meses fue un archivo de texto rotativo.
 *
 * De ahi las dos decisiones de esta tabla:
 *
 * 1. `raw_payload` guarda lo que llego TAL CUAL, ademas de los campos ya
 *    interpretados. Si manana cambia el formulario o llega algo que no se
 *    entiende, la respuesta igual queda; se puede reinterpretar despues. Lo
 *    que no se puede es volver a pedirle la opinion a alguien que ya la dio.
 *
 * 2. Todo lo interpretado es NULLABLE. Una encuesta a la que le falta la
 *    puntualidad se guarda igual: rechazar la respuesta entera por un campo
 *    que el cliente no lleno es exactamente como se pierden.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            /*
             * El token que viaja en el enlace de la encuesta. Aleatorio y no
             * el id: un enlace con el id de la cita deja calificar las citas
             * de otros probando numeros.
             */
            $table->string('survey_token', 64)->nullable()->unique()->after('deposit_reference');
            $table->timestamp('survey_sent_at')->nullable()->after('survey_token');
            $table->timestamp('survey_answered_at')->nullable()->after('survey_sent_at');
        });

        Schema::create('service_ratings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('appointment_id')->constrained()->cascadeOnDelete();

            /*
             * A quien se califica. Nullable porque la respuesta se guarda
             * aunque no se pueda atribuir: perder la opinion es peor que
             * tenerla sin dueno, y una fila huerfana se puede revisar a mano.
             */
            $table->foreignId('appointment_item_id')->nullable()
                ->constrained('appointment_items')->nullOnDelete();
            $table->foreignId('resource_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();

            // 1 a 5. Separados porque responden preguntas distintas: el
            // trabajo pudo quedar bien y la atencion mal.
            $table->unsignedTinyInteger('service_rating')->nullable();
            $table->unsignedTinyInteger('staff_rating')->nullable();
            $table->unsignedTinyInteger('punctuality_rating')->nullable();
            $table->text('comment')->nullable();

            // Lo que llego tal cual, por si algo no se supo interpretar.
            $table->json('raw_payload')->nullable();

            $table->timestamps();

            // Una persona se califica UNA vez por cita.
            $table->unique(['appointment_id', 'appointment_item_id'], 'ratings_una_por_persona');
            $table->index(['business_id', 'resource_id', 'created_at'], 'ratings_por_persona_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_ratings');

        Schema::table('appointments', function (Blueprint $table) {
            $table->dropUnique(['survey_token']);
            $table->dropColumn(['survey_token', 'survey_sent_at', 'survey_answered_at']);
        });
    }
};
