<?php

namespace App\Console\Commands;

use App\Models\Business;
use App\Services\Social\PostDispatcher;
use Illuminate\Console\Command;

/**
 * Libera lo programado que ya cumplio su hora.
 *
 * Que hace con ello depende de si el negocio conecto su cuenta de Instagram:
 * con cuenta la publica, sin cuenta la deja en "lista para publicar" y espera
 * a que alguien la pegue. En los dos casos, el texto ya lo aprobo una persona
 * -- programar ES aprobar. Ver PostDispatcher.
 *
 * Cada quince minutos, y sobre una ventana abierta: toma lo que ya paso su
 * hora, no lo que la cumple justo ahora. Una corrida perdida se recupera sola
 * en la siguiente en vez de dejar una publicacion esperando para siempre una
 * hora que ya paso. Mismo criterio que los recordatorios.
 */
class ReleaseSocialPosts extends Command
{
    protected $signature = 'publicaciones:despachar
                            {--business= : Solo este negocio, por id}';

    protected $description = 'Marca como listas las publicaciones programadas que ya cumplieron su hora';

    public function handle(PostDispatcher $dispatcher): int
    {
        $listas = 0;
        $publicadas = 0;
        $fallidas = 0;
        $devueltas = 0;

        $negocios = Business::query()
            ->where('is_active', true)
            ->when($this->option('business'), fn ($q, $id) => $q->whereKey($id))
            ->get();

        foreach ($negocios as $business) {
            if (! $business->hasFeature('social_posts')) {
                continue;
            }

            $resultado = $dispatcher->run($business);

            $listas += $resultado['ready'];
            $publicadas += $resultado['published'];
            $fallidas += $resultado['failed'];
            $devueltas += $resultado['returned'];

            if ($resultado['ready'] > 0 || $resultado['published'] > 0) {
                $this->line(sprintf(
                    '%s: %d lista(s) para publicar, %d publicada(s).',
                    $business->name,
                    $resultado['ready'],
                    $resultado['published'],
                ));
            }

            /*
             * Las rechazadas por Meta se nombran una por una. Un contador no
             * sirve: lo que hace falta saber es CUAL negocio y POR QUE, que
             * casi siempre es un token caducado y se arregla reconectando.
             */
            if ($resultado['failed'] > 0) {
                $this->warn("{$business->name}: {$resultado['failed']} rechazada(s) por Instagram.");
            }
        }

        /*
         * Las devueltas se cuentan aparte y no como error. Una publicacion
         * que llego a su hora sin foto es un problema real -- alguien borro
         * la imagen de la ficha -- pero no un fallo del comando, y mezclarlas
         * haria que la salida se vea roja el dia que no importa.
         */
        $this->info(sprintf(
            'Listas para publicar: %d. Publicadas: %d. Rechazadas: %d. Devueltas por falta de material: %d.',
            $listas,
            $publicadas,
            $fallidas,
            $devueltas,
        ));

        return self::SUCCESS;
    }
}
