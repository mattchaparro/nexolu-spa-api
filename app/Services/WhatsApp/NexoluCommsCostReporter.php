<?php

namespace App\Services\WhatsApp;

use App\Services\Messaging\Contracts\MessagingCostReporter;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Implementacion de MessagingCostReporter que consulta el gasto propio de
 * este POS a Nexolu Communications (GET /v1/usage/summary, con la misma
 * api_key con la que NexoluCommsChannel envia) en vez de sumar una tabla
 * local - Comms ya audita cada envio del lado suyo. Mismo patron que
 * AiPlatformUsageService usa contra el IA Core.
 *
 * Nunca lanza: un costo que no se pudo consultar es 'desconocido' (null),
 * no un error que tumbe el dashboard de Finance - ver PlatformFinanceService.
 */
class NexoluCommsCostReporter implements MessagingCostReporter
{
    public function costUsdForPeriod(string $dateFrom, string $dateTo): ?float
    {
        $baseUrl = config('services.comms_core.base_url');
        $apiKey = config('services.comms_core.api_key');

        if (! $baseUrl || ! $apiKey) {
            return null;
        }

        try {
            $response = Http::withToken($apiKey)
                ->timeout(15)
                ->get(rtrim($baseUrl, '/').'/v1/usage/summary', [
                    'channel' => 'whatsapp',
                    'date_from' => $dateFrom,
                    'date_to' => $dateTo,
                ]);
        } catch (\Throwable $e) {
            Log::warning('nexolu_comms_cost: no se pudo contactar a Nexolu Communications', ['error' => $e->getMessage()]);

            return null;
        }

        if ($response->failed()) {
            Log::warning('nexolu_comms_cost: Nexolu Communications rechazo la consulta', ['status' => $response->status()]);

            return null;
        }

        return (float) ($response->json('summary.cost_usd') ?? 0);
    }
}
