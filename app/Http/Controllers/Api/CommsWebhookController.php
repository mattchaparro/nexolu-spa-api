<?php

namespace App\Http\Controllers\Api;

use App\Jobs\AnswerWhatsappMessageJob;
use App\Models\Message;
use App\Models\WhatsappConversation;
use App\Services\WhatsApp\ConversationRouter;
use App\Support\ChannelPhone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Lo que llega por WhatsApp.
 *
 * Nexolu Communications recibe el webhook de Meta, verifica que sea de Meta,
 * y reenvia el cuerpo CRUDO firmado hasta aca. Nunca se apunta un webhook de
 * Meta directo a esta ruta: el que valida contra Meta es Communications.
 *
 * El trabajo de este controlador es corto a proposito: verificar la firma,
 * averiguar de que negocio es la conversacion, preguntarle al agente y
 * contestar. Toda la inteligencia esta en el IA Core y todas las reglas de
 * negocio en /api/ai/tools/invoke.
 */
class CommsWebhookController
{
    public function __construct(private readonly ConversationRouter $router) {}

    public function whatsapp(Request $request): JsonResponse
    {
        if (! $this->hasValidSignature($request)) {
            Log::warning('comms.webhook: firma invalida', ['ip' => $request->ip()]);

            return response()->json(['error' => 'invalid_signature'], 401);
        }

        $entrante = $this->firstIncomingMessage($request->json()->all());

        /*
         * Siempre 200, incluso cuando no hay nada que hacer.
         *
         * Un webhook que responde error hace que el otro lado reintente el
         * MISMO evento, y aca reintentar significa volver a contestarle a la
         * clienta. Un recibo de lectura, una foto o un evento de estado no
         * son errores: son cosas que este agente todavia no atiende.
         */
        if ($entrante === null) {
            return response()->json(['ok' => true, 'handled' => false]);
        }

        [$phoneNumberId, $from, $texto] = $entrante;

        // El telefono llega de Meta, no del texto: es lo unico de este cuerpo
        // que no escribio la persona.
        $normalizado = ChannelPhone::normalize($from);

        if ($normalizado === null) {
            return response()->json(['ok' => true, 'handled' => false]);
        }

        $conversacion = $this->router->resolve($phoneNumberId, $normalizado, $texto);

        if ($conversacion === null) {
            /*
             * Llego al numero compartido sin codigo y sin conversacion previa:
             * no se sabe de que negocio habla. Contestar "hola, ¿en que te
             * ayudo?" seria peor que callar -- abriria una charla que no
             * puede llegar a nada. Queda registrado para poder verlo.
             */
            Log::info('comms.webhook: mensaje sin negocio que lo reclame', ['from' => $normalizado]);

            return response()->json(['ok' => true, 'handled' => false]);
        }

        /*
         * LO QUE ENTRA SE GUARDA SIEMPRE, conteste el agente o no.
         *
         * Es lo que convierte una respuesta suelta en una conversacion que
         * alguien puede leer. Sin esto, quien atiende el mostrador ve lo que
         * el sistema contesto y no lo que le preguntaron -- y contestar a
         * mano es imposible.
         *
         * Va antes de la cola a proposito: si el agente falla, el mensaje de
         * la clienta ya esta escrito y alguien puede responderlo.
         */
        $this->guardarEntrante($conversacion, $texto);

        /*
         * Si alguien del equipo esta atendiendo, el agente se calla.
         *
         * Dos respuestas a la misma pregunta -- una de la empleada y otra del
         * agente, y contradiciendose -- es peor que no contestar. El relevo
         * caduca solo (ver `agent_paused_until`), asi que una conversacion
         * olvidada vuelve al agente en vez de quedarse muda.
         */
        if ($conversacion->agentIsPaused()) {
            return response()->json(['ok' => true, 'handled' => true, 'agent' => 'paused']);
        }

        /*
         * Pensar la respuesta se va a la COLA, y contestamos ya.
         *
         * Preguntarle al Core es una llamada HTTP que el Core devuelve con
         * OTRA llamada a esta misma API -- la herramienta de disponibilidad,
         * la de agendar. Esperarla aca dentro traba al proceso web que tiene
         * que servir justamente esa vuelta.
         *
         * Y ademas: Communications espera un 200 rapido. Si tarda, reintenta
         * el MISMO evento, y reintentar aca es volver a escribirle a la
         * clienta.
         */
        AnswerWhatsappMessageJob::dispatch($conversacion->id, $texto);

        return response()->json(['ok' => true, 'handled' => true]);
    }

