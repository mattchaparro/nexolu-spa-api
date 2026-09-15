<?php

namespace App\Services\Scheduling\Actions;

use App\Services\WhatsApp\NexoluConnectFlows;
use App\Support\NombreDePila;
use App\Support\Scheduling\StageActionCatalog;
use App\Support\Scheduling\StageMessage;

/**
 * Inicia un flujo de conversacion de Nexolu Connect al entrar a la etapa:
 * el cliente recibe un mensaje con botones (condiciones de cancelacion,
 * garantias, gestionar su cita...) y Connect atiende las respuestas solo.
 *
 * Es el reemplazo directo de "disparar un flow de ManyChat": el negocio
 * elige el flujo por su nombre (creado en connect.nexolu.co) y esta accion
 * le pasa las MISMAS variables que usan las plantillas de mensajes
 * (StageMessage::values: {cliente}, {fecha}, {hora}, {servicio},
 * {profesional}, {mis_citas}...) - en el flujo se escriben {{asi}}.
 *
 * A diferencia de "Avisarle al cliente", esto NO pasa por la bandeja de
 * salida: un flujo es una conversacion que Connect gestiona y audita en su
 * propio panel, no un mensaje que alguien copia a mano. Por eso, sin
 * Connect configurado la accion se OMITE con motivo (no hay modo manual
 * posible para un flujo). No es critica: la cita se mueve de etapa aunque
 * Connect este caido.
 */
class TriggerConnectFlowAction implements StageAction
{
    public function __construct(private readonly NexoluConnectFlows $flows) {}

    public function type(): string
    {
        return StageActionCatalog::TRIGGER_CONNECT_FLOW;
    }

    public function execute(StageActionContext $context): StageActionResult
    {
        $appointment = $context->appointment;
        $phone = $appointment->client_phone ?? $appointment->client?->phone;

        if (! $phone) {
            return StageActionResult::skipped('El cliente no tiene teléfono registrado.');
        }

        $flow = trim((string) $context->config('flow', ''));

        if ($flow === '') {
            return StageActionResult::skipped('La etapa no tiene un flujo configurado.');
        }

        if (! $this->flows->isConfigured()) {
            return StageActionResult::skipped('Nexolú Connect no está configurado en este ambiente.');
        }

        $ok = $this->flows->trigger(
            $flow,
            $phone,
            (int) $appointment->business_id,
            StageMessage::values($appointment),
            NombreDePila::deSaludo($appointment->client_name),
        );

        return $ok
            ? StageActionResult::ok("Flujo «{$flow}» iniciado para {$phone}.")
            : StageActionResult::failed("Connect no aceptó el flujo «{$flow}» (ver el log).");
    }
}
