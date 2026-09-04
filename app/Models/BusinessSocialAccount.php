<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * La cuenta de Instagram conectada de un negocio.
 *
 * EL TOKEN NO SE LEE POR ACCIDENTE. El cast `encrypted` lo descifra solo
 * cuando alguien pide `$account->access_token`, y en la base queda ilegible:
 * un `select *` de soporte, un backup en un portatil o un volcado para
 * depurar no exponen la llave para publicar como ese negocio.
 *
 * Va oculto en `$hidden` ademas del cifrado, que es otra capa por otra razon:
 * el cifrado protege la base, `$hidden` protege las respuestas JSON. Un
 * `return $account` distraido en un controlador no puede filtrarlo.
 */
class BusinessSocialAccount extends Model
{
    use BelongsToBusiness;

    public const PROVIDER_INSTAGRAM = 'instagram';

    /**
     * Cuantos dias antes de vencer se considera "por vencer".
     *
     * Diez y no uno: el sintoma de un token vencido es que las publicaciones
     * dejan de salir en silencio, y avisar la vispera no le da tiempo a nadie
     * a resolverlo un domingo.
     */
    public const EXPIRY_WARNING_DAYS = 10;

    protected $fillable = [
        'business_id', 'provider', 'external_id', 'username',
        'access_token', 'token_expires_at', 'last_published_at',
        'is_active', 'connected_by_user_id',
    ];

    protected $hidden = ['access_token'];

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'token_expires_at' => 'datetime',
            'last_published_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    public function connectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'connected_by_user_id');
    }

    /** Se puede publicar con esta cuenta ahora mismo. */
    public function isUsable(): bool
    {
        return $this->is_active && ! $this->hasExpired();
    }

    public function hasExpired(): bool
    {
        return $this->token_expires_at !== null
            && $this->token_expires_at->isPast();
    }

    /** Le quedan pocos dias. El panel lo dice antes de que deje de servir. */
    public function expiresSoon(): bool
    {
        return $this->token_expires_at !== null
            && ! $this->hasExpired()
            && $this->token_expires_at->lessThan(CarbonImmutable::now()->addDays(self::EXPIRY_WARNING_DAYS));
    }

    /**
     * En palabras, para la pantalla y para el log.
     *
     * El motivo importa: "caducó" se arregla reconectando y "apagada" con un
     * interruptor. Un "no se puede publicar" a secas manda a alguien a buscar
     * el problema equivocado.
     */
    public function unusableReason(): ?string
    {
        if ($this->hasExpired()) {
            return 'El permiso de Instagram caducó. Hay que volver a conectar la cuenta.';
        }

        if (! $this->is_active) {
            return 'La publicación automática está apagada para este negocio.';
        }

        return null;
    }
}
