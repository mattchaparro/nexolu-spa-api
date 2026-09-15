<?php

namespace App\Services\WhatsApp;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Dispara flujos de conversacion de Nexolu Connect
 * (POST /v1/flows/trigger en nexolu-comms-api).
 *
 * Un flujo NO es un mensaje del spa: es una conversacion que Connect
 * atiende solo (botones, ramas, tags) - por eso no pasa por
 * MessageDispatcher ni por la bandeja de salida. Lo que el flujo envie
 * queda auditado del lado de Connect (panel connect.nexolu.co,
 * notificaciones con reference=flow:<nombre>); aca solo queda el disparo
 * en el resultado de la accion de etapa que lo pidio.
 *
 * Nunca lanza hacia el caller: mover una cita de etapa no puede fallar
 * porque Connect este caido - devuelve false y deja el motivo en el log.
 */
class NexoluConnectFlows
{
    public function isConfigured(): bool
    {
        return ! empty(config('services.comms_core.api_key'))
            && ! empty(config('services.comms_core.base_url'));
    }

    /**
     * @param  array<string, string>  $variables  se interpolan en los nodos como {{variable}}
     */
    public function trigger(
        string $flow,
        string $phone,
        int $businessId,
        array $variables = [],
        ?string $contactName = null,
    ): bool {
        if (! $this->isConfigured()) {
            return false;
        }

        try {
            $response = Http::withToken((string) config('services.comms_core.api_key'))
                ->timeout(15)
                ->acceptJson()
                ->baseUrl(rtrim((string) config('services.comms_core.base_url'), '/'))
                ->post('/v1/flows/trigger', [
                    'flow' => $flow,
                    'to' => $phone,
                    'business_id' => (string) $businessId,
                    'variables' => $variables,
                    'contact_name' => (string) $contactName,
                ]);
        } catch (ConnectionException $e) {
            Log::warning('connect_flows: sin conexion con Connect', ['flow' => $flow, 'error' => $e->getMessage()]);

            return false;
        }

        if ($response->failed()) {
            Log::warning('connect_flows: Connect rechazo el disparo', [
                'flow' => $flow,
                'status' => $response->status(),
                'detail' => $response->json('detail'),
            ]);

            return false;
        }

        return true;
    }
}
