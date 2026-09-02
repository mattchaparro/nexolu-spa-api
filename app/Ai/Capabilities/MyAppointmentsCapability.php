<?php

namespace App\Ai\Capabilities;

use App\Ai\AiCaller;
use App\Ai\Capability;
use App\Models\Appointment;
use App\Services\ClientPortalService;

/**
 * Las citas de QUIEN ESTA ESCRIBIENDO. Nunca las de nadie mas.
 *
 * No recibe ningun argumento con un cliente adentro, y eso es deliberado: si
 * lo recibiera, un modelo al que le escriban "muéstrame las citas de Carolina"
 * podria pedirlas, y el negocio acaba de perder su base de clientas por un
 * mensaje de texto. La identidad sale del telefono con el que llego el
 * mensaje, que lo verifica WhatsApp, no la conversacion.
 */
class MyAppointmentsCapability implements Capability
{
    public function __construct(private readonly ClientPortalService $portal) {}

    public function requiredPermission(): ?string
    {
        return null;
    }

    public function requiredFeature(): ?string
    {
        return 'scheduling';
    }

    public function allowsCustomers(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [];
    }

    public function execute(AiCaller $caller, array $arguments): array
    {
        // Una empleada no tiene "sus" citas de clienta: para eso esta la
        // agenda del panel, con sus permisos y su sede.
        if ($caller->isStaff()) {
            return ['citas' => [], 'nota' => 'Esta capacidad es para clientas, no para el equipo.'];
        }

        if ($caller->client === null) {
            return ['citas' => []];
        }

        $tz = $caller->business->businessTimezone();

        $citas = $this->portal->upcoming($caller->client, $caller->business);

        return [
            'citas' => $citas->map(fn (Appointment $a) => [
                'id' => $a->id,
                'fecha' => $a->starts_at?->setTimezone($tz)->format('Y-m-d'),
                'hora' => $a->starts_at?->setTimezone($tz)->format('H:i'),
                'servicios' => $a->items->map(fn ($i) => $i->service?->name)->filter()->values()->all(),
                'con' => $a->items->first()?->resource?->name,
                'sede' => $a->location?->name,
                'se_puede_cambiar' => $this->portal->canBeChanged($a, $caller->business),
            ])->all(),
        ];
    }
}
