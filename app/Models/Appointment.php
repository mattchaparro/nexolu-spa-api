<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Appointment extends Model
{
    use BelongsToBusiness, HasFactory, SoftDeletes;

    public const STATUS_PENDING = 'pending';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_NO_SHOW = 'no_show';

    public const SOURCE_ADMIN = 'admin';

    public const SOURCE_ONLINE = 'online';

    public const SOURCE_WHATSAPP_AGENT = 'whatsapp_agent';

    public const SOURCE_PHONE = 'phone';

    protected $fillable = [
        'business_id', 'client_id', 'client_name', 'client_phone', 'stage_id', 'service_package_id',
        'starts_at', 'ends_at', 'status', 'source', 'notes',
        'confirmed_at', 'cancelled_at', 'cancelled_by_user_id', 'cancellation_reason',
        'payment_method_id', 'checked_out_at', 'checked_out_by_user_id',
        'subtotal', 'discount_amount', 'discount_reason', 'total', 'commission_total',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'checked_out_at' => 'datetime',
            'subtotal' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'total' => 'decimal:2',
            'commission_total' => 'decimal:2',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(AppointmentItem::class)->orderBy('sort_order');
    }

    public function servicePackage(): BelongsTo
    {
        return $this->belongsTo(ServicePackage::class, 'service_package_id');
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    public function isPaid(): bool
    {
        return $this->checked_out_at !== null;
    }

    /** Estados en los que la cita todavia ocupa al recurso. */
    public static function activeStatuses(): array
    {
        return [
            self::STATUS_PENDING,
            self::STATUS_CONFIRMED,
            self::STATUS_IN_PROGRESS,
            self::STATUS_COMPLETED,
        ];
    }

    public static function statuses(): array
    {
        return [
            self::STATUS_PENDING,
            self::STATUS_CONFIRMED,
            self::STATUS_IN_PROGRESS,
            self::STATUS_COMPLETED,
            self::STATUS_CANCELLED,
            self::STATUS_NO_SHOW,
        ];
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::activeStatuses(), true);
    }
}
