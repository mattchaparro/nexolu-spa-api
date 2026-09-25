<?php

namespace App\Jobs;

use App\Models\Client;
use App\Services\WhatsApp\ConnectChat;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Lleva a Connect el nombre de una clienta que cambió acá.
 *
 * En cola: guardar una ficha no puede esperar a Connect ni fallar si está
 * caído. Se lee la clienta al correr, no al encolar: si el nombre cambió
 * dos veces seguidas, va el último.
 */
class PushClientNameToConnectJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 300];

    public function __construct(public int $clientId) {}

    public function handle(ConnectChat $connect): void
    {
        $client = Client::withoutGlobalScope('business')->find($this->clientId);

        if ($client !== null) {
            $connect->renameContact($client);
        }
    }
}
