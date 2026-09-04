<?php

namespace App\Models;

use App\Support\ImageStorage;
use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una de las imagenes de una publicacion.
 *
 * Es una foto de la ficha O una imagen suelta, nunca las dos. La diferencia no
 * es tecnica sino DE QUIEN ES: la de la ficha es de una clienta y solo llega
 * hasta aca con su permiso; la suelta -- la vitrina, el equipo, un flyer -- no
 * es de nadie y no necesita ninguno.
 */
class SocialPostImage extends Model
{
    use BelongsToBusiness;

    /** El tope de un carrusel de Instagram. Mas y rechaza la publicacion. */
    public const MAX_PER_POST = 10;

    protected $fillable = [
        'business_id', 'social_post_id', 'client_photo_id', 'image_path', 'position',
    ];

    protected function casts(): array
    {
        return ['position' => 'integer'];
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(SocialPost::class, 'social_post_id');
    }

    public function clientPhoto(): BelongsTo
    {
        return $this->belongsTo(ClientPhoto::class);
    }

    /**
     * Donde esta el archivo, venga de donde venga.
     *
     * Devuelve null cuando la foto de la ficha se borro: la fila sobrevive
     * -- borrar la publicacion entera porque alguien limpio una ficha seria
     * un efecto que nadie espera -- pero ya no tiene nada que mostrar, y el
     * despacho lo trata como material faltante.
     */
    public function url(): ?string
    {
        return ImageStorage::url($this->image_path ?: $this->clientPhoto?->image_path);
    }

    /** Si viene de la ficha de una clienta. */
    public function isFromClient(): bool
    {
        return $this->client_photo_id !== null;
    }
}
