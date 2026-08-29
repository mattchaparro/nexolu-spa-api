<?php

namespace App\Services\Scheduling\Actions;

use App\Services\Messaging\Contracts\MessagingChannel;
use App\Support\ChannelPhone;
use App\Support\Scheduling\StageActionCatalog;
use App\Support\Scheduling\StageMessage;

/**
 * Le avisa a la profesional que atiende.
 *
 * Al primer item de la cita: en una cita encadenada el resto se entera cuando
 * le toque, y mandarle el mismo mensaje a tres personas por una sola cita es
 * como se logra que nadie los lea.
 */
class NotifyStaffAction implements StageAction
{
    public function __construct(private readonly MessagingChannel $channel) {}

    public function type(): string
    {
        return StageActionCatalog::NOTIFY_STAFF;
    }

    public function execute(StageActionContext $context): StageActionResult
    {
        if (! $this->channel->isConfigured()) {
            return StageActionResult::skipped('El canal de mensajería no está configurado.');
        }

        $appointment = $context->appointment;
        $business = $appointment->business;

        $phone = $appointment->items->first()?->resource?->user?->phone;

        if (! $phone) {
            return StageActionResult::skipped('La profesional no tiene teléfono registrado.');
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
            'equipo_'.$context->stage->key,
        );

        return $sent
            ? StageActionResult::ok('Aviso enviado al equipo.')
            : StageActionResult::failed('El canal rechazó el envío.');
    }
}
