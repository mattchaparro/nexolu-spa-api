<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PaymentMethod extends Model
{
    use BelongsToBusiness, HasFactory;

    protected $fillable = [
        'business_id', 'name', 'counts_as_cash', 'provider_fee_rate', 'is_active', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'counts_as_cash' => 'boolean',
            'provider_fee_rate' => 'decimal:4',
            'is_active' => 'boolean',
        ];
    }
}
