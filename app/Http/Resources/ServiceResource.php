<?php

namespace App\Http\Resources;

use App\Support\ImageStorage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Service
 */
class ServiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            // URL armada al serializar, no guardada. Si cambia el CDN o el
            // bucket se cambia en un solo lugar, sin reescribir la base.
            'image_url' => ImageStorage::url($this->image_path),
            'duration_min' => (int) $this->duration_min,
            'buffer_before_min' => (int) $this->buffer_before_min,
            'buffer_after_min' => (int) $this->buffer_after_min,
            // Los buffers ocupan al profesional pero no se le cobran al
            // cliente: el front muestra duration_min, no esta suma.
            'occupied_min' => $this->occupiedMinutesFor(),
            'price' => (float) $this->price,
            'is_bookable_online' => (bool) $this->is_bookable_online,
            'is_active' => (bool) $this->is_active,
            'category' => $this->whenLoaded('category', fn () => [
                'id' => $this->category->id,
                'name' => $this->category->name,
            ]),
            'resource_ids' => $this->whenLoaded('resources', fn () => $this->resources->pluck('id')),
        ];
    }
}
