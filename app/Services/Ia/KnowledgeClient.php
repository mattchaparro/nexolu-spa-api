<?php

namespace App\Services\Ia;

use App\Models\Business;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Las preguntas frecuentes del bot, guardadas en el IA Core.
 *
 * Viven en el Core y no aquí porque son del NÚCLEO del bot: el POS o el
 * colegio las heredan sin rehacerlas (decisión de Alejandro, 22/09/2026).
 * El spa es quien sabe QUIÉN puede editarlas (permiso `ia.conocimiento`),
 * así que el panel habla con este puente y el puente con el Core, siempre
 * con la llave de la app y el negocio del usuario autenticado: el
 * `business_id` nunca lo elige el navegador.
 */
class KnowledgeClient
{
    public function isConfigured(): bool
    {
        return ! empty(config('services.ia_core.api_key'))
            && ! empty(config('services.ia_core.base_url'));
    }

    /** @return list<array<string, mixed>> */
    public function list(Business $business): array
    {
        return $this->http()->get('/v1/knowledge', ['business_id' => (string) $business->id])->throw()->json();
    }

    /** @param array{topic: string, answer: string, is_active?: bool} $data */
    public function create(Business $business, array $data): array
    {
        return $this->http()->post('/v1/knowledge', [...$data, 'business_id' => (string) $business->id])->throw()->json();
    }

    /** @param array{topic?: string, answer?: string, is_active?: bool} $data */
    public function update(Business $business, string $id, array $data): Response
    {
        return $this->http()->patch('/v1/knowledge/'.rawurlencode($id), [...$data, 'business_id' => (string) $business->id]);
    }

    public function delete(Business $business, string $id): Response
    {
        return $this->http()->delete('/v1/knowledge/'.rawurlencode($id).'?business_id='.rawurlencode((string) $business->id));
    }

    private function http(): PendingRequest
    {
        return Http::withToken((string) config('services.ia_core.api_key'))
            ->acceptJson()
            ->timeout(15)
            ->baseUrl(rtrim((string) config('services.ia_core.base_url'), '/'));
    }
}
