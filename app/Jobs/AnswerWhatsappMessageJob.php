<?php

namespace App\Jobs;

use App\Models\Message;
use App\Models\WhatsappConversation;
use App\Services\Ia\IaCoreClient;
use App\Services\Messaging\MessageDispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Pensar la respuesta del agente, FUERA de la petición del webhook.
 *
 * No es una optimización, son dos problemas distintos que solo se arreglan
 * así:
 *
 * 1. EL ABRAZO MORTAL. Preguntarle al Core es una llamada HTTP que el Core
 *    devuelve con OTRA llamada HTTP a esta misma API (`/api/ai/tools/invoke`,
 *    para consultar disponibilidad o agendar). Si el proceso web esta
 *    bloqueado esperando al Core, no puede atender esa vuelta: se traban
 *    mutuamente hasta que algo expira. Con el trabajo en la cola, el proceso
 *    web queda libre para servir la herramienta mientras el worker espera.
 *
 * 2. EL REINTENTO DUPLICADO. Communications espera un 200 rapido; si tarda,
 *    reintenta el MISMO evento -- y reintentar aca es volver a escribirle a
 *    la clienta. Contestar de inmediato y pensar despues cierra esa puerta.
 *
 * Un modelo mas una o dos vueltas de herramienta se van facil a mas de 30
 * segundos, que es justo el tope de ejecucion de PHP en una petición web.
 */
class AnswerWhatsappMessageJob implements ShouldQueue
{
    use Queueable;

    /**
     * Dos intentos, no tres.
     *
     * Al otro lado hay una persona mirando el chat: un reintento tardio deja
     * de ser util y empieza a ser raro -- una respuesta a algo que ya dejo de
     * preguntar hace cinco minutos.
     */
    public int $tries = 2;

    /** @var list<int> */
    public array $backoff = [10];

    public function __construct(
        private readonly int $conversationId,
        private readonly string $text,
    ) {}

    public function handle(
        IaCoreClient $ia,
        MessageDispatcher $dispatcher,
    ): void {
        $conversacion = WhatsappConversation::withoutGlobalScope('business')
            ->with('business', 'client')
            ->find($this->conversationId);

        if ($conversacion === null) {
            return;
        }

        $respuesta = $ia->ask($conversacion, $this->text);

        if ($respuesta === null) {
            /*
             * Sin respuesta no se inventa una. Un "disculpa, no entendi"
             * automatico ante una caida del Core le enseña a la clienta que
             * el bot no sirve; el silencio deja que una persona conteste.
             */
            Log::warning('agente: el Core no respondio', [
                'conversation_id' => $conversacion->id,
            ]);

            return;
        }

        if ($respuesta['conversation_id'] !== null) {
            $conversacion->update(['ia_conversation_id' => $respuesta['conversation_id']]);
        }

        $dispatcher->queue(
            $conversacion->business,
            Message::KIND_AGENT,
            $conversacion->phone,
            $respuesta['text'],
            null,
            $conversacion->client,
            null,
            null,
            // Para que la respuesta quede en el hilo y no suelta en el outbox.
            $conversacion,
        );
    }
}
