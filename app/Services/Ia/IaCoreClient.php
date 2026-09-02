<?php

namespace App\Services\Ia;

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
        if (! $this->isConfigured()) {
            Log::warning('IA Core: intento de consulta sin credenciales');

            return null;
        }

        $business = $conversation->business;

        try {
            $response = Http::withToken((string) config('services.ia_core.api_key'))
                // Un modelo se demora: 15s no alcanza y cortar a mitad de
                // respuesta deja a la clienta sin contestacion y el turno
                // igualmente cobrado.
                ->timeout(45)
                ->baseUrl(rtrim((string) config('services.ia_core.base_url'), '/'))
                ->post('/v1/chat', [
                    'conversation_id' => $conversation->ia_conversation_id,
                    'agent' => 'recepcionista',
                    'message' => $message,
                    'context' => [
                        'business_id' => (string) $business->id,
                        'user_id' => $conversation->phone,
                        'is_admin' => false,
                        // Sin permisos: una clienta no tiene ninguno. Lo que
                        // puede hacer lo decide `allowsCustomers()` del lado
                        // del Spa, no una lista que viaje por la red.
                        'permissions' => [],
                        'features' => array_keys(array_filter($business->feature_flags ?? [])),
                        'channel' => 'whatsapp',
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
