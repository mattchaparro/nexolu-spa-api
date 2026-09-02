<?php

namespace App\Services\Messaging;

use App\Jobs\SendMessageJob;
use App\Models\Appointment;
use App\Models\Business;
use App\Models\Client;
use App\Models\Message;
use App\Services\Messaging\Contracts\MessagingChannel;
use App\Support\ChannelPhone;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;

/**
 * El unico punto por el que sale un mensaje.
 *
 * Todo lo que quiera avisarle algo a alguien pasa por aca: las acciones de
 * etapa, la encuesta, los recordatorios. Que sea uno solo es lo que permite
 * que la bandeja de salida este completa -- un camino que escriba directo al
 * canal es un mensaje que no aparece en la pantalla ni se puede reintentar.
 *
 * DOS DECISIONES QUE NO SON OBVIAS:
 *
 * 1. LA FILA SE CREA ANTES DE INTENTAR EL ENVIO, y sobrevive al fallo. Un
 *    mensaje que solo existe cuando el envio funciona no se puede explicar ni
 *    reintentar. Es lo contrario de lo que hacia Blue Souls, donde los datos
 *    vivian en el log de Laravel y hubo que escribir un comando para
 *    recuperarlos parseandolo.
 *
 * 2. EL MODO MANUAL NO ES UN LIMBO. `pendiente_manual` significa "nadie va a
 *    mandar esto solo, lo copia una persona", y es el modo por defecto. Un spa
 *    opera sus primeras semanas asi de todas formas, y encender el envio
 *    automatico sin que lo pidan seria mandarle mensajes a sus clientas a su
 *    nombre sin avisarle.
 */
class MessageDispatcher
{
    public function __construct(private readonly MessagingChannel $channel) {}

    /**
     * Deja un mensaje listo para salir, y lo manda si corresponde.
     *
     * Devuelve null cuando no hay nada que mandar -- sin telefono, o repetido.
     * No es un error: que una cita no tenga telefono es normal, y que el
     * recordatorio ya se haya creado es exactamente lo que se quiere.
     */
    public function queue(
        Business $business,
        string $kind,
        ?string $to,
        string $body,
        ?Appointment $appointment = null,
        ?Client $client = null,
        ?MessageTemplate $template = null,
    ): ?Message {
        $phone = $to === null ? null : ChannelPhone::normalize($to, $business->country_code);

        if ($phone === null || trim($body) === '') {
            return null;
        }

        try {
            $message = Message::create([
                'business_id' => $business->id,
                'location_id' => $appointment?->location_id,
                'kind' => $kind,
                'to' => $phone,
                'client_id' => $client?->id ?? $appointment?->client_id,
                'appointment_id' => $appointment?->id,
                /*
                 * El texto y la plantilla conviven a proposito.
                 *
                 * `body` es lo que una persona manda desde su propio WhatsApp
                 * en modo manual, y lo que se lee en la bandeja de salida. La
                 * plantilla es como sale SOLO. No se puede tener solo el
                 * texto: de "Hola Carolina, tu cita del jueves..." no se
                 * sacan de vuelta las variables sin adivinar.
                 */
                'body' => $body,
                'template_name' => $template?->name,
                'template_language' => $template?->language,
                'template_params' => $template?->params,
                /*
                 * El modo decide el estado, y el estado decide quien lo manda.
                 * Sin canal configurado tampoco se promete un envio: quedaria
                 * en cola para siempre y la pantalla diria "en cola" de algo
                 * que nadie va a mover.
                 */
                'status' => $this->sendsByItself($business)
                    ? Message::STATUS_PENDING
                    : Message::STATUS_MANUAL,
            ]);
        } catch (UniqueConstraintViolationException) {
            /*
             * Ya existe uno de ese tipo para esa cita.
             *
             * Se traga a proposito: es el caso normal cuando el comando de
             * recordatorios corre dos veces, o cuando alguien mueve una cita
             * de etapa y vuelve. La restriccion es la que garantiza que no se
             * mande dos veces -- no un contador, que se desincroniza.
             */
            return null;
        }

        if ($message->status === Message::STATUS_PENDING) {
            /*
             * A la COLA, no aca mismo.
             *
             * Lo que dispara esto casi siempre es alguien esperando en el
             * mostrador: mover una cita de etapa, cobrar, marcar una
             * inasistencia. Hablar con el proveedor de mensajeria ahi dentro
             * le suma al mostrador la latencia de una llamada HTTP a un
             * servicio que no controlamos, y con un timeout de treinta
             * segundos eso es la pantalla congelada mientras la clienta mira.
             *
             * Con la conexion `sync` -- pruebas, y una instalacion sin worker
             * -- el job corre en el acto y el comportamiento es el de antes.
             * Por eso esto no rompe nada si el worker todavia no existe.
             */
            SendMessageJob::dispatch($message->id);
        }

        return $message->fresh();
    }

