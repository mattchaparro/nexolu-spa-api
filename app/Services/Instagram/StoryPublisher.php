<?php

namespace App\Services\Instagram;

use App\Models\InstagramStory;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Publica una historia, a traves de Nexolu Communications.
 *
 * El Spa NO le habla a Meta. Mismo criterio que WhatsApp: hay un solo lugar
 * en el ecosistema donde viven las credenciales de Meta y donde se maneja su
 * API, y no es este servicio. Agregar un segundo camino seria reintroducir
 * la deuda que el POS todavia esta pagando.
 */
class StoryPublisher
{
    public function isConfigured(): bool
    {
        return ! empty(config('services.comms_core.api_key'))
            && ! empty(config('services.comms_core.base_url'));
    }

    /**
     * Intenta publicar. Deja el resultado escrito en la fila.
     *
     * No lanza: una historia que Meta rechaza no puede tumbar el comando que
     * la despacho -- detras puede haber diez mas esperando su turno.
     */
    public function publish(InstagramStory $story): bool
    {
        $url = $story->imageUrl();

        if ($url === null) {
            return $this->fail($story, 'La imagen ya no existe.');
        }

        if (! $this->isConfigured()) {
            return $this->fail($story, 'Communications no está configurado.');
        }

        try {
            $response = Http::withToken((string) config('services.comms_core.api_key'))
                // Publicar son DOS llamadas de Communications a Meta, y la
                // segunda espera a que Meta descargue la imagen. Quince
                // segundos no alcanzan.
                ->timeout(60)
                ->baseUrl(rtrim((string) config('services.comms_core.base_url'), '/'))
                ->post('/v1/instagram/stories', array_filter([
                    'image_url' => $url,
                    'mentions' => $story->mentions ?: null,
                ]));
        } catch (ConnectionException $e) {
            return $this->fail($story, 'No se pudo contactar a Communications: '.$e->getMessage());
        }

        if ($response->failed()) {
            return $this->fail($story, 'Communications rechazó la publicación ('.$response->status().').');
        }

        if ($response->json('status') !== 'published') {
            /*
             * El MOTIVO, no un "falló". Entre "la imagen no es JPEG" y "el
             * token caducó" está la diferencia entre volver a subir la foto
             * y renovar la credencial en Meta.
             */
            return $this->fail($story, (string) ($response->json('error') ?? 'Instagram no la publicó.'));
        }

        $story->update([
            'status' => InstagramStory::STATUS_PUBLISHED,
            'published_at' => now(),
            'media_id' => $response->json('media_id'),
            'error' => null,
        ]);

        return true;
    }

    private function fail(InstagramStory $story, string $motivo): bool
    {
        $story->update([
            'status' => InstagramStory::STATUS_FAILED,
            'error' => mb_substr($motivo, 0, 500),
        ]);

        Log::warning('instagram: historia no publicada', [
            'story_id' => $story->id,
            'motivo' => $motivo,
        ]);

        return false;
    }
}
