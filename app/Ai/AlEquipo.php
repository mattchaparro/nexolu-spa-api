<?php

namespace App\Ai;

use App\Models\Message;
use App\Models\WhatsappConversation;

/**
 * Pasarle la conversación a una persona del equipo.
 *
 * El bot se pausa, queda una nota interna en el hilo diciendo qué hay que
 * resolver, y la conversación salta como pendiente en la bandeja. Lo usan
 * «Hablar con el admin», la garantía de otro servicio y la hora que no cabe
 * en el turno («¿se puede a las 4?»), que antes el bot contestaba con un
 * «no» rotundo sin preguntarle a nadie.
 */
final class AlEquipo
{
    public static function pasar(WhatsappConversation $conversacion, string $nota): void
    {
        $conversacion->pauseAgent();

        Message::create([
            'business_id' => $conversacion->business_id,
            'conversation_id' => $conversacion->id,
            'client_id' => $conversacion->client_id,
            'kind' => Message::KIND_STAFF,
            'direction' => Message::DIRECTION_OUT,
            'to' => $conversacion->phone,
            'body' => '⚑ '.$nota.' (el bot queda en pausa: responde tú)',
            // Nota interna: nace enviada para que el outbox no la despache.
            'status' => Message::STATUS_SENT,
            'sent_at' => now(),
        ]);

        $conversacion->update([
            'last_message_at' => now(),
            'read_at' => null,
            'status' => WhatsappConversation::STATUS_OPEN,
        ]);
    }
}
