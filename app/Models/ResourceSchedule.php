<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Regla de horario recurrente. No se materializa en filas por dia: el motor de
 * disponibilidad la evalua contra el rango consultado.
 */
class ResourceSchedule extends Model
{
    use BelongsToBusiness, HasFactory;

    protected $fillable = [
        'business_id', 'resource_id', 'weekday',
        'start_time', 'end_time', 'effective_from', 'effective_to',
    ];

    protected function casts(): array
    {
        return [
            'weekday' => 'integer',
            'effective_from' => 'date',
            'effective_to' => 'date',
        ];
    }

    public function resource(): BelongsTo
    {
        return $this->belongsTo(Resource::class);
    }
}
