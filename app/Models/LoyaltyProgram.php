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
 *
 * DOS MODOS, porque los negocios de verdad usan los dos:
 *
 *   - `card`: junta 5, el sexto va con premio, y vuelve a empezar. Los sellos
 *     se gastan. Es el default y lo que hacen hoy todos los programas.
 *
 *   - `ladder`: hitos acumulativos (5, 10, 15...) con un premio distinto en
 *     cada uno. Los sellos no se gastan nunca. Es lo que Luxury lleva anos
 *     usando, y lo que sus clientas conocen.
 */
class LoyaltyProgram extends Model
{
    use BelongsToBusiness;

    /**
     * Junta N sellos, cobra el premio, la tarjeta vuelve a cero.
     *
     * Los sellos SE GASTAN: quien va por su octava visita con una tarjeta de
     * cinco tiene tres sellos, no ocho.
     */
    public const MODE_CARD = 'card';

    /**
     * Hitos acumulativos: a las 5 un premio, a las 10 otro, a las 15 otro.
     *
     * Los sellos NO se gastan nunca. El contador sube para siempre y cada
     * escalon entrega un premio distinto, definido en `loyalty_tiers`.
     */
    public const MODE_LADDER = 'ladder';

    protected $fillable = [
        'business_id', 'name', 'mode', 'terms', 'stamps_required',
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

    /**
     * Los escalones VIGENTES, de menos a mas sellos. Vacio en modo `card`.
     *
     * Los retirados quedan en la tabla pero fuera de aca: existen solo para
     * que los premios que entregaron sigan teniendo de donde colgar, y para
     * que volver a poner ese escalon manana no se lo regale de nuevo a quien
     * ya lo gano.
     */
    public function tiers(): HasMany
    {
        return $this->hasMany(LoyaltyTier::class, 'program_id')
            ->where('is_active', true)
            ->orderBy('stamps_required');
    }

    public function isLadder(): bool
    {
        return $this->mode === self::MODE_LADDER;
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
