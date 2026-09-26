<?php

namespace App\Jobs;

use App\Models\Client;
use App\Services\WhatsApp\ConnectContactSync;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Lleva a Connect una sola ficha, ya. Existe para quien deja de aceptar
 * promociones: no puede recibir la difusión de dentro de media hora por
 * esperar a la sincronización de cada hora.
 */
class SyncClientToConnectJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 300];

    public function __construct(public int $clientId) {}

    public function handle(ConnectContactSync $sync): void
    {
        $client = Client::withoutGlobalScope('business')->withTrashed()->find($this->clientId);

        if ($client !== null) {
            $sync->syncClient($client);
        }
    }
}
