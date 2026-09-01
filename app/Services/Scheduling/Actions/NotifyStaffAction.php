<?php

namespace App\Services\Scheduling\Actions;

use App\Models\Message;
use App\Services\Messaging\MessageDispatcher;
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
    public function __construct(private readonly MessageDispatcher $dispatcher) {}

    public function type(): string
    {
        return StageActionCatalog::NOTIFY_STAFF;
    }

    public function execute(StageActionContext $context): StageActionResult
    {
        $appointment = $context->appointment;
        $business = $appointment->business;

        $phone = $appointment->items->first()?->resource?->user?->phone;

        if (! $phone) {
            return StageActionResult::skipped('Quien atiende no tiene teléfono registrado.');
        }

        $message = $this->dispatcher->queue(
            $business,
            Message::KIND_STAFF,
            $phone,
            StageMessage::render(
                (string) $context->config('template', ''),
                $appointment,
                $context->stage,
            ),
            $appointment,
        );

        if ($message === null) {
            return StageActionResult::skipped('Ya se le avisó al equipo de esta cita.');
        }

        return match ($message->status) {
            Message::STATUS_SENT => StageActionResult::ok('Aviso enviado al equipo.'),
            Message::STATUS_MANUAL => StageActionResult::ok(
                'Aviso listo en «Mensajes por enviar».'
            ),
            // El motivo, no un "falló" genérico: la diferencia entre un timeout
            // y un número inválido es la diferencia entre reintentar y
            // corregir la ficha.
            default => StageActionResult::failed($message->error ?? 'El canal rechazó el envío.'),
        };
    }
}
