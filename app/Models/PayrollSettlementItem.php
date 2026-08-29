<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una linea del comprobante: un servicio que se le pago.
 *
 * Los datos vienen copiados, no por relacion. La referencia a la cita existe
 * para poder navegar, pero si la cita desaparece el comprobante sigue diciendo
 * que se pago y por que.
 */
class PayrollSettlementItem extends Model
{
    use BelongsToBusiness;

    protected $fillable = [
        'business_id', 'settlement_id', 'appointment_item_id', 'charged_at',
        'service_name', 'client_name', 'charged', 'commission_rate', 'commission_amount',
    ];

    protected function casts(): array
    {
        return [
            'charged_at' => 'datetime',
            'charged' => 'decimal:2',
            'commission_rate' => 'decimal:4',
            'commission_amount' => 'decimal:2',
        ];
    }

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(PayrollSettlement::class, 'settlement_id');
    }
}
