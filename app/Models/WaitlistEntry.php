<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Alguien esperando un cupo que no habia.
 *
 * Una entrada NO es una cita a medias: es una preferencia con vencimiento.
 * Se cierra cuando la persona consigue lo que buscaba -- por el aviso o por
 * su cuenta -- o cuando su rango de fechas pasa.
 */
class WaitlistEntry extends Model
{
    use BelongsToBusiness;

    /** Esperando. La unica que recibe avisos. */
    public const STATUS_OPEN = 'open';

    /** Consiguio su cupo. Cierra la entrada y con ella cualquier cascada. */
    public const STATUS_FULFILLED = 'fulfilled';

    /** Pidio que no le avisaran mas. */
    public const STATUS_STOPPED = 'stopped';

    /** Su rango de fechas paso sin cupo. */
    public const STATUS_EXPIRED = 'expired';

    protected $fillable = [
        'business_id', 'location_id', 'client_id', 'phone', 'service_id',
        'preferred_resource_id', 'date_from', 'date_to', 'time_from', 'time_to',
        'status', 'last_notified_at', 'fulfilled_appointment_id',
    ];

    /**
     * El token es la llave de la entrada: quien lo tenga puede tomar cupos a
     * nombre de esa persona. Fuera de fillable y oculto, igual que el del
     * portal de citas.
     */
    protected $hidden = ['token'];

    protected function casts(): array
    {
        return [
            'date_from' => 'date',
            'date_to' => 'date',
            'last_notified_at' => 'datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function preferredResource(): BelongsTo
    {
        return $this->belongsTo(Resource::class, 'preferred_resource_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }
}
