<?php

namespace App\Console\Commands;

use App\Support\Scheduling\DefaultWorkflow;
use App\Support\Scheduling\FlujoSinConfirmacion;
use Illuminate\Console\Command;

class SyncWorkflows extends Command
{
    protected $signature = 'workflows:sync';

    protected $description = 'Crea o actualiza los flujos de etapas que la plataforma ofrece.';

    /**
     * Los flujos que vienen de fabrica.
     *
     * Ninguno se le aplica solo a un negocio existente: esto los deja
     * DISPONIBLES, y quien administra elige cual rige desde el panel. El unico
     * automatico es el estandar, que un negocio nuevo recibe al crearse.
     */
    private const FLUJOS = [
        DefaultWorkflow::class,
        FlujoSinConfirmacion::class,
    ];

    public function handle(): int
    {
        foreach (self::FLUJOS as $flujo) {
            $workflow = $flujo::sync();

            $this->info("Flujo «{$workflow->name}» con {$workflow->stages->count()} etapas.");
        }

        return self::SUCCESS;
    }
}
