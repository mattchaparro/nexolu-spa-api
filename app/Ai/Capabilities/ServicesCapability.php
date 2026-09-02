<?php

namespace App\Ai\Capabilities;

use App\Ai\AiCaller;
use App\Ai\Capability;
use App\Models\Service;

/**
 * El catalogo que se ofrece por internet.
 *
 * Abierta a clientas porque es exactamente lo que ya muestra la pagina
 * publica: nada que no este a un clic de distancia sin autenticarse.
 */
class ServicesCapability implements Capability
{
    public function requiredPermission(): ?string
    {
        return null;
    }

    public function requiredFeature(): ?string
    {
        return 'online_booking';
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
        $servicios = Service::withoutGlobalScopes()
            ->where('business_id', $caller->business->id)
            ->where('is_active', true)
            ->where('is_bookable_online', true)
            ->with('category')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return [
            'servicios' => $servicios->map(fn (Service $s) => [
                'nombre' => $s->name,
                'precio' => (float) $s->price,
                'duracion_min' => $s->duration_min,
                'categoria' => $s->category?->name,
            ])->all(),
        ];
    }
}
