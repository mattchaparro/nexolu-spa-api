<?php

namespace App\Services\Scheduling\Actions;

use App\Models\Business;
use App\Services\Messaging\Contracts\MessagingChannel;
use App\Support\ChannelPhone;
use App\Support\Scheduling\StageActionCatalog;
use App\Support\Scheduling\StageMessage;

/**
 * Le avisa a la clienta que su cita cambio.
 *
 * NO es critica: si el mensaje no sale, la cita igual queda marcada. Negarse a
 * confirmar una cita porque WhatsApp esta caido dejaria el mostrador atascado
 * por algo que no depende de nadie ahi.
 */
class NotifyClientAction implements StageAction
{
    public function __construct(private readonly MessagingChannel $channel) {}

    public function type(): string
    {
        return StageActionCatalog::NOTIFY_CLIENT;
    }

    public function execute(StageActionContext $context): StageActionResult
    {
        $appointment = $context->appointment;
        $business = $appointment->business;

        if (! $this->channel->isConfigured()) {
            return StageActionResult::skipped('El canal de mensajería no está configurado.');
        }

        $phone = $appointment->client_phone ?? $appointment->client?->phone;

        if (! $phone) {
            // Pasa todo el tiempo: la clienta se agendo por telefono y nadie
            // anoto el numero. No es una falla.
            return StageActionResult::skipped('La clienta no tiene teléfono registrado.');
        }

        $body = StageMessage::render(
            (string) $context->config('template', ''),
            $appointment,
            $context->stage,
        );

        $sent = $this->channel->sendText(
            ChannelPhone::normalize($phone, $business?->country_code ?? 'CO'),
            $body,
            $business?->id,
            'cita_'.$context->stage->key,
        );

        return $sent
            ? StageActionResult::ok("Mensaje enviado a {$phone}.")
            : StageActionResult::failed('El canal rechazó el envío.');
    }
}
