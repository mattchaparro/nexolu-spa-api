<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Una venta de producto.
 *
 * El precio y el costo se CONGELAN aca: subir el precio de la crema en octubre
 * no puede cambiar lo que se cobro en septiembre.
 */
class ProductSale extends Model
{
    use BelongsToBusiness, SoftDeletes;

    protected $fillable = [
        'business_id', 'product_id', 'appointment_id', 'client_id', 'location_id',
        'quantity', 'unit_price', 'unit_cost', 'total',
        'payment_method_id', 'sold_by_user_id', 'sold_at',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price' => 'decimal:2',
            'unit_cost' => 'decimal:2',
            'total' => 'decimal:2',
            'sold_at' => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    /** Lo que dejo de ganancia, si se sabe el costo. */
    public function margin(): ?float
    {
        return $this->unit_cost === null
            ? null
            : round((float) $this->total - ((float) $this->unit_cost * $this->quantity), 2);
    }
}
