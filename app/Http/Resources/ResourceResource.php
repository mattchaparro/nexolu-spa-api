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
