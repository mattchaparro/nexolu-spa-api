<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Quien movio que cita, de donde a donde, y que se disparo.
 *
 * Vale por si sola aunque no hubiera acciones: hoy la unica huella de un cambio
 * de estado es el estado nuevo, asi que si una cita aparece cancelada nadie
 * sabe quien ni cuando.
 */
class AppointmentStageEvent extends Model
{
    use BelongsToBusiness;

    public const ACTOR_USER = 'user';

    public const ACTOR_SYSTEM = 'system';

    public const ACTOR_AGENT = 'agent';

    public const ACTOR_CLIENT = 'client';

    protected $fillable = [
        'business_id', 'appointment_id', 'from_status', 'to_status',
        'from_stage_id', 'to_stage_id', 'user_id', 'actor', 'actions',
    ];

    protected function casts(): array
    {
        return ['actions' => 'array'];
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
