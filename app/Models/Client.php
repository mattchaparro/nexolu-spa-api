<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Client extends Model
{
    use BelongsToBusiness, HasFactory, SoftDeletes;

    protected $fillable = [
        'business_id', 'name', 'last_name', 'phone', 'email', 'birth_date',
        'gender', 'notes', 'care_notes', 'preferred_resource_id', 'accepts_marketing', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'accepts_marketing' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    public function photos(): HasMany
    {
        return $this->hasMany(ClientPhoto::class)->orderByDesc('taken_at');
    }

    public function penalties(): HasMany
    {
        return $this->hasMany(ClientPenalty::class);
    }

    public function preferredResource(): BelongsTo
    {
        return $this->belongsTo(Resource::class, 'preferred_resource_id');
    }

    public function fullName(): string
    {
        return trim($this->name.' '.$this->last_name);
    }
}
