<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AppointmentItem extends Model
{
    use BelongsToBusiness, HasFactory;

    protected $fillable = [
        'business_id', 'appointment_id', 'service_id', 'resource_id',
        'starts_at', 'ends_at', 'service_starts_at', 'service_ends_at',
        'price', 'commission_rate', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'service_starts_at' => 'datetime',
            'service_ends_at' => 'datetime',
            'price' => 'decimal:2',
            'commission_rate' => 'decimal:4',
        ];
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function resource(): BelongsTo
    {
        return $this->belongsTo(Resource::class);
    }

    public function occupancy(): HasMany
    {
        return $this->hasMany(ResourceOccupancy::class);
    }
}
