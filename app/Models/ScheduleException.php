<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScheduleException extends Model
{
    use BelongsToBusiness, HasFactory;

    public const KIND_BLOCK = 'block';

    public const KIND_VACATION = 'vacation';

    public const KIND_HOLIDAY = 'holiday';

    /** Suma disponibilidad en vez de restarla: un turno extra puntual. */
    public const KIND_EXTRA_HOURS = 'extra_hours';

    protected $fillable = [
        'business_id', 'resource_id', 'starts_at', 'ends_at', 'kind', 'reason',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    public function resource(): BelongsTo
    {
        return $this->belongsTo(Resource::class);
    }
}
