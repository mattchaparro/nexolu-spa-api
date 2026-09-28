<?php

namespace App\Jobs;

use App\Models\WhatsappConversation;
use App\Services\WhatsApp\ConnectChat;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Le dice a Connect hasta cuándo está callado el bot con esta clienta.
 *
 * La pausa es de aquí, pero el chat es Connect: sin esto, en Connect no se
 * veía que el bot estaba en pausa ni había cómo reactivarlo (28-sep). Se
 * lee la conversación al correr: si se pausó y reactivó seguido, va el
 * último estado.
 */
class PushBotPauseToConnectJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 300];

    public function __construct(public int $conversationId) {}

    public function handle(ConnectChat $connect): void
    {
        $conversacion = WhatsappConversation::withoutGlobalScope('business')->find($this->conversationId);

        if ($conversacion !== null) {
            $connect->setBotPause($conversacion);
        }
    }
}
