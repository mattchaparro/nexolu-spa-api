<?php

namespace App\Ai\Capabilities\Panel;

use App\Ai\AiArgumentException;
use App\Ai\AiCaller;
use App\Ai\Capability;
use App\Ai\Resolves;
use App\Models\ResourceBreak;

/**
 * «Bloquéale a Alejandra el viernes 3 de 5 a 6.»
 *
 * Es el mismo bloqueo por fechas de la ficha de horario (un ResourceBreak
 * con fecha de inicio y de fin): en esas horas no sale disponible ni en el
 * bot, ni en la web, ni al agendar. En el IA Core es una escritura: el
 * asistente arma un borrador y esto solo corre cuando la persona lo
 * confirma en la tarjeta.
 */
class BlockHoursCapability implements Capability
{
    use PanelDates, Resolves;

    public function requiredPermission(): ?string
    {
        return 'horarios.gestionar';
    }

    public function requiredFeature(): ?string
    {
        return null;
    }

    public function allowsCustomers(): bool
    {
        return false;
    }

    public function rules(): array
    {
        return [
            'persona' => ['required', 'string', 'max:120'],
            'desde' => ['required', 'string', 'max:40'],
            'hasta' => ['nullable', 'string', 'max:40'],
            'hora_inicio' => ['nullable', 'date_format:H:i'],
            'hora_fin' => ['nullable', 'date_format:H:i'],
            'todo_el_dia' => ['nullable', 'boolean'],
            'motivo' => ['nullable', 'string', 'max:60'],
        ];
    }

    public function execute(AiCaller $caller, array $arguments): array
    {
        $business = $caller->business;
        $tz = $business->businessTimezone();
        $persona = $this->resolveResource($business->id, $arguments['persona']);
        $desde = $this->dia($arguments['desde'], $tz);
        $hasta = empty($arguments['hasta']) ? $desde : $this->dia($arguments['hasta'], $tz);

        if ($hasta->lt($desde)) {
            throw new AiArgumentException('La fecha final es antes de la inicial.');
        }

        $todoElDia = (bool) ($arguments['todo_el_dia'] ?? false) || empty($arguments['hora_inicio']);
        $inicio = $todoElDia ? '00:00' : $arguments['hora_inicio'];
        $fin = $todoElDia ? '23:59' : ($arguments['hora_fin'] ?? null);

        if ($fin === null || $fin <= $inicio) {
            throw new AiArgumentException('Falta la hora final, o termina antes de empezar.');
        }

        $bloqueo = ResourceBreak::withoutGlobalScopes()->create([
            'business_id' => $business->id,
            'resource_id' => $persona->id,
            'weekday' => null,
            'start_time' => $inicio.':00',
            'end_time' => $fin.':00',
            'label' => $arguments['motivo'] ?? 'Bloqueo',
            'effective_from' => $desde->toDateString(),
            'effective_to' => $hasta->toDateString(),
            'is_active' => true,
        ]);

        return [
            'bloqueado' => true,
            'id' => $bloqueo->id,
            'persona' => $persona->name,
            'desde' => $desde->locale('es')->isoFormat('dddd D [de] MMMM'),
            'hasta' => $hasta->locale('es')->isoFormat('dddd D [de] MMMM'),
            'horas' => $todoElDia ? 'todo el día' : "{$inicio} a {$fin}",
        ];
    }
}
