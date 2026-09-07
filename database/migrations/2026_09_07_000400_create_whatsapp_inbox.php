<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La bandeja de WhatsApp: poder leer la conversacion y contestar a mano.
 *
 * Hasta ahora `messages` guardaba solo lo que SALIA. Lo que entraba se leia
 * del webhook, se le pasaba al agente y se perdia. Eso alcanza mientras el
 * agente conteste todo, y deja de alcanzar el dia que hay que migrar el
 * numero de un local que hoy contesta a mano en ManyChat: sin lo que entro no
 * hay conversacion que leer, solo respuestas sueltas.
 *
 * TRES COSAS QUE ESTE ESQUEMA TIENE QUE RESOLVER:
 *
 * 1. EL RELEVO. Cuando alguien del equipo entra a contestar, el agente tiene
 *    que callarse en ESA conversacion. Si no, la clienta recibe dos
 *    respuestas a la misma pregunta y se contradicen delante de ella.
 *    `agent_paused_until` es una fecha y no un booleano a proposito: un
 *    interruptor que alguien olvida apagar deja al agente mudo para siempre,
 *    y de eso nadie se entera -- simplemente dejan de contestarse solos.
 *
 * 2. LA VENTANA DE 24 HORAS. Meta solo deja mandar texto libre dentro de las
 *    24 horas siguientes al ultimo mensaje de la persona; fuera de eso, solo
 *    plantillas aprobadas. No es una regla nuestra ni de ManyChat: es de
 *    Meta, y ManyChat la tiene igual. Por eso `last_inbound_at` va aparte de
 *    `last_message_at`: el que manda para la ventana es el ULTIMO MENSAJE DE
 *    ELLA, y `last_message_at` se mueve tambien cuando contestamos nosotros.
 *
 * 3. QUE FALTA POR ATENDER. `read_at` nulo = hay algo sin leer. Una marca de
 *    tiempo y no un contador: un contador hay que mantenerlo sincronizado, y
 *    se desincroniza.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            /*
             * `out` por defecto porque todo lo que ya existe salio de aca.
             * Los recordatorios, las encuestas, las difusiones y lo que
             * contesta el agente son todos salientes.
             */
            $table->string('direction', 3)->default('out')->after('kind');

            $table->foreignId('conversation_id')->nullable()->after('client_id')
                ->constrained('whatsapp_conversations')->nullOnDelete();
        });

        Schema::table('messages', function (Blueprint $table) {
            // Para pintar un hilo: los mensajes de una conversacion, en orden.
            $table->index(['conversation_id', 'created_at'], 'messages_hilo');
        });

        Schema::table('whatsapp_conversations', function (Blueprint $table) {
            /*
             * Hasta cuando el agente NO debe contestar esta conversacion.
             * Nulo o pasado = el agente atiende normal.
             */
            $table->timestamp('agent_paused_until')->nullable()->after('client_id');

            /*
             * Quien del equipo la tomo. Informativo, no un candado: dos
             * personas contestando a la vez es un problema de coordinacion
             * del local, y bloquear la conversacion crearia uno peor -- la
             * que la tomo se fue a almorzar y nadie mas puede responder.
             */
            $table->foreignId('assigned_user_id')->nullable()->after('agent_paused_until')
                ->constrained('users')->nullOnDelete();

            // El ultimo mensaje DE ELLA. Es el que abre la ventana de 24h.
            $table->timestamp('last_inbound_at')->nullable()->after('last_message_at');

            // Nulo = hay algo sin leer.
            $table->timestamp('read_at')->nullable()->after('last_inbound_at');

            /*
             * `open` o `closed`. Cerrar no borra ni silencia: solo la saca de
             * la lista de pendientes. Si la persona vuelve a escribir, se
             * reabre sola -- una conversacion cerrada que recibe un mensaje y
             * sigue escondida es un cliente al que nadie contesta.
             */
            $table->string('status', 16)->default('open')->after('read_at');

            $table->index(['business_id', 'status', 'last_message_at'], 'conversaciones_bandeja');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_conversations', function (Blueprint $table) {
            $table->dropForeign(['assigned_user_id']);
        });

        Schema::table('whatsapp_conversations', function (Blueprint $table) {
            $table->dropIndex('conversaciones_bandeja');
            $table->dropColumn(['agent_paused_until', 'assigned_user_id', 'last_inbound_at', 'read_at', 'status']);
        });

        /*
         * PRIMERO la clave foranea, DESPUES el indice que la sostiene, y solo
         * entonces la columna. Al reves MySQL responde "Can't DROP
         * 'messages_hilo'; check that column/key exists", que suena a que el
         * indice no existe cuando lo que pasa es que no lo suelta: la clave
         * foranea de `conversation_id` se apoya en el.
         */
        Schema::table('messages', function (Blueprint $table) {
            $table->dropForeign(['conversation_id']);
        });

        Schema::table('messages', function (Blueprint $table) {
            $table->dropIndex('messages_hilo');
            $table->dropColumn(['direction', 'conversation_id']);
        });
    }
};
