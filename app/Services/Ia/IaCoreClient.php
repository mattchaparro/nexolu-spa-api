<?php

namespace App\Services\Ia;

use App\Models\Business;
use App\Models\User;
use App\Models\WhatsappConversation;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Le pregunta al Nexolu IA Core que responder.
 *
 * El Core guarda el historial: aca solo se manda el mensaje nuevo y el
 * `conversation_id` de esa charla. Mandarle el historial completo cada vez
 * seria duplicar el estado en dos lados y garantizar que se desincronicen.
 *
 * El bloque `context` es lo que ESTA API AFIRMA sobre quien pregunta. Va con
 * `channel: whatsapp` y el telefono como `user_id`, y del otro lado vuelve
 * tal cual en cada invocacion de herramienta -- donde se revalida contra la
 * base (ver AiToolInvokeController). Nunca lleva permisos: quien escribe por
 * WhatsApp es una clienta, no una empleada.
 */
class IaCoreClient
{
    public function __construct(private readonly BusinessProfile $profile) {}

    public function isConfigured(): bool
    {
        return ! empty(config('services.ia_core.api_key'))
            && ! empty(config('services.ia_core.base_url'));
    }

    /**
     * @return array{text: string, conversation_id: ?string}|null null si el Core no respondio
     */
    public function ask(WhatsappConversation $conversation, string $message): ?array
    {
        $business = $conversation->business;

        return $this->chat($business, $conversation->ia_conversation_id, 'recepcionista', $message, [
            'user_id' => $conversation->phone,
            'is_admin' => false,
            // Sin permisos: una clienta no tiene ninguno. Lo que
            // puede hacer lo decide `allowsCustomers()` del lado
            // del Spa, no una lista que viaje por la red.
            'permissions' => [],
            'channel' => 'whatsapp',
        ]);
    }

    /**
     * Un encargo suelto: se manda un texto, vuelve un texto.
     *
     * Sin `conversation_id` a proposito. Redactar la publicacion del martes
     * no tiene nada que ver con la del lunes, y encadenarlas haria que el
     * modelo arrastre el tono -- y los errores -- de lo anterior. Cada
     * encargo empieza en blanco.
     *
     * Va SIN PERMISOS y sin `is_admin`, aunque lo pida la duena desde el
     * panel. Escribir un texto no necesita tocar la agenda, y una llamada que
     * no puede ejecutar herramientas no puede ser desviada a ejecutarlas: lo
     * que se le manda al modelo incluye el nombre de servicios y a veces el
     * de una clienta, y eso es entrada que no controlamos del todo.
     *
     * @return string|null null si el Core no respondio
     */
    public function compose(Business $business, string $agent, string $prompt, ?User $onBehalfOf = null): ?string
    {
        $result = $this->chat($business, null, $agent, $prompt, [
            'user_id' => $onBehalfOf === null ? 'sistema' : (string) $onBehalfOf->id,
            'is_admin' => false,
            'permissions' => [],
            'channel' => 'panel',
        ]);

        return $result['text'] ?? null;
    }

    /**
     * La unica que habla con el Core.
     *
     * El bloque `context` se arma aca y no en quien llama: los campos que
     * identifican al negocio -- perfil, banderas, zona horaria -- son los
     * mismos para todos los agentes, y repartirlos entre los llamantes es
     * como uno termina mandando un contexto incompleto desde el camino nuevo.
     *
     * @param  array<string, mixed>  $context  Lo propio de quien pregunta.
     * @return array{text: string, conversation_id: ?string}|null
     */
    private function chat(
        Business $business,
        ?string $conversationId,
        string $agent,
        string $message,
        array $context,
    ): ?array {
        if (! $this->isConfigured()) {
            Log::warning('IA Core: intento de consulta sin credenciales');

            return null;
        }

        try {
            $response = Http::withToken((string) config('services.ia_core.api_key'))
                // Un modelo se demora: 15s no alcanza y cortar a mitad de
                // respuesta deja a la clienta sin contestacion y el turno
                // igualmente cobrado.
                ->timeout(45)
                ->baseUrl(rtrim((string) config('services.ia_core.base_url'), '/'))
                ->post('/v1/chat', [
                    'conversation_id' => $conversationId,
                    'agent' => $agent,
                    'message' => $message,
                    'context' => $context + [
                        'business_id' => (string) $business->id,
                        'features' => array_keys(array_filter($business->feature_flags ?? [])),
                        /*
                         * Quien es este negocio. El Core no puede saberlo --
                         * no tiene la base de datos del negocio -- y sin esto
                         * el agente contesta "consulta con el
                         * establecimiento" a preguntas que el sistema si sabe
                         * responder.
                         */
                        'business_profile' => $this->profile->for($business),
                        'timezone' => $business->businessTimezone(),
                        'locale' => 'es',
                    ],
                ]);
        } catch (ConnectionException $e) {
            Log::warning('IA Core: error de red', ['error' => $e->getMessage()]);

            return null;
        }

        if ($response->failed()) {
            Log::warning('IA Core: respuesta con error', [
                'status' => $response->status(),
                'body' => mb_substr($response->body(), 0, 500),
            ]);

            return null;
        }

        $texto = trim((string) $response->json('text'));

        if ($texto === '') {
            return null;
        }

        return [
            'text' => $texto,
            'conversation_id' => $response->json('conversation_id'),
        ];
    }
}
