<?php

namespace App\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * La llave para mostrar la bandeja de Connect dentro del panel del Spa.
 *
 * Por qué no la pinta el Spa. La bandeja de WhatsApp está escrita dos
 * veces: una en Connect -- con búsqueda, plantillas, adjuntos, ficha del
 * contacto y respuestas rápidas -- y otra acá, más pobre. Cada mejora hay
 * que hacerla dos veces o dejar una de las dos atrás, y eso con dos apps
 * ya duele. Así que la pantalla es la de Connect y el Spa la muestra
 * adentro.
 *
 * Lo que no puede pasar es que la API key del Spa baje a un navegador:
 * esa llave manda WhatsApp a nombre de todos los negocios. Por eso este
 * endpoint la cambia, de servidor a servidor, por un token corto que solo
 * sirve para el chat de ESTE negocio.
 *
 * Quién puede verlo lo decide el Spa -- acá está la cuenta de quien
 * entra y su permiso `clientes.ver` -- porque Connect no conoce a esa
 * persona y no tiene por qué.
 */
class ChatEmbebidoController
{
    public function token(Request $request): JsonResponse
    {
        $business = $request->user()->business;

        $base = rtrim((string) config('services.comms_core.base_url'), '/');
        $llave = (string) config('services.comms_core.api_key');

        if ($base === '' || $llave === '') {
            return response()->json([
                'message' => 'El chat no está configurado todavía.',
            ], 503);
        }

        try {
            $respuesta = Http::withToken($llave)
                ->acceptJson()
                ->timeout(8)
                ->post($base.'/v1/embed/chat-token', [
                    'business_id' => (string) $business->id,
                ]);
        } catch (\Throwable $e) {
            // Que Connect esté caído no puede tumbar el panel del salón:
            // se dice que el chat no está disponible y el resto sigue.
            Log::warning('Chat embebido: no se pudo pedir el token', ['error' => $e->getMessage()]);

            return response()->json(['message' => 'El chat no está disponible en este momento.'], 503);
        }

        if (! $respuesta->successful()) {
            Log::warning('Chat embebido: Connect rechazó la petición', [
                'status' => $respuesta->status(),
                'body' => $respuesta->body(),
            ]);

            return response()->json(['message' => 'El chat no está disponible en este momento.'], 503);
        }

        return response()->json([
            'token' => $respuesta->json('token'),
            'expires_at' => $respuesta->json('expires_at'),
            // La URL viene de Connect y no de una variable de acá: si algún
            // día cambia la ruta del panel, no hay que desplegar el Spa.
            'url' => $respuesta->json('url'),
        ]);
    }
}
