<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La bandeja de salida: TODO mensaje que el sistema quiso mandar.
 *
 * POR QUE UNA TABLA Y NO EL LOG.
 *
 * `LoggingMessagingChannel` escribia a un modelo `WhatsappLog` que en este repo
 * NUNCA EXISTIO: el primer envio real habria muerto con un class not found. No
 * se noto porque `isConfigured()` siempre devuelve false sin credenciales, asi
 * que la rama nunca se ejecuto. Es la clase de bug que espera al dia del
 * lanzamiento.
 *
 * Y aunque hubiera existido, un log no sirve. Blue Souls tenia un comando
 * `ratings:reinsert` que recuperaba calificaciones PARSEANDO LOS LOGS DE
 * LARAVEL, porque los datos solo vivian ahi. Un mensaje que unicamente existe
 * en un archivo de texto no se puede reintentar, ni contar, ni mostrarle a
 * quien administra.
 *
 * Aca cada mensaje es una fila con estado. Eso es lo que permite las tres cosas
 * que de verdad hacen falta:
 *
 * 1. OPERAR HOY, SIN WHATSAPP. En modo manual nada se envia solo: los mensajes
 *    quedan en `pendiente_manual` y quien atiende los copia. El producto
 *    funciona completo mientras Meta aprueba un numero.
 * 2. NO MANDAR DOS VECES. El indice unico, no un contador.
 * 3. SABER QUE PASO. Un mensaje fallido se ve, se explica y se reintenta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->nullable()->constrained()->nullOnDelete();

            /*
             * De que se trata: `recordatorio`, `confirmacion`, `encuesta`,
             * `etapa`. No es decoracion -- es lo que permite no mandar dos
             * recordatorios de la misma cita, y contar cuantos mensajes de
             * cada tipo consume un negocio.
             */
            $table->string('kind', 32);

            // A quien, y sobre que. El telefono se congela: si manana cambia
            // en la ficha, este mensaje se mando al que estaba entonces.
            $table->string('to', 32);
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('appointment_id')->nullable()->constrained()->nullOnDelete();

            // El texto YA RENDERIZADO. Guardar la plantilla y los datos
            // obligaria a re-renderizar para saber que se mando, y el
            // resultado cambiaria si la plantilla cambio despues.
            $table->text('body');

            /*
             * `pendiente_manual` es un estado de primera clase, no un limbo:
             * significa "nadie va a mandar esto solo, lo copia una persona".
             * Con el, el negocio opera hoy y la pantalla sabe que mostrar.
             */
            $table->string('status', 24)->default('pendiente');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->dateTime('sent_at')->nullable();
            $table->dateTime('failed_at')->nullable();
            $table->string('error', 500)->nullable();

            // Cuando se marco como enviado a mano, y quien. Distinto de
            // `sent_at` automatico: dice que hubo una persona detras.
            $table->foreignId('sent_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['business_id', 'status', 'created_at']);

            /*
             * UN mensaje de cada tipo por cita.
             *
             * El indice, no un contador ni una bandera en `appointments`. Es la
             * leccion de `gamification:recalculate` en Blue Souls: los
             * contadores se desincronizan y hay que escribir un comando para
             * repararlos. Una restriccion no se desincroniza.
             *
             * `appointment_id` nulo no colisiona en MySQL, asi que un mensaje
             * suelto -- una promocion, algo escrito a mano -- puede repetirse
             * sin pelearse con esta regla.
             */
            $table->unique(['appointment_id', 'kind'], 'mensajes_uno_por_cita_y_tipo');
        });

        Schema::table('businesses', function (Blueprint $table) {
            /*
             * Como manda sus mensajes este negocio.
             *
             * `manual` por defecto, y no es un placeholder: es como va a operar
             * un spa sus primeras semanas de todas formas, y hay quien no va a
             * querer salir de ahi nunca. Encender el envio automatico sin que
             * nadie lo pida seria mandarle mensajes a sus clientas a su nombre
             * sin avisarle.
             */
            $table->string('messaging_mode', 16)->default('manual')->after('subscription_plan');
        });
    }

    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->dropColumn('messaging_mode');
        });

        Schema::dropIfExists('messages');
    }
};
