<?php

namespace App\Http\Resources;

use App\Support\ImageStorage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Resource
 */
class ResourceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            // En que local trabaja. Nulo solo en datos anteriores a las sedes.
            'location_id' => $this->location_id,
            'name' => $this->name,
            'color' => $this->color,
            'photo_url' => ImageStorage::url($this->photo_path),
            // La reseña corta de la página pública, y si sale ahí. Distinto de
            // `is_bookable_online`: alguien puede no aceptar reservas por
            // internet y aun así merecer estar en la vitrina del local.
            'bio' => $this->bio,
            // A dónde le llegan los avisos de sus citas. Vacío = no le
            // llegan, que es lo que pasa hoy con casi todo el equipo.
            'phone' => $this->phone,
            'is_public' => (bool) $this->is_public,
            // [{category_id, rest_days}]: los días entre dos servicios de
            // esa categoría (Marcela, pedicure día de por medio).
            'category_rest_days' => array_values($this->category_rest_days ?? []),
            'user_id' => $this->user_id,
            'is_bookable_online' => (bool) $this->is_bookable_online,
            'is_active' => (bool) $this->is_active,
            // Su porcentaje general. Nulo = cada servicio decide.
            'commission_rate' => $this->commission_rate === null ? null : (float) $this->commission_rate,
            'services' => $this->whenLoaded('services', fn () => $this->services->map(fn ($s) => [
                'id' => $s->id,
                'name' => $s->name,
                'duration_override_min' => $s->pivot->duration_override_min,
                'commission_rate_override' => $s->pivot->commission_rate_override === null
                    ? null
                    : (float) $s->pivot->commission_rate_override,
            ])),
        ];
    }
}
