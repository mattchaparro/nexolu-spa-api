<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una fila por unidad de granularidad ocupada. El indice unico
 * (resource_id, slot_start) es lo que hace imposible la doble reserva: la
 * segunda escritura concurrente falla en la base, no en la aplicacion.
 */
class ResourceOccupancy extends Model
{
    protected $table = 'resource_occupancy';

    public $timestamps = false;

    protected $fillable = ['business_id', 'resource_id', 'appointment_item_id', 'slot_start'];

    protected function casts(): array
    {
        return ['slot_start' => 'datetime'];
    }

    public function resource(): BelongsTo
    {
        return $this->belongsTo(Resource::class);
    }

    public function appointmentItem(): BelongsTo
    {
        return $this->belongsTo(AppointmentItem::class);
    }
}
