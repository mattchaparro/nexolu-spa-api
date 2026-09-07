<?php

namespace App\Models;

use App\Support\ImageStorage;
use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;

/**
 * Una historia de Instagram, publicada o por publicar.
 *
 * Existe porque la API de Meta no programa: publicar es una llamada en el
 * momento. Esta fila guarda la intencion hasta que llegue la hora.
 */
class InstagramStory extends Model
{
    use BelongsToBusiness;

    public const STATUS_DRAFT = 'borrador';

    public const STATUS_SCHEDULED = 'programada';

    public const STATUS_PUBLISHED = 'publicada';

    /** Meta la rechazo. El motivo esta en `error`. */
    public const STATUS_FAILED = 'fallida';

    public const STATUS_CANCELLED = 'cancelada';

    protected $fillable = [
        'business_id', 'image_path', 'mentions', 'scheduled_at', 'published_at',
        'status', 'media_id', 'error', 'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'mentions' => 'array',
            'scheduled_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }

    /** La URL que Meta va a descargar. Tiene que ser publica de verdad. */
    public function imageUrl(): ?string
    {
        return ImageStorage::url($this->image_path);
    }

    /**
     * Si todavia se puede tocar.
     *
     * Una publicada ya esta en Instagram: "editarla" desde aca no la
     * cambiaria alla, solo mentiria en la pantalla.
     */
    public function isEditable(): bool
    {
        return in_array($this->status, [self::STATUS_DRAFT, self::STATUS_SCHEDULED, self::STATUS_FAILED], true);
    }

    /** @return array<string, string> */
    public static function statusLabels(): array
    {
        return [
            self::STATUS_DRAFT => 'Borrador',
            self::STATUS_SCHEDULED => 'Programada',
            self::STATUS_PUBLISHED => 'Publicada',
            self::STATUS_FAILED => 'Falló',
            self::STATUS_CANCELLED => 'Cancelada',
        ];
    }
}
