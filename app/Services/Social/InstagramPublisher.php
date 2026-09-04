<?php

namespace App\Services\Social;

use App\Models\BusinessSocialAccount;
use App\Models\SocialPost;
use App\Support\ImageStorage;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Publica en Instagram.
 *
 * EL FLUJO DE META ES EN DOS TIEMPOS, y no es un capricho de la API: primero
 * se crea un CONTENEDOR con la imagen y el texto, y despues se publica ese
 * contenedor. Con carrusel son tres tiempos -- un contenedor por foto, uno
 * que los agrupa, y el publish.
 *
 * META DESCARGA LA IMAGEN, no se la mandamos. Por eso `image_url` tiene que
 * ser alcanzable desde internet sin firma ni autenticacion, y por eso importa
 * tanto que las imagenes esten comprimidas: en un carrusel de diez, es nuestro
 * droplet el que sirve esos bytes. Ver docs/imagenes.md.
 *
 * LO QUE NO HACE: decidir si publicar. Eso ya lo decidio una persona al
 * aprobar el texto, y esta clase solo ejecuta. La distincion importa -- es lo
 * que permite que el reloj publique lo programado sin que nadie tema que un
 * modelo saque algo que nadie leyo.
 */
class InstagramPublisher
{
    /**
     * Cuanto esperar a que un contenedor quede listo.
     *
     * Con imagenes suele estar listo al instante, pero Meta lo documenta como
     * asincrono y a veces tarda: publicar un contenedor a medio hacer devuelve
     * un error que parece de otra cosa. Se pregunta unas pocas veces y se
     * desiste -- colgarse un minuto en una peticion del panel es peor que
     * decir "intenta otra vez".
     */
    private const READY_ATTEMPTS = 5;

    private const READY_WAIT_MS = 700;

    /** El ultimo mensaje que devolvio Meta, para no perderlo entre capas. */
    private ?string $lastError = null;

    /**
     * Publica y devuelve el id del medio en Instagram.
     *
     * @return array{ok: true, id: string}|array{ok: false, error: string}
     */
    public function publish(SocialPost $post, BusinessSocialAccount $account): array
    {
        if (($motivo = $account->unusableReason()) !== null) {
            return $this->fail($motivo);
        }

        if (($motivo = InstagramLimits::rejects($post)) !== null) {
            return $this->fail($motivo);
        }

        $images = $post->images;

        try {
            $containers = [];

            foreach ($images as $image) {
                $url = ImageStorage::url($image->image_path ?: $image->clientPhoto?->image_path);

                $id = $this->createContainer($account, $url, $images->count() > 1, $post);

                if ($id === null) {
                    return $this->fail($this->lastError ?? 'Instagram no aceptó la imagen.');
                }

                $containers[] = $id;
            }

            $final = count($containers) === 1
                ? $containers[0]
                : $this->createCarousel($account, $containers, $post);

            if ($final === null) {
                return $this->fail($this->lastError ?? 'Instagram no aceptó el carrusel.');
            }

            if (! $this->waitUntilReady($account, $final)) {
                return $this->fail('Instagram todavía está procesando las imágenes. Intenta otra vez en un momento.');
            }

            return $this->publishContainer($account, $final);
        } catch (ConnectionException $e) {
            Log::warning('Instagram: error de red', ['error' => $e->getMessage()]);

            return $this->fail('No pudimos comunicarnos con Instagram. Intenta otra vez.');
        }
    }

    /**
     * Un contenedor con una imagen.
     *
     * `is_carousel_item` cambia el significado del contenedor: sin el, ES la
     * publicacion; con el, es una pieza que todavia no sale sola. El texto va
     * en el contenedor final, no en las piezas -- ponerlo en cada una lo
     * repetiria o lo perderia, segun el humor de la API.
     */
    private function createContainer(
        BusinessSocialAccount $account,
        ?string $imageUrl,
        bool $isCarouselItem,
        SocialPost $post,
    ): ?string {
        if ($imageUrl === null) {
            $this->lastError = 'Una de las imágenes ya no está disponible.';

            return null;
        }

        $payload = ['image_url' => $imageUrl];

        if ($isCarouselItem) {
            $payload['is_carousel_item'] = 'true';
        } else {
            $payload['caption'] = InstagramLimits::fullCaption($post);
        }

        return $this->idFrom($this->post($account, 'media', $payload));
    }

