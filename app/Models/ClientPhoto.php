<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClientPhoto extends Model
{
    use BelongsToBusiness, HasFactory;

    protected $fillable = [
        'business_id', 'client_id', 'appointment_item_id',
        'image_path', 'caption', 'taken_at', 'uploaded_by_user_id',
    ];

    protected function casts(): array
    {
        return ['taken_at' => 'datetime'];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function appointmentItem(): BelongsTo
    {
        return $this->belongsTo(AppointmentItem::class);
    }
}
