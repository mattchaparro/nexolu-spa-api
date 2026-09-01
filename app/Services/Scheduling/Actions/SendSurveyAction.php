<?php

namespace App\Services\Scheduling\Actions;

use App\Models\Message;
use App\Services\Messaging\MessageDispatcher;
use App\Services\Ratings\SurveyService;
use App\Support\Scheduling\StageActionCatalog;
use App\Support\Scheduling\StageMessage;

/**
 * Le manda al cliente el enlace de la encuesta cuando termina el servicio.
 *
 * Va como accion de etapa y no dentro del cobro, al reves que el sello de
 * fidelizacion, y la diferencia es deliberada: el sello es una regla del
 * negocio que tiene que cumplirse siempre, mientras que CUANDO preguntar
 * depende de como trabaja cada local. Hay quien quiere preguntar al terminar
 * y quien prefiere al dia siguiente; ponerlo en el flujo deja elegir.
 *
 * NO es critica. Si el mensaje no sale, el servicio igual queda cerrado:
 * negarse a completar una cita porque WhatsApp esta caido dejaria el mostrador
 * atascado por algo que no depende de nadie ahi.
 */
class SendSurveyAction implements StageAction
{
    public function __construct(
        private readonly MessageDispatcher $dispatcher,
        private readonly SurveyService $survey,
    ) {}

    public function type(): string
    {
        return StageActionCatalog::SEND_SURVEY;
    }

    public function execute(StageActionContext $context): StageActionResult
    {
        $appointment = $context->appointment;
        $business = $appointment->business;

        /*
         * Una garantia no se encuesta. La visita existe porque algo salio
         * mal; pedir estrellas ahi es preguntar por el clavo en la herida.
         */
        if ($appointment->items->every(fn ($item) => $item->is_warranty)) {
            return StageActionResult::skipped('Es una garantía: no se encuesta.');
        }

        if ($appointment->survey_answered_at !== null) {
            return StageActionResult::skipped('El cliente ya respondió esta encuesta.');
        }

        $phone = $appointment->client_phone ?? $appointment->client?->phone;

        if (! $phone) {
            return StageActionResult::skipped('El cliente no tiene teléfono registrado.');
        }

        /*
         * El token se crea ANTES de armar el mensaje: si se creara despues,
         * el enlace saldria vacio y la clienta recibiria una URL rota.
         */
        $this->survey->markSent($appointment);

        $body = StageMessage::render(
            (string) $context->config('template', StageMessage::defaultSurveyTemplate()),
            $appointment->fresh(['items.service', 'items.resource', 'business']),
            $context->stage,
        );

        $message = $this->dispatcher->queue(
            $business,
            Message::KIND_SURVEY,
            $phone,
            $body,
            $appointment,
        );

        if ($message === null) {
            return StageActionResult::skipped('Ya se le mandó la encuesta de esta cita.');
        }

        return match ($message->status) {
            Message::STATUS_SENT => StageActionResult::ok("Encuesta enviada a {$phone}."),
            Message::STATUS_MANUAL => StageActionResult::ok(
                "Encuesta lista para enviarle a {$phone}. Está en «Mensajes por enviar»."
            ),
            // El motivo, no un "falló" genérico: la diferencia entre un timeout
            // y un número inválido es la diferencia entre reintentar y
            // corregir la ficha.
            default => StageActionResult::failed($message->error ?? 'El canal rechazó el envío.'),
        };
    }
}
