<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\User
 */
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'last_name' => $this->last_name,
            'full_name' => $this->fullName(),
            'email' => $this->email,
            'phone' => $this->phone,
            'is_active' => (bool) $this->is_active,
            'business_id' => $this->business_id,
            // Propiedad del usuario, no rol del negocio: el front decide con
            // esto a que panel mandarlo, y el backend lo hace cumplir aparte.
            'is_super_admin' => (bool) $this->is_super_admin,
            'business' => new BusinessResource($this->whenLoaded('business')),
            'resource_id' => $this->resource?->id,
            'roles' => $this->getRoleNames(),
            'permissions' => $this->getAllPermissions()->pluck('name'),
        ];
    }
}
