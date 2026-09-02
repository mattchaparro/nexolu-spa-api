<?php

namespace App\Http\Controllers\Api;

use App\Models\Message;
use App\Services\Ia\IaCoreClient;
use App\Services\Messaging\MessageDispatcher;
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
    public function __construct(
        private readonly ConversationRouter $router,
        private readonly IaCoreClient $ia,
        private readonly MessageDispatcher $dispatcher,
    ) {}

    public function whatsapp(Request $request): JsonResponse
    {
        if (! $this->hasValidSignature($request)) {
            Log::warning('comms.webhook: firma invalida', ['ip' => $request->ip()]);

            return response()->json(['error' => 'invalid_signature'], 401);
        }

        $entrante = $this->firstTextMessage($request->json()->all());

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

        $respuesta = $this->ia->ask($conversacion, $texto);

        if ($respuesta === null) {
            return response()->json(['ok' => true, 'handled' => false]);
        }

        if ($respuesta['conversation_id'] !== null) {
            $conversacion->update(['ia_conversation_id' => $respuesta['conversation_id']]);
        }

        /*
         * La respuesta sale por el mismo camino que todo lo demas -- el
         * outbox -- y no por una llamada suelta al canal. Asi queda escrita,
         * se puede auditar y, si el negocio esta en modo manual, alguien la
         * manda a mano en vez de perderse.
         */
        $this->dispatcher->queue(
            $conversacion->business,
            Message::KIND_AGENT,
            $normalizado,
            $respuesta['text'],
            null,
            $conversacion->client,
        );

        return response()->json(['ok' => true, 'handled' => true]);
    }

    /**
     * El primer mensaje de TEXTO del cuerpo de Meta, si lo hay.
     *
     * Meta manda lotes y mete de todo en el mismo sobre: estados de entrega,
     * recibos de lectura, adjuntos. Aca solo interesa lo que una persona
     * escribio.
     *
     * @param  array<string, mixed>  $payload
     * @return array{0: ?string, 1: string, 2: string}|null [phone_number_id, de, texto]
     */
    private function firstTextMessage(array $payload): ?array
    {
        foreach ($payload['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                $value = $change['value'] ?? [];
                $phoneNumberId = $value['metadata']['phone_number_id'] ?? null;

                foreach ($value['messages'] ?? [] as $message) {
                    if (($message['type'] ?? null) !== 'text') {
                        continue;
                    }

                    $texto = trim((string) ($message['text']['body'] ?? ''));
                    $from = (string) ($message['from'] ?? '');

                    if ($texto === '' || $from === '') {
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
