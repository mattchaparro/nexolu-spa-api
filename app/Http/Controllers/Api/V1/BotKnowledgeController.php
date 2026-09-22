<?php

namespace App\Http\Controllers\Api\V1;

use App\Services\Ia\KnowledgeClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "Enséñale al bot": las preguntas frecuentes que responde por WhatsApp.
 *
 * Solo pasa las llamadas al IA Core (ver KnowledgeClient), acotadas al
 * negocio de quien está autenticado. Si el Core no responde, se dice con
 * un 503 claro en vez de una pantalla en blanco.
 */
class BotKnowledgeController
{
    public function __construct(private readonly KnowledgeClient $knowledge) {}

    public function index(Request $request): JsonResponse
    {
        return $this->guarded(fn () => ['data' => $this->knowledge->list($request->user()->business)]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'topic' => ['required', 'string', 'min:2', 'max:160'],
            'answer' => ['required', 'string', 'min:2', 'max:4000'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        return $this->guarded(fn () => ['data' => $this->knowledge->create($request->user()->business, $data)], 201);
    }

    public function update(Request $request, string $entry): JsonResponse
    {
        $data = $request->validate([
            'topic' => ['sometimes', 'string', 'min:2', 'max:160'],
            'answer' => ['sometimes', 'string', 'min:2', 'max:4000'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        return $this->guarded(function () use ($request, $entry, $data) {
            $response = $this->knowledge->update($request->user()->business, $entry, $data);

            abort_if($response->status() === 404, 404, 'No existe esa entrada.');

            return ['data' => $response->throw()->json()];
        });
    }

    public function destroy(Request $request, string $entry): JsonResponse
    {
        return $this->guarded(function () use ($request, $entry) {
            $response = $this->knowledge->delete($request->user()->business, $entry);

            abort_if($response->status() === 404, 404, 'No existe esa entrada.');
            $response->throw();

            return ['ok' => true];
        });
    }

    /** @param callable(): array<string, mixed> $accion */
    private function guarded(callable $accion, int $status = 200): JsonResponse
    {
        if (! $this->knowledge->isConfigured()) {
            return response()->json(['message' => 'El asistente de IA no está configurado.'], 503);
        }

        try {
            return response()->json($accion(), $status);
        } catch (RequestException $e) {
            report($e);

            return response()->json(['message' => 'No pude hablar con el asistente de IA. Intenta de nuevo.'], 503);
        } catch (ConnectionException $e) {
            report($e);

            return response()->json(['message' => 'No pude hablar con el asistente de IA. Intenta de nuevo.'], 503);
        }
    }
}
