<?php

namespace App\Jobs;

use App\Ai\AiCaller;
use App\Ai\EnvioDirecto;
use App\Ai\SurveyForm;
use App\Models\WhatsappConversation;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Guardar la encuesta que llegó por formulario y darle las gracias.
 *
 * Fuera del webhook por lo mismo que ProcessBookingFormJob: Connect espera
 * un 200 rápido, y un reintento suyo no puede duplicar el gracias.
 */
class ProcessSurveyFormJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    /** @var list<int> */
    public array $backoff = [5];

    /** @param  array<string, mixed>  $respuesta */
    public function __construct(
        private readonly int $conversationId,
        private readonly array $respuesta,
    ) {}

    public function handle(SurveyForm $form): void
    {
        $conversacion = WhatsappConversation::withoutGlobalScope('business')
            ->with('business', 'client')
            ->find($this->conversationId);

        if ($conversacion === null) {
            return;
        }

        $gracias = $form->handle($conversacion, $this->respuesta);

        if ($gracias === null) {
            return;
        }

        app(EnvioDirecto::class)->texto(
            AiCaller::customer($conversacion->business, $conversacion->phone, $conversacion->client, 'whatsapp'),
            $gracias,
        );
    }
}
