<?php

namespace App\Services\Scheduling\Actions;

use App\Ai\AiCaller;
use App\Ai\EnvioDirecto;
use App\Ai\InfoPostCita;
use App\Models\Appointment;
use App\Models\Business;
use App\Models\Message;
use App\Services\Loyalty\LoyaltyService;
use App\Services\Messaging\MessageDispatcher;
use App\Support\Scheduling\ConfirmationMessage;
use App\Support\Scheduling\StageActionCatalog;
use App\Support\Scheduling\StageMessage;
use App\Support\Scheduling\ThankYouMessage;

/**
 * Le avisa al cliente que su cita cambio.
 *
 * NO es critica: si el mensaje no sale, la cita igual queda marcada. Negarse a
 * confirmar una cita porque WhatsApp esta caido dejaria el mostrador atascado
 * por algo que no depende de nadie ahi.
 */
class NotifyClientAction implements StageAction
{
    public function __construct(private readonly MessageDispatcher $dispatcher) {}

    public function type(): string
    {
        return StageActionCatalog::NOTIFY_CLIENT;
    }

    public function execute(StageActionContext $context): StageActionResult
    {
        $appointment = $context->appointment;
        $business = $appointment->business;

        $phone = $appointment->client_phone ?? $appointment->client?->phone;

        if (! $phone) {
            // Pasa todo el tiempo: el cliente se agendo por telefono y nadie
            // anoto el numero. No es una falla.
            return StageActionResult::skipped('El cliente no tiene teléfono registrado.');
        }

        /*
         * Ya NO se pregunta si el canal esta configurado.
         *
         * Antes, sin canal, esto no hacia nada: el aviso se perdia y el negocio
         * ni se enteraba de que existia. Ahora el mensaje se guarda igual y
         * aparece en "Mensajes por enviar" para que alguien lo mande a mano.
         * El canal decide COMO sale, no SI existe.
         */
        $confirma = $context->stage?->maps_to_status === Appointment::STATUS_CONFIRMED;
        $termina = $context->stage?->maps_to_status === Appointment::STATUS_COMPLETED;

        $message = $this->dispatcher->queue(
            $business,
            Message::KIND_STAGE,
            $phone,
            StageMessage::render(
                (string) $context->config('template', ''),
                $appointment,
                $context->stage,
            ),
            $appointment,
            null,
            /*
             * La confirmación viaja TAMBIÉN como plantilla.
             *
             * Es el único aviso que el salón manda sin que la clienta haya
             * escrito: se confirma la agenda del día siguiente, o se agenda
             * a alguien que no escribe hace un mes. Fuera de las 24 horas
             * Meta acepta el texto libre y NO lo entrega --sin error, sin
             * rebote-- así que ese mensaje simplemente no existía para ella.
             *
             * El texto sigue yendo: es el que sale dentro de la ventana, el
             * que se ve en la bandeja y el que se manda a mano. Cuál de los
             * dos usa lo decide MessageDispatcher al enviar, que es el único
             * que sabe si la ventana está abierta.
             */
            match (true) {
                $confirma => ConfirmationMessage::template($appointment),
                // Null si el negocio no tiene programa de sellos: la
                // plantilla los nombra en renglones fijos (ver
                // ThankYouMessage::template).
                $termina => ThankYouMessage::template($appointment),
                default => null,
            },
        );

        if ($message === null) {
            // Repetido: ya hay un aviso de etapa para esta cita. Volver a
            // moverla de etapa no le manda un segundo mensaje al cliente.
            return StageActionResult::skipped('Ya se le avisó al cliente de esta cita.');
        }

        /*
         * Y, detrás de la confirmación, lo que el negocio tenga escrito para
         * después de la cita: garantías, recomendaciones, cancelaciones.
         *
         * Es lo mismo que hace el bot cuando la clienta agenda sola, y lo
         * que ella ya conoce de ManyChat. Solo con la ventana abierta: fuera
         * de ella esos botones viajan DENTRO de la plantilla, porque un
         * segundo mensaje de texto no se entregaría.
         */
        if ($confirma && $message->status === Message::STATUS_SENT) {
            $this->ofrecerInfo($business, $appointment, $phone);
        }

        /*
         * Y al terminar, los dos botones del final: calificar y ver la
         * tarjeta. Igual que en ManyChat, van en un segundo mensaje --con la
         * ventana abierta--; fuera de ella viajan dentro de la plantilla.
         */
        if ($termina && $message->status === Message::STATUS_SENT) {
            $this->ofrecerCierre($business, $appointment, $phone);
        }

        return match ($message->status) {
            Message::STATUS_SENT => StageActionResult::ok("Mensaje enviado a {$phone}."),
            Message::STATUS_MANUAL => StageActionResult::ok(
                "Mensaje listo para enviarle a {$phone}. Está en «Mensajes por enviar»."
            ),
            // El motivo, no un "falló" genérico: la diferencia entre un timeout
            // y un número inválido es la diferencia entre reintentar y
            // corregir la ficha.
            default => StageActionResult::failed($message->error ?? 'El canal rechazó el envío.'),
        };
    }

    /**
     * «Calificar servicio» y «Mi tarjeta», detrás del gracias.
     *
     * La opinión primero: es lo que el salón necesita y lo que se pide en
     * caliente, cuando acaba de ver sus uñas. La tarjeta es el anzuelo para
     * volver y aguanta el segundo lugar.
     */
    private function ofrecerCierre(Business $business, Appointment $appointment, string $phone): void
    {
        if (! $this->dispatcher->windowIsOpenFor($business, $phone)) {
            return;
        }

        $opciones = [['id' => 'calificar', 'title' => ThankYouMessage::RATE]];

        // La tarjeta solo si el negocio tiene programa: un botón que
        // contesta "acá no hay sellos" es peor que no estar.
        if ($business->hasFeature('loyalty') && app(LoyaltyService::class)->activeProgram($business) !== null) {
            $opciones[] = ['id' => 'tarjeta', 'title' => ThankYouMessage::MY_CARD];
        }

        try {
            app(EnvioDirecto::class)->opciones(
                AiCaller::customer($business, $phone, $appointment->client, 'whatsapp'),
                'Nos encantaría conocer tu opinión sobre el servicio que recibiste 😊 ¡Ayúdanos a mejorar! 🌟',
                $opciones,
            );
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * Los botones de información, detrás de la confirmación.
     *
     * Mejor esfuerzo y en silencio: la cita ya quedó confirmada y ella ya lo
     * sabe. Que el negocio no haya escrito nada --o que el Core no responda--
     * no puede volver roja una transición que salió bien.
     */
    private function ofrecerInfo(Business $business, Appointment $appointment, string $phone): void
    {
        if (! $this->dispatcher->windowIsOpenFor($business, $phone)) {
            return;
        }

        try {
            app(InfoPostCita::class)->ofrecer(
                AiCaller::customer($business, $phone, $appointment->client, 'whatsapp'),
                $phone,
            );
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
