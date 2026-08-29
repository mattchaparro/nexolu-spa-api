<?php

namespace App\Console\Commands;

use App\Support\Scheduling\DefaultWorkflow;
use Illuminate\Console\Command;

class SyncWorkflows extends Command
{
    protected $signature = 'workflows:sync';

    protected $description = 'Crea o actualiza el flujo de etapas por defecto para las citas.';

    public function handle(): int
    {
        $workflow = DefaultWorkflow::sync();

        $this->info("Flujo «{$workflow->name}» con {$workflow->stages->count()} etapas.");

        return self::SUCCESS;
    }
}
