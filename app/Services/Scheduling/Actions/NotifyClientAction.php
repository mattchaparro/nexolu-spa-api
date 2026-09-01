<?php

namespace App\Services\Scheduling\Actions;

use App\Models\Message;
use App\Services\Messaging\MessageDispatcher;
use App\Support\Scheduling\StageActionCatalog;
use App\Support\Scheduling\StageMessage;

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
        );

        if ($message === null) {
            // Repetido: ya hay un aviso de etapa para esta cita. Volver a
            // moverla de etapa no le manda un segundo mensaje al cliente.
            return StageActionResult::skipped('Ya se le avisó al cliente de esta cita.');
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
}
