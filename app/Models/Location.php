<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Una sede: un local fisico del negocio.
 *
 * NO es un negocio aparte, y esa es la decision de fondo. La clienta que se
 * hace las manos en Chapinero y los pies en Cedritos es la misma persona, con
 * la misma tarjeta de sellos y el mismo historial. Catalogo y clientes son del
 * NEGOCIO; la gente y la agenda son de la SEDE.
 */
class Location extends Model
{
    use BelongsToBusiness;

    protected $fillable = [
        'business_id', 'name', 'slug', 'address', 'phone', 'city', 'maps_url',
        'is_primary', 'is_active', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function resources(): HasMany
    {
        return $this->hasMany(Resource::class);
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }
}
