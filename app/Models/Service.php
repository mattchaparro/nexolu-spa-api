<?php

namespace App\Models;

use App\Support\Money\CommissionResolver;
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
        'business_id', 'name', 'slug', 'description', 'image_path', 'service_category_id',
        'duration_min', 'buffer_before_min', 'buffer_after_min',
        'price', 'commission_rate', 'is_bookable_online', 'is_active', 'sort_order',
        'requires_photo',
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
            'requires_photo' => 'boolean',
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
     * Porcentaje de comision efectivo, y de donde sale.
     *
     * La cascada vive en CommissionResolver, sin base de datos, para poder
     * comprobarla con casos escritos a mano. Aca solo se buscan los cuatro
     * numeros que pueden aplicar.
     *
     * @return array{rate: float|null, source: string}
     */
    public function resolveCommissionFor(?Resource $resource = null): array
    {
        $agreement = null;

        if ($resource !== null) {
            $agreement = $this->resources()
                ->where('resources.id', $resource->id)
                ->first()?->pivot?->commission_rate_override;
        }

        return CommissionResolver::resolve(
            agreement: self::toRate($agreement),
            person: self::toRate($resource?->commission_rate),
            service: self::toRate($this->commission_rate),
            category: self::toRate($this->category?->commission_rate),
        );
    }

    /** Solo el numero, para quien no necesita explicar de donde salio. */
    public function commissionRateFor(?Resource $resource = null): ?float
    {
        return $this->resolveCommissionFor($resource)['rate'];
    }

    /**
     * Un decimal de la base a float, conservando el null.
     *
     * `(float) null` da 0.0, y 0 significa "este no paga comision" -- muy
     * distinto de "no configurado, pregunta mas abajo". Convertir a ciegas
     * cortaba la cascada en el primer escalon vacio.
     */
    private static function toRate(mixed $value): ?float
    {
        return $value === null ? null : (float) $value;
    }
}
