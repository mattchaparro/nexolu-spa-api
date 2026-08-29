<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un almuerzo o descanso recurrente.
 *
 * `resource_id` nulo lo hace del negocio entero; `weekday` nulo, de todos los
 * dias. Las dos cosas juntas -- que es el caso comun -- describen el local que
 * cierra a mediodia con una sola fila.
 */
class ResourceBreak extends Model
{
    use BelongsToBusiness;

    protected $fillable = [
        'business_id', 'resource_id', 'weekday', 'start_time', 'end_time',
        'label', 'effective_from', 'effective_to', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            'effective_to' => 'date',
            'is_active' => 'boolean',
        ];
    }

    public function resource(): BelongsTo
    {
        return $this->belongsTo(Resource::class);
    }

    /**
     * Los descansos que aplican a un recurso en una fecha: los suyos y los del
     * negocio, del dia de la semana o de todos los dias, vigentes esa fecha.
     */
    public function scopeApplyingTo(Builder $query, int $resourceId, string $date, int $weekday): Builder
    {
        return $query
            ->where('is_active', true)
            ->where(fn ($q) => $q->where('resource_id', $resourceId)->orWhereNull('resource_id'))
            ->where(fn ($q) => $q->where('weekday', $weekday)->orWhereNull('weekday'))
            ->whereDate('effective_from', '<=', $date)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date));
    }
}
