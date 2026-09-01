<?php

namespace App\Jobs;

use App\Models\Message;
use App\Services\Messaging\MessageDispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Manda un mensaje que ya existe en la bandeja.
 *
 * POR QUE LA FILA SE CREA ANTES Y NO AQUI DENTRO: un mensaje que solo existe
 * mientras el job corre desaparece si la cola se cae, y nadie sabe que se
 * queria mandar. Aca solo se INTENTA el envio de algo que ya esta guardado.
 *
 * REINTENTOS. Tres, con espera creciente. Casi todo lo que falla al mandar un
 * mensaje es temporal -- un timeout, el proveedor saturado -- y reintentar de
 * inmediato contra un servicio caido solo gasta los tres intentos en cinco
 * segundos. Un numero invalido, en cambio, va a fallar las tres veces: para eso
 * esta el motivo guardado, para que una persona lo lea y corrija la ficha en
 * vez de esperar que la maquina adivine.
 */
class SendMessageJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** 1, 5 y 15 minutos. Un proveedor saturado no se recupera en 10 segundos. */
    public array $backoff = [60, 300, 900];

    public function __construct(public readonly int $messageId) {}

    public function handle(MessageDispatcher $dispatcher): void
    {
        $message = Message::withoutGlobalScopes()->find($this->messageId);

        /*
         * Descartado mientras esperaba en la cola, o ya mandado a mano.
         *
         * Las dos cosas pasan: alguien ve el mensaje en la bandeja, lo manda
         * desde su teléfono y lo marca, o lo descarta porque ya no aplica.
         * Volver a mandarlo seria el segundo mensaje que la clienta no
         * entiende.
         */
        if ($message === null || $message->status === Message::STATUS_SENT) {
            return;
        }

        if ($dispatcher->send($message)) {
            return;
        }

        /*
         * `send()` ya dejo el motivo guardado y la fila marcada como fallida.
         *
         * Que HAYA otro intento necesita las dos cosas: que queden intentos y
         * que la cola de verdad reintente. Con la conexion `sync` -- pruebas,
         * y una instalacion sin worker -- el job corre en el acto y `release()`
         * no vuelve a encolarlo: dejar el mensaje en "pendiente" ahi lo
         * escondería para siempre, porque la bandeja muestra por defecto lo
         * manual y lo fallido.
         */
        $reintentara = $this->attempts() < $this->tries
            && $this->job?->getConnectionName() !== 'sync';

        if ($reintentara) {
            /*
             * Vuelve a "en cola", no se queda en "fallido".
             *
             * Decir "falló" mientras todavia hay un reintento en camino invita
             * a que alguien lo mande a mano justo antes de que salga solo, y
             * la clienta recibe el mismo mensaje dos veces. El motivo del
             * fallo se conserva -- sirve para diagnosticar -- pero el estado
             * dice la verdad: todavia no termino.
             */
            $message->forceFill(['status' => Message::STATUS_PENDING])->save();

            $this->release($this->backoff[$this->attempts() - 1] ?? 900);

            return;
        }

        /*
         * Se acabaron los intentos. Queda `fallido` con su motivo, que es lo
         * que la bandeja muestra para que una persona decida: reintentar,
         * mandarlo a mano, o corregir el telefono de la ficha.
         */
        $this->fail($message->error ?? 'El canal rechazó el envío.');
    }
}
