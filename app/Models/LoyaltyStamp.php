<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un sello: una visita que conto.
 *
 * Es una FILA por visita, no un contador. El saldo se cuenta, asi que no hay
 * nada que se pueda desincronizar de la realidad -- que es lo que el sistema
 * viejo terminaba arreglando con `gamification:recalculate`.
 *
 * `consumed_by_reward_id` es el reinicio de la tarjeta: los sellos que pagaron
 * un premio quedan atados a el en vez de restarse de un contador, asi que
 * siempre se puede responder "¿por que tengo 2 sellos?" con las visitas exactas.
 */
class LoyaltyStamp extends Model
{
    use BelongsToBusiness;

    protected $fillable = [
        'business_id', 'program_id', 'client_id', 'appointment_id',
        'earned_at', 'consumed_by_reward_id',
    ];

    protected function casts(): array
    {
        return ['earned_at' => 'datetime'];
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