    /**
     * Deja escrito lo que dijo la clienta, y reabre la conversacion.
     *
     * `last_inbound_at` se separa de `last_message_at` porque la ventana de
     * 24 horas de Meta la abre EL MENSAJE DE ELLA, no el nuestro: si
     * contestamos a las 23 horas, la ventana sigue venciendo a las 24 desde
     * que ella escribio, no desde que respondimos.
     *
     * Y `read_at` vuelve a nulo: llego algo nuevo que nadie ha visto.
     */
    private function guardarEntrante(WhatsappConversation $conversacion, string $texto): void
    {
        Message::create([
            'business_id' => $conversacion->business_id,
            'conversation_id' => $conversacion->id,
            'client_id' => $conversacion->client_id,
            'kind' => Message::KIND_INBOUND,
            'direction' => Message::DIRECTION_IN,
            'to' => $conversacion->phone,
            'body' => $texto,
            /*
             * Un mensaje entrante nace ENVIADO: ya llego. El outbox trata
             * `pendiente` como "hay que mandarlo", y dejarlo asi lo pondria
             * en la cola de salida, de vuelta hacia quien lo escribio.
             */
            'status' => Message::STATUS_SENT,
            'sent_at' => now(),
        ]);

        $conversacion->update([
            'last_message_at' => now(),
            'last_inbound_at' => now(),
            'read_at' => null,
            // Una conversacion cerrada que recibe un mensaje y sigue
            // escondida es una clienta a la que nadie contesta.
            'status' => WhatsappConversation::STATUS_OPEN,
        ]);
    }

    /**
     * Lo primero que dijo una persona en este sobre, si dijo algo.
     *
     * Meta manda lotes y mete de todo junto: estados de entrega, recibos de
     * lectura, adjuntos. Aca solo interesa lo que alguien EXPRESO, y eso
     * tiene tres formas: lo escribio, toco un boton de una plantilla, o toco
     * un boton u opcion de una lista.
     *
     * Los botones importan tanto como el texto: la plantilla de confirmacion
     * ofrece "Cambiar la hora" y "Cancelar la cita", y si el agente solo
     * escuchara texto, tocarlos no haria absolutamente nada. Se traducen a
     * texto porque para el modelo "Cancelar la cita" tocado y escrito
     * significan lo mismo.
     *
     * @param  array<string, mixed>  $payload
     * @return array{0: ?string, 1: string, 2: string}|null [phone_number_id, de, texto]
     */
    private function firstIncomingMessage(array $payload): ?array
    {
        foreach ($payload['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                $value = $change['value'] ?? [];
                $phoneNumberId = $value['metadata']['phone_number_id'] ?? null;

                foreach ($value['messages'] ?? [] as $message) {
                    $texto = $this->textOf($message);
                    $from = (string) ($message['from'] ?? '');

                    if ($texto === null || $from === '') {
                        continue;
                    }

                    // Un tope: el IA Core corta en 1000 caracteres, y un
                    // mensaje kilometrico solo puede ser ruido o un intento
                    // de llenarle el contexto al modelo.
                    return [$phoneNumberId, $from, mb_substr($texto, 0, 1000)];
                }
            }
        }

        return null;
    }

    /**
     * Lo que dijo la persona, venga escrito o tocado.
     *
     * @param  array<string, mixed>  $message
     */
    private function textOf(array $message): ?string
    {
        $crudo = match ($message['type'] ?? null) {
            'text' => $message['text']['body'] ?? null,
            // El payload de un boton lo definimos nosotros al crear la
            // plantilla; el TEXTO es lo que la persona vio y toco, que es lo
            // que hay que contarle al modelo.
            'button' => $message['button']['text'] ?? $message['button']['payload'] ?? null,
            'interactive' => $message['interactive']['button_reply']['title']
                ?? $message['interactive']['list_reply']['title']
                ?? null,
            default => null,
        };

        $texto = trim((string) $crudo);

        return $texto === '' ? null : $texto;
    }

    private function hasValidSignature(Request $request): bool
    {
        $secret = (string) config('services.comms_core.webhook_secret');
        $timestamp = (string) $request->header('X-Nexolu-Timestamp');
        $signature = (string) $request->header('X-Nexolu-Signature');

        // Sin secreto configurado NO se acepta nada: un webhook abierto es
        // una via para escribirle a las clientas del negocio a su nombre.
        if ($secret === '' || $timestamp === '' || $signature === '') {
            return false;
        }

        // Una firma vieja reproducida vuelve a disparar la respuesta. Cinco
        // minutos alcanzan para un reintento honesto.
        if (abs(now()->timestamp - (int) $timestamp) > 300) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$request->getContent(), $secret);

        return hash_equals($expected, $signature);
    }
}
