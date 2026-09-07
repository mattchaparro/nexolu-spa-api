<?php

namespace App\Models;

use App\Support\Money\LoyaltyCalculator;
use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un escalon de la tarjeta en modo escalera: "a las 15 visitas, 15%".
 *
 * Solo existe cuando el programa esta en modo `ladder`. En modo `card` el
 * premio vive en el programa, porque hay uno solo y se repite.
 */
class LoyaltyTier extends Model
{
    use BelongsToBusiness;

    protected $fillable = [
        'business_id', 'program_id', 'stamps_required',
        'reward_type', 'reward_value', 'reward_service_id', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'stamps_required' => 'integer',
            'reward_value' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(LoyaltyProgram::class, 'program_id');
    }

    public function rewardService(): BelongsTo
    {
        return $this->belongsTo(Service::class, 'reward_service_id');
    }

    /** Como se le explica este escalon a quien lo va a recibir. */
    public function rewardLabel(): string
    {
        return LoyaltyCalculator::label(
            $this->reward_type,
            $this->reward_value === null ? null : (float) $this->reward_value,
            $this->rewardService?->name,
        );
    }
}
