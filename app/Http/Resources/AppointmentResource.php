<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Appointment
 */
class AppointmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // Las horas salen en la zona del negocio: es como las lee una
        // persona. Internamente todo se guarda en UTC.
        $tz = $this->business?->businessTimezone()
            ?? $request->user()?->business?->businessTimezone()
            ?? config('spa.defaults.timezone');

        return [
            'id' => $this->id,
            'status' => $this->status,
            'status_label' => self::statusLabel($this->status),
            'source' => $this->source,
            'client_id' => $this->client_id,
            'client_name' => $this->client_name,
            'client_phone' => $this->client_phone,
            'notes' => $this->notes,
            'starts_at' => $this->starts_at?->setTimezone($tz)->toIso8601String(),
            'ends_at' => $this->ends_at?->setTimezone($tz)->toIso8601String(),
            'label' => $this->starts_at?->setTimezone($tz)->format('H:i'),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => [
                'id' => $item->id,
                'service_id' => $item->service_id,
                'service_name' => $item->service?->name,
                'resource_id' => $item->resource_id,
                'resource_name' => $item->resource?->name,
                'starts_at' => $item->service_starts_at?->setTimezone($tz)->toIso8601String(),
                'ends_at' => $item->service_ends_at?->setTimezone($tz)->toIso8601String(),
                'price' => (float) $item->price,
            ])),
        ];
    }

    private static function statusLabel(string $status): string
    {
        return match ($status) {
            'pending' => 'Sin confirmar',
            'confirmed' => 'Confirmada',
            'in_progress' => 'En curso',
            'completed' => 'Completada',
            'cancelled' => 'Cancelada',
            'no_show' => 'No asistió',
            default => $status,
        };
    }
}
