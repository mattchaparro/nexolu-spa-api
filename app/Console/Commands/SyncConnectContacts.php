<?php

namespace App\Console\Commands;

use App\Models\Business;
use App\Services\WhatsApp\ConnectContactSync;
use Illuminate\Console\Command;

/**
 * Publica en Connect lo que el Spa sabe de sus clientas, para que las
 * difusiones de Connect puedan filtrar por eso. Ver ConnectContactSync.
 *
 * Cada hora: son dos o tres llamadas por salón, y así una visita cobrada
 * en la mañana ya cuenta para la difusión de la tarde. Quien deja de
 * aceptar promociones no espera a esta corrida (ver Client::booted).
 */
class SyncConnectContacts extends Command
{
    protected $signature = 'connect:sincronizar-clientas {--negocio= : Solo este negocio (id)}';

    protected $description = 'Publica en Connect los datos de las clientas que usan las difusiones';

    public function handle(ConnectContactSync $sync): int
    {
        $negocios = Business::query()
            ->when($this->option('negocio'), fn ($q, $id) => $q->whereKey($id))
            ->get();

        foreach ($negocios as $negocio) {
            try {
                $total = $sync->syncBusiness($negocio);
                $this->info(sprintf('%s: %d clientas publicadas.', $negocio->name, $total));
            } catch (\Throwable $e) {
                // Un salón que falla no deja sin sincronizar a los demás.
                report($e);
                $this->error(sprintf('%s: %s', $negocio->name, $e->getMessage()));
            }
        }

        return self::SUCCESS;
    }
}
