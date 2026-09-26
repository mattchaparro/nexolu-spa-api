<?php

namespace App\Http\Controllers\Api\V1;

use App\Services\WhatsApp\ConnectChat;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * El menu "WhatsApp": entra a la persona al chat de su salon en Connect.
 *
 * Devuelve una URL con un pase de un solo uso que vence en dos minutos,
 * asi que el front la pide en el momento del clic y la abre enseguida;
 * no se guarda ni se reusa. Ver App\Services\WhatsApp\ConnectChat.
 */
class ConnectChatController
{
    public function link(Request $request, ConnectChat $connect): JsonResponse
    {
        if (! $connect->isConfigured()) {
            return response()->json(['message' => 'El chat no está configurado todavía.'], 503);
        }

        $data = $request->validate([
            // Desde una cita de la agenda: abrir directo el chat de esa clienta.
            'phone' => ['nullable', 'string', 'max:32'],
            'name' => ['nullable', 'string', 'max:80'],
        ]);

        $next = '/chat';

        if (! empty($data['phone'])) {
            $next .= '?'.http_build_query(array_filter([
                'phone' => preg_replace('/\D+/', '', $data['phone']),
                'name' => $data['name'] ?? null,
            ]));
        }

        $url = $connect->loginUrl($request->user(), $next);

        if ($url === null) {
            // Que Connect este caido no tumba el panel del salon: se dice
            // que el chat no esta disponible y el resto sigue.
            return response()->json(['message' => 'El chat no está disponible en este momento.'], 503);
        }

        return response()->json(['url' => $url]);
    }
}
