<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una publicacion del negocio en sus redes.
 *
 * El ciclo completo cabe en una linea: alguien (o el planificador) propone,
 * una persona aprueba con fecha, llega la hora y queda lista, alguien la
 * publica.
 *
 *     draft -> scheduled -> ready -> published
 *                    \-> discarded
 *
 * Publicar NO es un efecto del reloj. El reloj mueve `scheduled` a `ready` y
 * ahi se detiene: quien cierra el ciclo es una persona. La razon esta en
 * docs/publicaciones.md y se resume en que la cuenta de Instagram de un
 * negocio pequeno es su vitrina, y un texto raro publicado a las 3am sin que
 * nadie lo mirara cuesta mas que diez publicaciones que no salieron.
 */
class SocialPost extends Model
{
    use BelongsToBusiness;

    /** Una idea. Puede no tener texto todavia, y no tiene fecha. */
    public const STATUS_DRAFT = 'draft';

    /** Aprobada y con hora. Es el unico estado que el reloj mira. */
    public const STATUS_SCHEDULED = 'scheduled';

    /** Le llego la hora. Esperando que alguien la publique. */
    public const STATUS_READY = 'ready';

    public const STATUS_PUBLISHED = 'published';

    /** Solo lo escribe un canal automatico. En modo manual no existe. */
    public const STATUS_FAILED = 'failed';

    /** No va. Se guarda igual: saber que se descarto evita reproponerlo. */
    public const STATUS_DISCARDED = 'discarded';

    /** La propuso el planificador leyendo la agenda. */
    public const SOURCE_AUTO = 'auto';

    /** La escribio una persona. */
    public const SOURCE_MANUAL = 'manual';

    /** Una foto de un trabajo hecho, con permiso de la clienta. */
    public const ANGLE_WORK = 'trabajo';

    /** Hay horas libres pronto y conviene llenarlas. */
    public const ANGLE_GAP = 'hueco';

    /** Un servicio del catalogo que se dejo de vender. */
    public const ANGLE_SERVICE = 'servicio';

    /** Quien atiende. Presenta al equipo. */
    public const ANGLE_TEAM = 'equipo';

    /** Lo que se le ocurra al negocio. */
    public const ANGLE_FREE = 'libre';

    protected $fillable = [
        'business_id', 'location_id', 'status', 'source', 'angle', 'idea_key',
        'service_id', 'subject_date', 'client_photo_id', 'image_path',
        'caption', 'hashtags', 'composed_at', 'scheduled_for', 'published_at',
        'external_ref', 'error', 'created_by_user_id', 'approved_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'hashtags' => 'array',
            'subject_date' => 'date',
            'composed_at' => 'datetime',
            'scheduled_for' => 'datetime',
            'published_at' => 'datetime',
        ];
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function clientPhoto(): BelongsTo
    {
        return $this->belongsTo(ClientPhoto::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    /**
     * Todavia se puede tocar el texto.
     *
     * Una publicada no: reescribir el texto de algo que ya salio deja el
     * calendario contando una historia que no paso.
     */
    public function isEditable(): bool
    {
        return in_array($this->status, [self::STATUS_DRAFT, self::STATUS_SCHEDULED, self::STATUS_READY], true);
    }

    /**
     * Tiene con que salir: algo que decir y algo que mostrar.
     *
     * Se exige imagen porque esto es para un spa de unas. Una publicacion sin
     * foto en Instagram no es una publicacion: es un mensaje que nadie ve.
     */
    public function isComplete(): bool
    {
        return trim((string) $this->caption) !== ''
            && ($this->image_path !== null || $this->client_photo_id !== null);
    }

    /** @return array<string, string> */
    public static function statusLabels(): array
    {
        return [
            self::STATUS_DRAFT => 'Propuesta',
            self::STATUS_SCHEDULED => 'Programada',
            self::STATUS_READY => 'Lista para publicar',
            self::STATUS_PUBLISHED => 'Publicada',
            self::STATUS_FAILED => 'Falló',
            self::STATUS_DISCARDED => 'Descartada',
        ];
    }

    /** @return array<string, string> */
    public static function angleLabels(): array
    {
        return [
            self::ANGLE_WORK => 'Trabajo hecho',
            self::ANGLE_GAP => 'Horas libres',
            self::ANGLE_SERVICE => 'Servicio del catálogo',
            self::ANGLE_TEAM => 'El equipo',
            self::ANGLE_FREE => 'Libre',
        ];
    }

    /** @return list<string> */
    public static function angles(): array
    {
        return array_keys(self::angleLabels());
    }
}