    /**
     * Intenta el envio de un mensaje que ya existe.
     *
     * Sincrono a proposito por ahora: no hay worker de colas corriendo en el
     * servidor, y un job encolado que nadie procesa se ve igual que un mensaje
     * perdido. Cuando exista el worker, esto se mueve a un job y lo unico que
     * cambia es esta linea.
     */
    public function send(Message $message): bool
    {
        $ok = false;
        $error = null;

        try {
            /*
             * Plantilla o texto libre, y no es una preferencia de estilo:
             * WhatsApp solo entrega texto libre dentro de las 24 horas
             * siguientes a que la clienta escribio. Un recordatorio de la cita
             * de manana llega mucho despues de eso, asi que mandarlo como
             * texto es que Meta lo rechace -- silenciosamente, salvo por el
             * motivo que queda en la bandeja.
             */
            $ok = $message->usesTemplate()
                ? $this->channel->sendTemplate(
                    $message->to,
                    (string) $message->template_name,
                    (string) ($message->template_language ?? 'es'),
                    $this->bodyComponents($message->template_params ?? []),
                    $message->business_id,
                    $message->kind,
                )
                : $this->channel->sendText(
                    $message->to,
                    $message->body,
                    $message->business_id,
                    $message->kind,
                );

            if (! $ok) {
                $error = 'El canal rechazó el envío.';
            }
        } catch (\Throwable $e) {
            /*
             * Un canal caido no puede tumbar lo que lo disparo: mover una cita
             * de etapa tiene que funcionar aunque WhatsApp este fuera.
             *
             * Pero el MOTIVO se guarda. Tragarselo deja a quien administra con
             * un "falló" que no se puede accionar -- y la diferencia entre un
             * timeout y un numero invalido es la diferencia entre reintentar y
             * corregir la ficha.
             */
            $error = mb_substr($e->getMessage(), 0, 500);

            Log::warning('Fallo el envio de un mensaje', [
                'message_id' => $message->id,
                'error' => $e->getMessage(),
            ]);
        }

        $message->forceFill($ok ? [
            'status' => Message::STATUS_SENT,
            'sent_at' => now(),
            'attempts' => $message->attempts + 1,
            'error' => null,
        ] : [
            'status' => Message::STATUS_FAILED,
            'failed_at' => now(),
            'attempts' => $message->attempts + 1,
            'error' => $error,
        ])->save();

        return $ok;
    }

    /**
     * Marcar a mano lo que una persona ya mando por su cuenta.
     *
     * Es el cierre del modo manual: sin esto la lista crece para siempre y deja
     * de servir, porque nadie distingue lo que falta de lo que ya se hizo.
     */
    public function markSentByHand(Message $message, int $userId): Message
    {
        $message->forceFill([
            'status' => Message::STATUS_SENT,
            'sent_at' => now(),
            'sent_by_user_id' => $userId,
        ])->save();

        return $message->fresh();
    }

    /**
     * Si a este negocio le salen los mensajes solos.
     *
     * Hacen falta las DOS cosas: que lo haya pedido y que haya con que. Un
     * negocio en automatico sin canal configurado no manda nada, y prometerlo
     * dejaria mensajes "en cola" que nadie va a mover.
     */
    public function sendsByItself(Business $business): bool
    {
        return $business->messaging_mode === 'auto' && $this->channel->isConfigured();
    }

    /**
     * Las variables, en la forma que espera la Cloud API.
     *
     * @param  list<string>  $params
     * @return list<array<string, mixed>>
     */
    private function bodyComponents(array $params): array
    {
        if ($params === []) {
            return [];
        }

        return [[
            'type' => 'body',
            'parameters' => array_map(
                fn ($valor) => ['type' => 'text', 'text' => (string) $valor],
                $params,
            ),
        ]];
    }
}
