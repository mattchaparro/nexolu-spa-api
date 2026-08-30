<?php

namespace App\Models;

use App\Support\Money\LoyaltyCalculator;
use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * La tarjeta de sellos de un negocio: cuantos sellos y que se gana.
 *
 * Uno activo por negocio. Dos programas simultaneos obligarian a decidir cual
 * gana el sello de una visita, y esa pregunta no tiene una respuesta que el
 * mostrador pueda explicar en voz alta.
 */
class LoyaltyProgram extends Model
{
    use BelongsToBusiness;

    protected $fillable = [
        'business_id', 'name', 'terms', 'stamps_required',
        'reward_type', 'reward_value', 'reward_service_id',
        'min_ticket', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'stamps_required' => 'integer',
            'reward_value' => 'decimal:2',
            'min_ticket' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function rewardService(): BelongsTo
    {
        return $this->belongsTo(Service::class, 'reward_service_id');
    }

    public function stamps(): HasMany
    {
        return $this->hasMany(LoyaltyStamp::class, 'program_id');
    }

    /** Como se le explica el premio a quien lo va a recibir. */
    public function rewardLabel(): string
    {
        return LoyaltyCalculator::label(
            $this->reward_type,
            $this->reward_value === null ? null : (float) $this->reward_value,
            $this->rewardService?->name,
        );
    }
}
