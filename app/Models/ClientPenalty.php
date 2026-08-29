<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClientPenalty extends Model
{
    use BelongsToBusiness, HasFactory;

    public const KIND_NO_SHOW = 'no_show';

    public const KIND_LATE_CANCELLATION = 'late_cancellation';

    protected $fillable = [
        'business_id', 'client_id', 'appointment_id', 'kind',
        'amount', 'reason', 'waived_at', 'waived_by_user_id',
    ];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'waived_at' => 'datetime'];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }
}
