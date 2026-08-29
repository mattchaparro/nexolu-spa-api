<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Service extends Model
{
    use BelongsToBusiness, HasFactory, SoftDeletes;

    protected $fillable = [
        'business_id', 'name', 'slug', 'description', 'service_category_id',
        'duration_min', 'buffer_before_min', 'buffer_after_min',
        'price', 'commission_rate', 'is_bookable_online', 'is_active', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'duration_min' => 'integer',
            'buffer_before_min' => 'integer',
            'buffer_after_min' => 'integer',
            'price' => 'decimal:2',
            'commission_rate' => 'decimal:4',
            'is_bookable_online' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ServiceCategory::class, 'service_category_id');
    }

    /**
     * La pivote se nombra explicitamente: Laravel la inferiria como
     * `resource_service` (orden alfabetico), pero `service_resource` se lee
     * mejor en el sentido en que realmente se consulta -- que recursos presta
     * este servicio.
     */
    public function resources(): BelongsToMany
    {
        return $this->belongsToMany(Resource::class, 'service_resource')
            ->withPivot(['duration_override_min', 'commission_rate_override']);
    }

    public function requirements(): HasMany
    {
        return $this->hasMany(ServiceResourceRequirement::class);
    }

    /**
     * Duracion efectiva para un recurso dado: una profesional con mas
     * experiencia puede tardar menos en el mismo servicio.
     */
    public function durationFor(?Resource $resource = null): int
    {
        if ($resource === null) {
            return $this->duration_min;
        }

        $override = $this->resources()
            ->where('resources.id', $resource->id)
            ->first()?->pivot?->duration_override_min;

        return $override ?? $this->duration_min;
    }

    /** Minutos que el recurso queda ocupado, buffers incluidos. */
    public function occupiedMinutesFor(?Resource $resource = null): int
    {
        return $this->buffer_before_min + $this->durationFor($resource) + $this->buffer_after_min;
    }

    /**
     * Porcentaje de comision efectivo para un recurso.
     *
     * Una profesional puede tener su propio porcentaje en un servicio
     * concreto -- tipicamente cuando es la unica que lo presta, o cuando
     * requiere una habilidad que el resto no tiene. Ignorar el override
     * liquida de menos y nadie lo nota hasta que alguien reclama.
     */
    public function commissionRateFor(?Resource $resource = null): ?float
    {
        if ($resource === null) {
            return $this->commission_rate === null ? null : (float) $this->commission_rate;
        }

        $override = $this->resources()
            ->where('resources.id', $resource->id)
            ->first()?->pivot?->commission_rate_override;

        $rate = $override ?? $this->commission_rate;

        return $rate === null ? null : (float) $rate;
    }
}
