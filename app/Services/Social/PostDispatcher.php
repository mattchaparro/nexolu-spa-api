<?php

namespace App\Services\Social;

use App\Models\Business;
use App\Models\SocialPost;
use Carbon\CarbonImmutable;

/**
 * El reloj de las publicaciones.
 *
 * LA GARANTIA NO ES "EL SISTEMA NO PUBLICA": ES "NADIE PUBLICA SIN QUE UNA
 * PERSONA LO HAYA LEIDO". Y esa persona ya paso -- programar una publicacion
 * ES aprobarla, con el texto y la foto adelante. Lo que el reloj hace despues
 * es apretar un boton que alguien ya decidio apretar.
 *
 * (Durante un tiempo esto se detuvo en `ready` a proposito, cuando todavia no
 * habia con que publicar. La regla que se estaba defendiendo era la de
 * arriba, no la parada en si.)
 *
 * ASI QUE HAY DOS CAMINOS, y el que se toma depende de si el negocio conecto
 * su cuenta:
 *
 *   con cuenta:  scheduled --(su hora)--> published
 *   sin cuenta:  scheduled --(su hora)--> ready --(una persona)--> published
 *
 * `ready` sigue existiendo y sigue siendo el modo por defecto, igual que
 * `messaging_mode: manual` en la mensajeria: un spa opera asi sus primeras
 * semanas de todas formas, y hay quien no va a querer salir de ahi nunca.
 *
 * SI META RECHAZA, la publicacion NO se pierde: queda en `failed` con el
 * motivo escrito y visible en el calendario. Una publicacion que desaparece
 * porque un token caduco es peor que una que no salio.
 */
class PostDispatcher
{
    public function __construct(private readonly InstagramPublisher $instagram) {}

    /**
     * @return array{ready: int, published: int, failed: int, returned: int}
     */
    public function run(Business $business, ?CarbonImmutable $now = null): array
    {
        $vacio = ['ready' => 0, 'published' => 0, 'failed' => 0, 'returned' => 0];

        if (! $business->hasFeature('social_posts')) {
            return $vacio;
        }

        $now ??= CarbonImmutable::now($business->businessTimezone());

        /*
         * VENTANA ABIERTA, no instante exacto: se toman las que ya les paso
         * la hora, no las que la cumplen justo ahora. Una corrida perdida --
         * el servidor estaba caido -- se recupera sola en la siguiente, en
         * vez de dejar esa publicacion esperando para siempre una hora que ya
         * paso. Mismo criterio que los recordatorios.
         */
        $due = SocialPost::withoutGlobalScopes()
            ->where('business_id', $business->id)
            ->where('status', SocialPost::STATUS_SCHEDULED)
            /*
             * `->utc()` y no `$now` a secas: las fechas se guardan en UTC y
             * este `$now` esta en la zona del negocio. Sin convertirlo,
             * Eloquent compara la hora de Bogota contra columnas en UTC y el
             * reloj se adelanta cinco horas. Mismo cuidado que los
             * recordatorios.
             */
            ->where('scheduled_for', '<=', $now->utc())
            ->with(['images.clientPhoto'])
            ->get();

        /*
         * La cuenta se resuelve UNA VEZ por negocio, no por publicacion: son
         * la misma cuenta y la misma comprobacion de vencimiento.
         */
        $cuenta = $business->instagramAccount;
        $publicaSola = $cuenta !== null && $cuenta->isUsable();

        $ready = 0;
        $published = 0;
        $failed = 0;
        $returned = 0;

        foreach ($due as $post) {
            /*
             * Se programo completa -- el controlador no deja programar otra
             * cosa -- pero entre esa hora y esta pudieron borrar la foto de
             * la ficha, o la clienta pudo retirar su permiso. Vuelve a
             * propuesta CON el motivo escrito: dejarla en "programada" seria
             * esconderla en un estado que nadie revisa.
             */
            if (! $post->isComplete()) {
                $post->forceFill([
                    'status' => SocialPost::STATUS_DRAFT,
                    'error' => 'Le llegó la hora sin imagen o sin texto. Se devolvió a propuestas.',
                ])->save();

                $returned++;

                continue;
            }

            if (! $publicaSola) {
                $post->forceFill([
                    'status' => SocialPost::STATUS_READY,
                    'error' => null,
                ])->save();

                $ready++;

                continue;
            }

            $resultado = $this->instagram->publish($post, $cuenta);

            if ($resultado['ok']) {
                $post->forceFill([
                    'status' => SocialPost::STATUS_PUBLISHED,
                    'published_at' => now(),
                    'external_ref' => $resultado['id'],
                    'error' => null,
                ])->save();

                $published++;

                continue;
            }

            /*
             * Rechazada por Meta: queda en `failed` CON EL MOTIVO, no vuelve a
             * la bandeja. La diferencia importa -- una propuesta es algo que
             * nadie miro todavia, y esto es algo que alguien aprobo y que hay
             * que arreglar. Mezclarlas esconde el problema entre las ideas.
             *
             * No se reintenta sola. Casi todos los rechazos de Meta son de los
             * que no se arreglan esperando (token caducado, proporcion mala,
             * cupo diario), y reintentar cada quince minutos gastaria el cupo
             * en intentos que ya sabemos que fallan.
             */
            $post->forceFill([
                'status' => SocialPost::STATUS_FAILED,
                'error' => $resultado['error'],
            ])->save();

            $failed++;
        }

        return [
            'ready' => $ready,
            'published' => $published,
            'failed' => $failed,
            'returned' => $returned,
        ];
    }
}
