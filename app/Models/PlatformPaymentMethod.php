<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Catalogo global de medios de pago, administrado por la plataforma.
 *
 * Nunca se borra un medio: se desactiva. Borrarlo dejaria sin nombre a los
 * cobros historicos que lo referencian, y con ellos los cierres de meses
 * anteriores.
 */
class PlatformPaymentMethod extends Model
{
    use HasFactory;

    protected $fillable = ['key', 'label', 'counts_as_cash', 'is_active', 'sort_order'];

    protected $attributes = [
        'counts_as_cash' => false,
        'is_active' => true,
        'sort_order' => 0,
    ];

    protected function casts(): array
    {
        return [
            'counts_as_cash' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function businessMethods(): HasMany
    {
        return $this->hasMany(PaymentMethod::class);
    }
}
