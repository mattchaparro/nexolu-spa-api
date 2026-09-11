<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Un gasto que se causa todos los meses: arriendo, agua, internet.
 *
 * Es una PLANTILLA, no un gasto. Lo que se apunta en los libros es el gasto
 * que ella genera cada mes, y ese se edita como cualquier otro: la plantilla
 * dice cuanto suele ser, no cuanto fue.
 */
class RecurringExpense extends Model
{
    use BelongsToBusiness, SoftDeletes;

    protected $fillable = [
        'business_id', 'expense_type_id', 'description', 'value',
        'day_of_month', 'payment_method_id', 'location_id', 'scope', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'decimal:2',
            'day_of_month' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function expenseType(): BelongsTo
    {
        return $this->belongsTo(ExpenseType::class);
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    /**
     * Que dia de ESE mes le toca.
     *
     * Un dia 31 en febrero se recorta al ultimo dia del mes en vez de
     * saltarse: el arriendo de febrero existe aunque febrero no tenga 31.
     */
    public function fechaEn(\Carbon\CarbonImmutable $mes): \Carbon\CarbonImmutable
    {
        return $mes->startOfMonth()->setDay(
            min($this->day_of_month, $mes->daysInMonth),
        );
    }
}
