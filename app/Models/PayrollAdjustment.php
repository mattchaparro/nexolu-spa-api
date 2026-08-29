<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un anticipo, un descuento o un bono pendiente de liquidar.
 *
 * Mientras `settlement_id` sea nulo esta pendiente. La liquidacion que lo cobra
 * lo reclama, y por eso nada se descuenta dos veces.
 */
class PayrollAdjustment extends Model
{
    use BelongsToBusiness;

    public const KIND_DEDUCTION = 'deduction';

    public const KIND_BONUS = 'bonus';

    protected $fillable = [
        'business_id', 'resource_id', 'settlement_id', 'date', 'kind',
        'category', 'amount', 'description', 'created_by_user_id',
    ];

    protected function casts(): array
    {
        return ['date' => 'date', 'amount' => 'decimal:2'];
    }

    public function resource(): BelongsTo
    {
        return $this->belongsTo(Resource::class);
    }

    /** @return list<string> */
    public static function kinds(): array
    {
        return [self::KIND_DEDUCTION, self::KIND_BONUS];
    }
}
