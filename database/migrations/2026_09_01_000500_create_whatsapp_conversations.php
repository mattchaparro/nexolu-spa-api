<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * De quien es cada conversacion de WhatsApp.
 *
 * Hay DOS formas de que un mensaje entrante encuentre a su negocio, y el
 * producto necesita las dos:
 *
 *  - NUMERO PROPIO: el negocio tiene su WhatsApp conectado. El
 *    `phone_number_id` que recibio el mensaje ya dice de quien es. Es el caso
 *    de Luxury Nails, que sale a produccion con el numero que ya tiene.
 *
 *  - NUMERO COMPARTIDO: un solo numero de Nexolu para los negocios que
 *    compran la app. Ahi el mensaje no trae de quien es, y por eso existe
 *    `whatsapp_code`: el negocio publica un wa.link con su codigo adentro y
 *    el primer mensaje llega con el.
 *
 * La conversacion se guarda porque el codigo viene UNA vez. Del segundo
 * mensaje en adelante, "de quien es" solo lo sabe esta tabla.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            // Modo numero propio. Unico: dos negocios no pueden reclamar el
            // mismo numero, y si pasara los mensajes irian al azar.
            $table->string('whatsapp_phone_number_id', 32)->nullable()->unique()->after('messaging_mode');

            /*
             * Modo numero compartido. Corto y opaco a proposito: va escrito
             * en el texto del enlace que la clienta ve y puede editar, asi
             * que tiene que ser facil de leer y no debe revelar nada. NO se
             * usa el slug: es adivinable, y adivinar el codigo de otro
             * negocio pondria la conversacion en el local equivocado.
             */
            $table->string('whatsapp_code', 12)->nullable()->unique()->after('whatsapp_phone_number_id');
        });

        Schema::create('whatsapp_conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();

            // El telefono de quien escribe, ya normalizado (digitos, sin '+').
            $table->string('phone', 32);

            // La conversacion del lado del IA Core, que es quien guarda el
            // historial. Aca solo se recuerda cual es la suya.
            $table->string('ia_conversation_id', 64)->nullable();

            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('last_message_at')->nullable();

            /*
             * Una persona puede ser clienta de dos negocios y escribirle al
             * mismo numero compartido. Por eso la conversacion es por
             * (negocio, telefono) y no por telefono a secas: mezclarlas seria
             * contarle a un spa las citas del otro.
             */
            $table->unique(['business_id', 'phone']);
            $table->index(['phone', 'last_message_at']);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_conversations');

        Schema::table('businesses', function (Blueprint $table) {
            $table->dropColumn(['whatsapp_phone_number_id', 'whatsapp_code']);
        });
    }
};
