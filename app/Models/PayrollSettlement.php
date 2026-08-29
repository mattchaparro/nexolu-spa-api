<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un pago hecho a una profesional por un periodo.
 *
 * Todo lo que trae esta congelado. Abrir un comprobante de hace tres meses
 * tiene que mostrar lo mismo que el dia que se firmo, aunque desde entonces le
 * hayan cambiado el porcentaje o el catalogo haya subido de precio.
 */
class PayrollSettlement extends Model
{
    use BelongsToBusiness;

    protected $fillable = [
        'business_id', 'resource_id', 'period_start', 'period_end',
        'mode', 'base_amount', 'base_period',
        'services_count', 'charged_total', 'commission_total', 'base_total',
        'bonus_total', 'deduction_total', 'net_total',
        'paid_at', 'payment_method_id', 'expense_id', 'created_by_user_id', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'paid_at' => 'datetime',
            'base_amount' => 'decimal:2',
            'charged_total' => 'decimal:2',
            'commission_total' => 'decimal:2',
            'base_total' => 'decimal:2',
            'bonus_total' => 'decimal:2',
            'deduction_total' => 'decimal:2',
            'net_total' => 'decimal:2',
        ];
    }

    public function resource(): BelongsTo
    {
        return $this->belongsTo(Resource::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PayrollSettlementItem::class, 'settlement_id');
    }

    public function adjustments(): HasMany
    {
        return $this->hasMany(PayrollAdjustment::class, 'settlement_id');
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
    }
}
