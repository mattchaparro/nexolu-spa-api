<?php

namespace App\Http\Controllers\Api\V1;

use App\Services\Ia\PanelAssistant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * El chat del asistente en el panel, y la confirmación de lo que propone.
 *
 * Solo hace de puente con el IA Core: la llave del IA Core nunca baja al
 * navegador, y quien pregunta viaja resuelto desde la sesión, no desde lo
 * que mande la pantalla.
 */
class PanelAssistantController
{
    public function __construct(private readonly PanelAssistant $assistant) {}

    public function chat(Request $request): JsonResponse
    {
        $data = $request->validate([
            'message' => ['required', 'string', 'max:2000'],
            'conversation_id' => ['nullable', 'string', 'max:64'],
        ]);

        try {
            $respuesta = $this->assistant->ask($request->user(), $data['message'], $data['conversation_id'] ?? null);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }

        return response()->json([
            'conversation_id' => $respuesta['conversation_id'] ?? null,
            'text' => (string) ($respuesta['text'] ?? ''),
            'drafts' => $respuesta['drafts'] ?? [],
            'tools_used' => $respuesta['tools_used'] ?? [],
        ]);
    }

    public function confirm(Request $request, string $draft): JsonResponse
    {
        $data = $request->validate(['values' => ['nullable', 'array']]);

        try {
            $respuesta = $this->assistant->confirm($request->user(), $draft, $data['values'] ?? null);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }

        return response()->json($respuesta->json(), $respuesta->status());
    }

    public function discard(Request $request, string $draft): JsonResponse
    {
        try {
            $respuesta = $this->assistant->discard($request->user(), $draft);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }

        return response()->json($respuesta->json(), $respuesta->status());
    }
}
