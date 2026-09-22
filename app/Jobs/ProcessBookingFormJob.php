<?php

namespace App\Jobs;

use App\Ai\BookingForm;
use App\Models\WhatsappConversation;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Agendar lo que llegó en un formulario de WhatsApp, fuera del webhook.
 *
 * Mismo motivo que AnswerWhatsappMessageJob: la reserva escribe agenda y
 * manda la confirmación por el canal, y Connect espera un 200 rápido —
 * si el webhook tarda, reintenta, y reintentar aquí sería procesar el
 * mismo formulario dos veces. (La guarda de cita repetida lo atajaría,
 * pero mejor no llegar ahí.)
 */
class ProcessBookingFormJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    /** @var list<int> */
    public array $backoff = [5];

    /**
     * @param  array<string, mixed>  $respuesta
     */
    public function __construct(
        private readonly int $conversationId,
        private readonly array $respuesta,
    ) {}

    public function handle(BookingForm $form): void
    {
        $conversacion = WhatsappConversation::withoutGlobalScope('business')
            ->with('business', 'client')
            ->find($this->conversationId);

        if ($conversacion !== null) {
            $form->handle($conversacion, $this->respuesta);
        }
    }
}
