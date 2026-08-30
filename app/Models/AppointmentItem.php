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
        'price', 'final_price', 'commission_rate', 'commission_amount', 'sort_order',
        'is_warranty', 'warranty_for_resource_id', 'warranty_for_item_id', 'warranty_note',
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
            'final_price' => 'decimal:2',
            'commission_amount' => 'decimal:2',
            'is_warranty' => 'boolean',
        ];
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    /**
     * A quien se le anota esta garantia.
     *
     * No es quien la rehace: es quien hizo el trabajo que fallo. Todo el
     * sentido de llevar la cuenta es saber quien esta recibiendo garantias.
     */
    public function warrantyForResource(): BelongsTo
    {
        return $this->belongsTo(Resource::class, 'warranty_for_resource_id');
    }

    /** El trabajo que fallo, si quedo registrado. */
    public function warrantyForItem(): BelongsTo
    {
        return $this->belongsTo(self::class, 'warranty_for_item_id');
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
