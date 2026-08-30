<?php

namespace App\Services\Scheduling\Actions;

use App\Services\Messaging\Contracts\MessagingChannel;
use App\Services\Ratings\SurveyService;
use App\Support\ChannelPhone;
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
        private readonly MessagingChannel $channel,
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

        if (! $this->channel->isConfigured()) {
            return StageActionResult::skipped('El canal de mensajería no está configurado.');
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

        $sent = $this->channel->sendText(
            ChannelPhone::normalize($phone, $business?->country_code ?? 'CO'),
            $body,
            $business?->id,
            'encuesta',
        );

        return $sent
            ? StageActionResult::ok("Encuesta enviada a {$phone}.")
            : StageActionResult::failed('El canal rechazó el envío.');
    }
}
