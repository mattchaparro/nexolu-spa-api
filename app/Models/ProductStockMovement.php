<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cada entrada y salida de inventario, con signo.
 *
 * +12 entraron, -1 se vendio. Sumar la columna da el saldo: por eso el stock
 * del producto no puede descuadrarse respecto a las ventas, porque sale de
 * ellas y no de una columna que alguien edite aparte.
 */
class ProductStockMovement extends Model
{
    use BelongsToBusiness;

    public const KIND_ENTRADA = 'entrada';

    public const KIND_VENTA = 'venta';

    public const KIND_AJUSTE = 'ajuste';

    protected $fillable = [
        'business_id', 'product_id', 'kind', 'quantity',
        'product_sale_id', 'created_by_user_id', 'note',
    ];

    protected function casts(): array
    {
        return ['quantity' => 'integer'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
