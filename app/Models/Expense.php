<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Expense extends Model
{
    use BelongsToBusiness, HasFactory, SoftDeletes;

    /** Cuenta contra la caja del dia. */
    public const SCOPE_OPERATIONAL = 'operacional';

    /**
     * NO cuenta contra la caja del dia: arriendo, nomina, impuestos. Sin la
     * distincion, pagar el arriendo descuadraria el efectivo del mostrador.
     */
    public const SCOPE_ADMINISTRATIVE = 'administrativo';

    protected $fillable = [
        'business_id', 'location_id', 'expense_type_id', 'date', 'description', 'value',
        'scope', 'payment_method_id', 'receipt_path', 'created_by_user_id',
    ];

    protected function casts(): array
    {
        return ['date' => 'date', 'value' => 'decimal:2'];
    }

    /** En que local ocurrio. */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(ExpenseType::class, 'expense_type_id');
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    public static function scopes(): array
    {
        return [self::SCOPE_OPERATIONAL, self::SCOPE_ADMINISTRATIVE];
    }
}
