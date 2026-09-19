<?php

namespace App\Console\Commands;

use App\Models\Business;
use App\Services\WhatsApp\MenuDeServicios;
use Illuminate\Console\Command;

/**
 * Publica en Connect el menu de WhatsApp de cada negocio, sacado del
 * catalogo.
 *
 * Se corre despues de tocar servicios o precios. No es automatico en el
 * `saved()` del modelo a proposito: editar diez precios seguidos
 * republicaria el flujo diez veces, y el menu no necesita estar al
 * segundo -- necesita no mentir.
 */
class PublicarMenuServicios extends Command
{
    protected $signature = 'connect:menu
                            {--business= : Solo este negocio, por id}
                            {--dry-run : Muestra el menú sin publicarlo}';

    protected $description = 'Regenera el menú de servicios de WhatsApp desde el catálogo';

    public function handle(MenuDeServicios $menu): int
    {
        $negocios = Business::query()
            ->where('is_active', true)
            ->when($this->option('business'), fn ($q, $id) => $q->whereKey($id))
            ->get();

        $publicados = 0;

        foreach ($negocios as $business) {
            $definicion = $menu->definicion($business);

            if ($definicion === []) {
                $this->warn("{$business->name}: sin servicios reservables, no hay menú que publicar.");

                continue;
            }

            if ($this->option('dry-run')) {
                $this->line("== {$business->name} ==");
                foreach ($definicion['nodes']['categorias']['rows'] as $fila) {
                    $this->line("  • {$fila['title']}".(isset($fila['description']) ? " ({$fila['description']})" : ''));
                }

                continue;
            }

            if ($menu->publicar($business)) {
                $publicados++;
                $this->info("{$business->name}: menú publicado.");
            } else {
                $this->error("{$business->name}: no se pudo publicar (¿Connect configurado?).");
            }
        }

        if (! $this->option('dry-run')) {
            $this->info("Menús publicados: {$publicados}");
        }

        return self::SUCCESS;
    }
}
