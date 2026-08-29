<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una accion administrativa registrada. Lo escribe AuditLogger.
 */
class LogAction extends Model
{
    protected $fillable = [
        'business_id', 'user_id', 'action',
        'subject_type', 'subject_id', 'payload', 'ip_address',
    ];

    protected function casts(): array
    {
        return ['payload' => 'array'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
