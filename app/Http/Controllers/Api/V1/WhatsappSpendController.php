<?php

namespace App\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Cuánto se gasta el salón en WhatsApp, según Meta.
 *
 * No es una cuenta nuestra: Connect le pregunta a Meta (pricing_analytics)
 * lo que de verdad cobra, en la moneda de la cuenta (COP), por tipo de
 * mensaje y por día. Solo lo ve quien administra el negocio.
 */
class WhatsappSpendController
{
    public function show(Request $request): JsonResponse
    {
        $data = $request->validate(['mes' => ['nullable', 'regex:/^\d{4}-\d{2}$/']]);

        $base = rtrim((string) config('services.comms_core.base_url'), '/');
        $llave = (string) config('services.comms_core.api_key');

        if ($base === '' || $llave === '') {
            return response()->json(['message' => 'WhatsApp no está configurado todavía.'], 503);
        }

        try {
            $respuesta = Http::withToken($llave)->acceptJson()->timeout(20)
                ->get($base.'/v1/usage/whatsapp-spend', array_filter([
                    'month' => $data['mes'] ?? null,
                    // El negocio de la sesión, nunca del request.
                    'business_id' => (string) $request->user()->business_id,
                ]));
        } catch (\Throwable $e) {
            Log::warning('gasto whatsapp: Connect no respondió', ['error' => $e->getMessage()]);

            return response()->json(['message' => 'No pudimos consultar el gasto en este momento.'], 503);
        }

        if (! $respuesta->successful()) {
            return response()->json([
                'message' => 'Meta no entregó el gasto: '.($respuesta->json('detail') ?? 'intenta más tarde'),
            ], 503);
        }

        return response()->json($respuesta->json());
    }
}