    /** @param  list<string>  $children */
    private function createCarousel(BusinessSocialAccount $account, array $children, SocialPost $post): ?string
    {
        return $this->idFrom($this->post($account, 'media', [
            'media_type' => 'CAROUSEL',
            'children' => implode(',', $children),
            'caption' => InstagramLimits::fullCaption($post),
        ]));
    }

    /**
     * @return array{ok: true, id: string}|array{ok: false, error: string}
     */
    private function publishContainer(BusinessSocialAccount $account, string $containerId): array
    {
        $id = $this->idFrom($this->post($account, 'media_publish', ['creation_id' => $containerId]));

        if ($id === null) {
            return $this->fail($this->lastError ?? 'Instagram rechazó la publicación.');
        }

        $account->forceFill(['last_published_at' => now()])->save();

        return ['ok' => true, 'id' => $id];
    }

    /**
     * Espera a que el contenedor quede en FINISHED.
     *
     * `ERROR` corta de una: seguir preguntando por algo que Meta ya dio por
     * fallido son cuatro segundos de nada.
     */
    private function waitUntilReady(BusinessSocialAccount $account, string $containerId): bool
    {
        for ($intento = 0; $intento < self::READY_ATTEMPTS; $intento++) {
            $response = Http::timeout(20)->get($this->url($containerId), [
                'fields' => 'status_code',
                'access_token' => $account->access_token,
            ]);

            $status = (string) $response->json('status_code');

            if ($status === 'FINISHED' || $status === '') {
                // Vacio tambien pasa: hay respuestas que no traen el campo, y
                // asumir "no esta listo" dejaria colgada una publicacion sana.
                return true;
            }

            if ($status === 'ERROR' || $status === 'EXPIRED') {
                return false;
            }

            usleep(self::READY_WAIT_MS * 1000);
        }

        return false;
    }

    /** @param  array<string, string>  $payload */
    private function post(BusinessSocialAccount $account, string $edge, array $payload): Response
    {
        return Http::timeout(30)
            ->asForm()
            ->post($this->url($account->external_id.'/'.$edge), $payload + [
                'access_token' => $account->access_token,
            ]);
    }

    private function url(string $path): string
    {
        return rtrim((string) config('services.instagram.graph_url'), '/')
            .'/'.config('services.instagram.graph_version')
            .'/'.$path;
    }

    /**
     * El id de la respuesta, o null dejando el motivo en `lastError`.
     *
     * El mensaje de Meta se guarda TAL CUAL ademas de loguearlo. Es en ingles
     * y a veces cripticio, pero es lo unico que distingue "el token caduco" de
     * "esa imagen no le gusto", y esconderlo detras de un "algo salio mal"
     * deja a quien lo tenga que arreglar sin nada.
     */
    private function idFrom(Response $response): ?string
    {
        if ($response->successful() && $response->json('id')) {
            return (string) $response->json('id');
        }

        $mensaje = (string) ($response->json('error.message') ?? '');

        Log::warning('Instagram: rechazo', [
            'status' => $response->status(),
            'code' => $response->json('error.code'),
            'subcode' => $response->json('error.error_subcode'),
            'message' => $mensaje,
        ]);

        $this->lastError = $mensaje === ''
            ? 'Instagram rechazó la publicación (HTTP '.$response->status().').'
            : 'Instagram dijo: '.$mensaje;

        return null;
    }

    /** @return array{ok: false, error: string} */
    private function fail(string $error): array
    {
        return ['ok' => false, 'error' => $error];
    }
}
