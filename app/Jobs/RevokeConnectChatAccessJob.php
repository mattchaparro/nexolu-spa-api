<?php

namespace App\Jobs;

use App\Services\WhatsApp\ConnectChat;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Le quita a una persona el chat de Connect y los avisos a su celular.
 *
 * En cola y con reintentos: desactivar a alguien en el Spa no puede fallar
 * porque Connect este caido, pero tampoco puede quedarse sin llegar -- a
 * esa persona le seguirian llegando al celular los mensajes de las
 * clientas.
 */
class RevokeConnectChatAccessJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [30, 120, 600, 1800];

    public function __construct(public int $userId) {}

    public function handle(ConnectChat $connect): void
    {
        $connect->revoke($this->userId);
    }
}
