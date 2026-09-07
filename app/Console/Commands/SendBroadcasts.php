<?php

namespace App\Console\Commands;

use App\Models\Broadcast;
use App\Services\Messaging\BroadcastService;
use Illuminate\Console\Command;

/**
 * Despacha las difusiones cuya hora llego.
 *
 * Corre cada cinco minutos. Programar algo "a las 10:00" y que salga 10:04
 * es aceptable; que salga a las 11 no lo es -- una promocion de almuerzo
 * mandada a media tarde ya no es una promocion.
 */
class SendBroadcasts extends Command
{
    protected $signature = 'difusiones:enviar {--dry-run : Solo dice cuales saldrian}';

    protected $description = 'Envía las difusiones programadas cuya hora ya pasó';

    public function handle(BroadcastService $broadcasts): int
    {
        $pendientes = $broadcasts->due();

        if ($pendientes->isEmpty()) {
            $this->info('Nada programado por ahora.');

            return self::SUCCESS;
        }

        foreach ($pendientes as $difusion) {
            /** @var Broadcast $difusion */
            if ($this->option('dry-run')) {
                $this->line(sprintf(
                    '[%s] "%s" saldría a %d destinatarias.',
                    $difusion->business->name,
                    $difusion->name,
                    $broadcasts->audienceCount($difusion),
                ));

                continue;
            }

            $enviados = $broadcasts->dispatch($difusion);

            $this->info(sprintf('"%s": %d mensajes preparados.', $difusion->name, $enviados));
        }

        return self::SUCCESS;
    }
}
