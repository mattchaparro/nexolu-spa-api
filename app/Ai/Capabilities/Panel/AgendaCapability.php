<?php

namespace App\Ai\Capabilities\Panel;

use App\Ai\AiCaller;
use App\Ai\Capability;
use App\Ai\HoraLegible;
use App\Ai\Resolves;
use App\Models\Appointment;
use App\Services\Messaging\AvisoCitaNueva;

/**
 * Las citas de un día: «¿qué tiene Alejandra el viernes?», «¿cuántas citas
 * hay mañana?». Con hora, clienta, servicio, quién, estado y por dónde
 * entró.
 */
class AgendaCapability implements Capability
{
    use PanelDates, Resolves;

    public function requiredPermission(): ?string
    {
        return 'citas.ver_todas';
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
            'fecha' => ['nullable', 'string', 'max:40'],
            'persona' => ['nullable', 'string', 'max:120'],
        ];
    }

    public function execute(AiCaller $caller, array $arguments): array
    {
        $business = $caller->business;
        $tz = $business->businessTimezone();
        $dia = $this->dia($arguments['fecha'] ?? null, $tz);
        $persona = empty($arguments['persona']) ? null : $this->resolveResource($business->id, $arguments['persona']);

        $citas = Appointment::withoutGlobalScopes()
            ->where('business_id', $business->id)
            ->whereBetween('starts_at', [$dia->utc(), $dia->addDay()->utc()])
            ->when($persona, fn ($q) => $q->whereHas('items', fn ($i) => $i->where('resource_id', $persona->id)))
            ->with('items.service', 'items.resource')
            ->orderBy('starts_at')
            ->get();

        return [
            'fecha' => $dia->toDateString(),
            'dia' => $dia->locale('es')->isoFormat('dddd D [de] MMMM'),
            'persona' => $persona?->name,
            'total' => $citas->where('status', '!=', Appointment::STATUS_CANCELLED)->count(),
            'citas' => $citas->map(fn (Appointment $a) => [
                'hora' => HoraLegible::de($a->starts_at, $tz),
                'clienta' => $a->client_name,
                'servicios' => $a->items->map(fn ($i) => $i->service?->name)->filter()->implode(' + '),
                'con' => $a->items->map(fn ($i) => $i->resource?->name)->filter()->unique()->implode(' y '),
                'estado' => match (true) {
                    $a->checked_out_at !== null => 'cobrada',
                    default => $a->status,
                },
                'canal' => AvisoCitaNueva::CANALES[$a->source] ?? $a->source,
            ])->values()->all(),
        ];
    }
}
