<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CashClosing extends Model
{
    use BelongsToBusiness, HasFactory;

    protected $fillable = [
        'business_id', 'date', 'total_charged', 'total_cash', 'total_other_methods',
        'payment_breakdown', 'opening_cash', 'total_expenses', 'expected_cash',
        'actual_cash', 'difference', 'base_for_next_day', 'total_commissions',
        'note', 'closed_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'total_charged' => 'decimal:2',
            'total_cash' => 'decimal:2',
            'total_other_methods' => 'decimal:2',
            'payment_breakdown' => 'array',
            'opening_cash' => 'decimal:2',
            'total_expenses' => 'decimal:2',
            'expected_cash' => 'decimal:2',
            'actual_cash' => 'decimal:2',
            'difference' => 'decimal:2',
            'base_for_next_day' => 'decimal:2',
            'total_commissions' => 'decimal:2',
        ];
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by_user_id');
    }
}
