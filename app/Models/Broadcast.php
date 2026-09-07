<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Una difusion: un mensaje a muchas, ahora o programado.
 *
 * Distinta de `Campaign`, que son descuentos que se aplican solos al cobrar.
 * Esta es la que reemplaza el envio masivo que hoy se hace desde ManyChat.
 */
class Broadcast extends Model
{
    use BelongsToBusiness;

    /** Se esta escribiendo. No sale sola. */
    public const STATUS_DRAFT = 'borrador';

    /** Tiene fecha y hora. El comando la va a recoger. */
    public const STATUS_SCHEDULED = 'programada';

    /** Se estan generando los mensajes ahora mismo. */
    public const STATUS_SENDING = 'enviando';

    public const STATUS_SENT = 'enviada';

    public const STATUS_CANCELLED = 'cancelada';

    protected $fillable = [
        'business_id', 'name', 'template_name', 'template_language', 'template_params',
        'body_template', 'audience', 'scheduled_at', 'sent_at', 'status', 'recipients',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'template_params' => 'array',
            'audience' => 'array',
            'scheduled_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /**
     * Si todavia se puede tocar.
     *
     * Una vez empezo a generar mensajes ya no: parte de las clientas los
     * recibio, y "editar" a esa altura seria mandar dos versiones distintas
     * de la misma promocion.
     */
    public function isEditable(): bool
    {
        return in_array($this->status, [self::STATUS_DRAFT, self::STATUS_SCHEDULED], true);
    }

    /** @return array<string, string> */
    public static function statusLabels(): array
    {
        return [
            self::STATUS_DRAFT => 'Borrador',
            self::STATUS_SCHEDULED => 'Programada',
            self::STATUS_SENDING => 'Enviando',
            self::STATUS_SENT => 'Enviada',
            self::STATUS_CANCELLED => 'Cancelada',
        ];
    }
}
