<?php

namespace App\Console\Commands;

use App\Models\Business;
use App\Services\Social\PostDispatcher;
use Illuminate\Console\Command;

/**
 * Libera lo programado que ya cumplio su hora.
 *
 * Lo unico que hace es mover `scheduled` a `ready`, para que aparezca arriba
 * en la pantalla cuando toca. NO PUBLICA -- ver PostDispatcher para el
 * porque, que se resume en que la vitrina del negocio no es el lugar donde
 * estrenar automatizacion.
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
            $devueltas += $resultado['returned'];

            if ($resultado['ready'] > 0) {
                $this->line("{$business->name}: {$resultado['ready']} lista(s) para publicar.");
            }
        }

        /*
         * Las devueltas se cuentan aparte y no como error. Una publicacion
         * que llego a su hora sin foto es un problema real -- alguien borro
         * la imagen de la ficha -- pero no un fallo del comando, y mezclarlas
         * haria que la salida se vea roja el dia que no importa.
         */
        $this->info("Listas para publicar: {$listas}. Devueltas por falta de material: {$devueltas}.");

        return self::SUCCESS;
    }
}
