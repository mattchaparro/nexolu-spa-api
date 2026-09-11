<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Algo que se vende y no se presta: una crema, un esmalte, una vela.
 */
class Product extends Model
{
    use BelongsToBusiness, SoftDeletes;

    protected $fillable = [
        'business_id', 'name', 'sku', 'description', 'image_path',
        'price', 'cost', 'stock', 'low_stock_at', 'is_active', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'cost' => 'decimal:2',
            'stock' => 'integer',
            'low_stock_at' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function movements(): HasMany
    {
        return $this->hasMany(ProductStockMovement::class);
    }

    public function sales(): HasMany
    {
        return $this->hasMany(ProductSale::class);
    }

    /** Si hace falta reponer. `low_stock_at` nulo = no avisar. */
    public function isLow(): bool
    {
        return $this->low_stock_at !== null && $this->stock <= $this->low_stock_at;
    }
}
