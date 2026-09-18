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
        $servicios = Service::withoutGlobalScope('business')
            ->where('business_id', $caller->business->id)
            ->where('is_active', true)
            ->where('is_bookable_online', true)
            ->with('category')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        $moneda = $caller->business->currency ?? 'COP';

        return [
            'moneda' => $moneda,
            'servicios' => $servicios->map(fn (Service $s) => [
                'nombre' => $s->name,
                /*
                 * El precio va ESCRITO, no como número suelto.
                 *
                 * Con `precio => 180000.0` el modelo escribió "$180.00" en un
                 * chat real: leyó los miles como decimales y le cotizó a una
                 * clienta mil veces menos. Un número sin unidades es una
                 * invitación a que lo reformatee mal; el texto ya formateado
                 * no deja margen.
                 */
                'precio' => $this->precio((float) $s->price, $moneda),
                'precio_valor' => (float) $s->price,
                'duracion_min' => $s->duration_min,
                'categoria' => $s->category?->name,
            ])->all(),
        ];
    }

    /** Como lo escribe el local: 180.000 COP (miles con punto, sin decimales). */
    private function precio(float $valor, string $moneda): string
    {
        return number_format($valor, 0, ',', '.').' '.$moneda;
    }
}
