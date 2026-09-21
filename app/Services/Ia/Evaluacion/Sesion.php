<?php

namespace App\Services\Ia\Evaluacion;

use App\Models\Client;
use App\Models\WhatsappConversation;
use Carbon\CarbonInterface;

/**
 * Lo que `Banco::preparar` dejó listo, y lo que `Banco::limpiar` necesita
 * para deshacerlo: la ficha, la conversación, cómo estaba antes y desde
 * cuándo es nuestro lo que aparezca.
 */
final class Sesion
{
    /** @param array<string, mixed>|null $comoEstaba null si la conversación no existía */
    public function __construct(
        public readonly Client $cliente,
        public readonly WhatsappConversation $conversacion,
        public readonly ?array $comoEstaba,
        public readonly CarbonInterface $desde,
    ) {}
}
