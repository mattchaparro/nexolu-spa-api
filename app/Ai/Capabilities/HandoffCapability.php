<?php

namespace App\Ai\Capabilities;

use App\Ai\AiCaller;
use App\Ai\Capability;
use App\Models\Message;
use App\Models\WhatsappConversation;
use App\Support\ChannelPhone;

/**
 * "Quiero hablar con una persona".
 *
 * Antes eso solo funcionaba si la clienta tocaba el botón de un flujo de
 * Connect; si se lo escribía al agente, no pasaba nada -- el bot seguía
 * intentando agendarle una cita a alguien que venía a reclamar.
 *
 * Hace dos cosas y ninguna es contestar: calla al agente en esa conversación
 * (el relevo caduca solo, ver `agent_paused_until`) y deja una nota en el
 * hilo para que quien abra la bandeja sepa qué necesita y desde cuándo. El
 * mensaje a la clienta lo escribe el modelo, que para eso está.
 */
class HandoffCapability implements Capability
{
    public function requiredPermission(): ?string
    {
        return null;
    }

    public function requiredFeature(): ?string
    {
        // Pedir ayuda humana no es una función que se pueda apagar: si el
        // negocio tiene WhatsApp, tiene gente detrás.
        return null;
    }

    public function allowsCustomers(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'motivo' => ['required', 'string', 'max:500'],
        ];
    }

    public function execute(AiCaller $caller, array $arguments): array
    {
        $phone = ChannelPhone::normalize((string) $caller->phone, $caller->business->country_code ?? 'CO');

        if ($phone === null) {
            return [
                'avisado' => false,
                'instruccion' => 'Dile que escriba al negocio directamente; no pude avisarle al equipo.',
            ];
        }

        $conversacion = WhatsappConversation::withoutGlobalScope('business')
            ->where('business_id', $caller->business->id)
            ->where('phone', $phone)
            ->first();

        if ($conversacion === null) {
            return [
                'avisado' => false,
                'instruccion' => 'Dile que escriba al negocio directamente; no pude avisarle al equipo.',
            ];
        }

        $conversacion->pauseAgent();

        Message::create([
            'business_id' => $conversacion->business_id,
            'conversation_id' => $conversacion->id,
            'client_id' => $conversacion->client_id,
            'kind' => Message::KIND_STAFF,
            'direction' => Message::DIRECTION_OUT,
            'to' => $conversacion->phone,
            'body' => '⚑ Pidió hablar con una persona: '.$arguments['motivo']
                .' (el agente queda en pausa, responde tú)',
            // Nota interna: nace enviada para que el outbox no la despache.
            'status' => Message::STATUS_SENT,
            'sent_at' => now(),
        ]);

        $conversacion->update([
            'last_message_at' => now(),
            // Que salte como pendiente en la bandeja: alguien tiene que verla.
            'read_at' => null,
            'status' => WhatsappConversation::STATUS_OPEN,
        ]);

        return [
            'avisado' => true,
            'instruccion' => 'Ya avisaste al equipo. Dile en UNA línea que alguien del local le '
                .'escribe enseguida, y no sigas intentando resolverlo tú.',
        ];
    }
}
