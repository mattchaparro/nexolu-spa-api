<?php

namespace App\Services\Social;

use App\Models\Business;
use App\Models\SocialPost;
use Carbon\CarbonImmutable;

/**
 * El reloj de las publicaciones.
 *
 * MUEVE `scheduled` A `ready` Y AHI SE DETIENE. No publica. Es la decision
 * mas importante del modulo y conviene decir por que, porque la tentacion de
 * cerrar el ciclo es enorme y el codigo para hacerlo cabria en veinte lineas:
 *
 * - La cuenta de Instagram de un spa de barrio ES el negocio. No es un canal
 *   mas: es donde la clienta nueva decide si entra. Un texto raro publicado a
 *   las tres de la manana sin que nadie lo mirara cuesta mas que las diez
 *   publicaciones que no salieron.
 *
 * - El texto lo escribio un modelo. Aprobarlo el lunes no es lo mismo que
 *   verlo publicado el jueves: entre las dos cosas el negocio cambio de
 *   promocion, se enfermo la manicurista y el hueco del jueves se lleno.
 *
 * - Y la parte aburrida: publicar en nombre de otro en Meta exige una app
 *   revisada y un token que hay que rotar. Construir eso para que el primer
 *   negocio lo estrene contra su propia vitrina no es un buen primer uso.
 *
 * Asi que la ultima tecla la toca una persona, desde el panel, con el texto y
 * la foto adelante. Lo que este reloj hace es que esa persona no tenga que
 * acordarse: a la hora que se eligio, la publicacion aparece en "listas para
 * publicar" y deja de estar en el futuro.
 */
class PostDispatcher
{
    /**
     * @return array{ready: int, returned: int}
     */
    public function run(Business $business, ?CarbonImmutable $now = null): array
    {
        if (! $business->hasFeature('social_posts')) {
            return ['ready' => 0, 'returned' => 0];
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
            ->with(['clientPhoto'])
            ->get();

        $ready = 0;
        $returned = 0;

        foreach ($due as $post) {
            /*
             * Se programo completa -- el controlador no deja programar otra
             * cosa -- pero entre esa hora y esta pudieron borrar la foto de
             * la ficha. Vuelve a propuesta CON el motivo escrito: dejarla en
             * "programada" seria esconderla en un estado que nadie revisa.
             */
            if (! $post->isComplete()) {
                $post->forceFill([
                    'status' => SocialPost::STATUS_DRAFT,
                    'error' => 'Le llegó la hora sin foto o sin texto. Se devolvió a propuestas.',
                ])->save();

                $returned++;

                continue;
            }

            $post->forceFill([
                'status' => SocialPost::STATUS_READY,
                'error' => null,
            ])->save();

            $ready++;
        }

        return ['ready' => $ready, 'returned' => $returned];
    }
}
