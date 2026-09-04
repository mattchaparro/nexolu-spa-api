<?php

namespace App\Console\Commands;

use App\Models\Business;
use App\Services\Social\PostPlanner;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Busca de que publicar.
 *
 * Mira la agenda, las fotos con permiso y el catalogo, y deja ideas en la
 * bandeja. No escribe ningun texto y no publica nada: lo que deja son
 * propuestas para que una persona elija.
 *
 * VA APARTE DE `publicaciones:despachar` aunque las dos toquen la misma
 * tabla. Proponer cuesta caro -- calcular los huecos de la semana es recorrer
 * el horario de cada persona del equipo, dia por dia -- y despachar cuesta
 * una consulta. Juntarlas obligaria a elegir entre correr lo caro cada quince
 * minutos o dejar una publicacion programada esperando media hora despues de
 * su hora.
 *
 * UNA VEZ AL DIA basta: las noticias que busca -- una foto de ayer, el hueco
 * del jueves -- no cambian en una hora, y proponer mas seguido solo llena una
 * bandeja que alguien tiene que vaciar a mano.
 */
class PlanSocialPosts extends Command
{
    protected $signature = 'publicaciones:proponer
                            {--business= : Solo este negocio, por id}';

    protected $description = 'Propone publicaciones a partir de la agenda, las fotos y el catálogo';

    public function handle(PostPlanner $planner): int
    {
        $propuestas = 0;
        $descartadas = 0;

        foreach ($this->businesses() as $business) {
            $resultado = $planner->run($business);

            $propuestas += $resultado['proposed'];
            $descartadas += $resultado['discarded'];

            if ($resultado['proposed'] > 0) {
                $this->line("{$business->name}: {$resultado['proposed']} idea(s).");
            }
        }

        $this->info("Ideas nuevas: {$propuestas}. Propuestas vencidas que se descartaron: {$descartadas}.");

        return self::SUCCESS;
    }

    /** @return Collection<int, Business> */
    private function businesses(): Collection
    {
        return Business::query()
            ->where('is_active', true)
            ->when($this->option('business'), fn ($q, $id) => $q->whereKey($id))
            ->get()
            ->filter(fn (Business $b) => $b->hasFeature('social_posts'));
    }
}
