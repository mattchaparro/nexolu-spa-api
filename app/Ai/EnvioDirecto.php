<?php

namespace App\Ai;

use App\Models\Message;
use App\Models\WhatsappConversation;
use App\Services\WhatsApp\NexoluCommsChannel;
use App\Support\ChannelPhone;

/**
 * Lo que una herramienta le dice a la clienta sin pasar por el modelo.
 *
 * Tres cosas, siempre juntas, porque hacerlas por separado ya cobró dos
 * víctimas:
 *
 * 1. ENVIAR por el canal.
 * 2. DEJAR RASTRO en el hilo del Spa. La lista de horas se enviaba sin
 *    rastro, y el turno siguiente creía que el mensaje anterior seguía
 *    sin responder: el manejador de toques recibió "Semi\n9 am" en vez de
 *    "9 am", no reconoció el botón, y la conversación de Alejandro murió.
 * 3. CALLAR AL MODELO (`OpcionesEnviadas`): si la herramienta ya habló,
 *    el texto del modelo sobra.
 *
 * Y la razón de fondo: hay mensajes demasiado importantes para dejárselos
 * al modelo. "Tu cita quedó agendada" no puede depender de que el modelo
 * decida redactarlo — esa noche agendó la cita y respondió con texto
 * vacío, y Alejandro quedó con una cita que no sabía que tenía.
 */
final class EnvioDirecto
{
    public function __construct(private readonly NexoluCommsChannel $channel) {}

    /** Un texto de la herramienta, directo a la clienta. */
    public function texto(AiCaller $caller, string $texto): bool
    {
        $phone = $this->phone($caller);

        if ($phone === null) {
            return false;
        }

        if (! $this->channel->sendText($phone, $texto, $caller->business->id)) {
            return false;
        }

        $this->registrar($caller, $phone, $texto);

        return true;
    }

    /**
     * Opciones tocables (botones o lista), directas a la clienta.
     *
     * @param  list<array{id: string, title: string, description?: string}>  $opciones
     */
    public function opciones(AiCaller $caller, string $texto, array $opciones, string $boton = 'Ver opciones'): bool
    {
        $phone = $this->phone($caller);

        if ($phone === null) {
            return false;
        }

        if (! $this->channel->sendOptions($phone, $texto, $opciones, $caller->business->id, $boton)) {
            return false;
        }

        $this->registrar(
            $caller,
            $phone,
            $texto."\n\n".collect($opciones)->map(fn ($o) => '▸ '.$o['title'])->implode("\n"),
        );

        return true;
    }

    private function phone(AiCaller $caller): ?string
    {
        if ($caller->isStaff()) {
            return null;
        }

        return ChannelPhone::normalize((string) $caller->phone, $caller->business->country_code ?? 'CO');
    }

    private function registrar(AiCaller $caller, string $phone, string $body): void
    {
        OpcionesEnviadas::marcar($phone);

        $conversacion = WhatsappConversation::withoutGlobalScope('business')
            ->where('business_id', $caller->business->id)
            ->where('phone', $phone)
            ->first();

        if ($conversacion === null) {
            return;
        }

        Message::create([
            'business_id' => $conversacion->business_id,
            'conversation_id' => $conversacion->id,
            'client_id' => $conversacion->client_id,
            'kind' => Message::KIND_AGENT,
            'direction' => Message::DIRECTION_OUT,
            'to' => $phone,
            'body' => $body,
            // Ya salió por Connect: si naciera pendiente, el outbox lo
            // repetiría.
            'status' => Message::STATUS_SENT,
            'sent_at' => now(),
        ]);

        $conversacion->update(['last_message_at' => now()]);
    }
}
