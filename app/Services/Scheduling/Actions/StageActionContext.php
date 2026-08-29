<?php

namespace App\Services\Scheduling\Actions;

use App\Models\Appointment;
use App\Models\AppointmentWorkflowStage;
use App\Models\User;

/**
 * Todo lo que una accion necesita saber, en un solo objeto.
 *
 * Pasar la cita suelta obligaria a cada accion a recargar el negocio y a
 * adivinar quien la disparo, y una accion que consulta por su cuenta es una
 * accion que se puede llevar a otro sitio y comportarse distinto.
 */
final class StageActionContext
{
    /** @param array<string, mixed> $config */
    public function __construct(
        public readonly Appointment $appointment,
        public readonly AppointmentWorkflowStage $stage,
        public readonly array $config,
        public readonly ?User $actor,
        public readonly string $actorKind,
    ) {}

    public function config(string $key, mixed $default = null): mixed
    {
        return $this->config[$key] ?? $default;
    }
}
