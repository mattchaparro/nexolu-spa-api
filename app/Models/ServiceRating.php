<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Lo que opino un cliente de una visita, por persona.
 *
 * Todo lo interpretado es nullable a proposito: una respuesta a la que le
 * falta un campo se guarda igual. Rechazarla entera por lo que el cliente no
 * lleno es exactamente como el sistema viejo termino recuperando meses de
 * opiniones desde los logs.
 */
class ServiceRating extends Model
{
    use BelongsToBusiness;

    protected $fillable = [
        'business_id', 'appointment_id', 'appointment_item_id', 'resource_id', 'client_id',
        'service_rating', 'staff_rating', 'punctuality_rating', 'comment', 'raw_payload',
    ];

    protected function casts(): array
    {
        return [
            'service_rating' => 'integer',
            'staff_rating' => 'integer',
            'punctuality_rating' => 'integer',
            'raw_payload' => 'array',
        ];
    }

    public function resource(): BelongsTo
    {
        return $this->belongsTo(Resource::class);
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }
}
