<?php

namespace App\Models;

use App\Support\Money\LoyaltyCalculator;
use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un premio ya ganado.
 *
 * El tipo y el valor se COPIAN aca al desbloquearlo, no se leen del programa
 * al canjearlo. Es la misma regla que el precio y la comision de una cita
 * cobrada: si el negocio cambia el programa manana, a quien ya lleno su
 * tarjeta se le entrega lo que decia la tarjeta el dia que la lleno.
 */
class LoyaltyReward extends Model
{
    use BelongsToBusiness;

    public const STATUS_AVAILABLE = 'available';

    public const STATUS_USED = 'used';

    public const STATUS_EXPIRED = 'expired';

    protected $fillable = [
        'business_id', 'program_id', 'client_id', 'status',
        'unlocked_at', 'used_at', 'used_on_appointment_id',
        'reward_type', 'reward_value', 'reward_service_id',
    ];

    protected function casts(): array
    {
        return [
            'unlocked_at' => 'datetime',
            'used_at' => 'datetime',
            'reward_value' => 'decimal:2',
        ];
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(LoyaltyProgram::class, 'program_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function rewardService(): BelongsTo
    {
        return $this->belongsTo(Service::class, 'reward_service_id');
    }

    /** Los sellos que pagaron este premio. */
    public function stamps(): HasMany
    {
        return $this->hasMany(LoyaltyStamp::class, 'consumed_by_reward_id');
    }

    public function label(): string
    {
        return LoyaltyCalculator::label(
            $this->reward_type,
            $this->reward_value === null ? null : (float) $this->reward_value,
            $this->rewardService?->name,
        );
    }

    /** Cuanto descuenta sobre una cuenta. */
    public function discountFor(float $ticketTotal): float
    {
        return LoyaltyCalculator::discountFor(
            $this->reward_type,
            $this->reward_value === null ? null : (float) $this->reward_value,
            $ticketTotal,
        );
    }
}
