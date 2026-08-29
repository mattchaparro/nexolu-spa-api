<?php

namespace App\Models;

use App\Support\Money\PackagePricing;
use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Un combo: varios servicios que se venden juntos, normalmente con descuento.
 *
 * No tiene duracion ni precio propios: los dos salen de sus partes. Lo unico
 * suyo es la REGLA de descuento.
 */
class ServicePackage extends Model
{
    use BelongsToBusiness;

    protected $fillable = [
        'business_id', 'name', 'slug', 'description', 'image_path',
        'discount_type', 'discount_value',
        'is_active', 'is_bookable_online', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'discount_value' => 'decimal:2',
            'is_active' => 'boolean',
            'is_bookable_online' => 'boolean',
        ];
    }

    /**
     * Los servicios que lo componen, en el orden en que se prestan.
     *
     * El orden importa: es la secuencia con la que se agenda. Primero el
     * manicure, despues el pedicure, no al reves.
     */
    public function services(): BelongsToMany
    {
        return $this->belongsToMany(Service::class, 'service_package_items', 'package_id', 'service_id')
            ->withPivot('sort_order')
            ->orderBy('service_package_items.sort_order');
    }

    /**
     * Precio de lista, descuento y total.
     *
     * @return array{list_total: float, discount: float, total: float, savings_percent: float}
     */
    public function quote(): array
    {
        return PackagePricing::quote(
            $this->services->map(fn (Service $s) => (float) $s->price)->all(),
            $this->discount_type,
            $this->discount_value === null ? null : (float) $this->discount_value,
        );
    }

    /** Cuanto ocupa la secuencia completa, buffers incluidos. */
    public function totalMinutes(): int
    {
        return (int) $this->services->sum(fn (Service $s) => $s->occupiedMinutesFor());
    }
}
