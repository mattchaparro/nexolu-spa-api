<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * El turno de caja de UNA persona. Distinto del cierre del dia: un dia puede
 * tener varios turnos, y cada uno responde por su propio efectivo.
 */
class CashShift extends Model
{
    use BelongsToBusiness, HasFactory;

    protected $fillable = [
        'business_id', 'location_id', 'user_id', 'opened_at', 'closed_at',
        'opening_cash', 'opening_note', 'counted_cash', 'expected_cash',
        'difference', 'closing_note', 'total_charged', 'total_cash',
        'total_other_methods', 'total_expenses', 'payment_breakdown',
        'closed_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'opening_cash' => 'decimal:2',
            'counted_cash' => 'decimal:2',
            'expected_cash' => 'decimal:2',
            'difference' => 'decimal:2',
            'total_charged' => 'decimal:2',
            'total_cash' => 'decimal:2',
            'total_other_methods' => 'decimal:2',
            'total_expenses' => 'decimal:2',
            'payment_breakdown' => 'array',
        ];
    }

    /** En que local ocurrio. */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isOpen(): bool
    {
        return $this->closed_at === null;
    }
}
