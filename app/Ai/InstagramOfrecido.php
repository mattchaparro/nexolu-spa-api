<?php

namespace App\Ai;

use Illuminate\Support\Facades\Cache;

/**
 * ¿Ya se le ofreció el Instagram del salón a esta clienta hoy?
 *
 * El botón «Seguir en Instagram» va en la confirmación cuando la ventana
 * está abierta. Cuando no --una cita agendada desde el panel a alguien que
 * no ha escrito--, la confirmación sale como plantilla, y la plantilla NO
 * lo lleva: Meta clasifica como publicidad cualquier aviso que invite a
 * seguir una cuenta, y una confirmación publicitaria deja de llegarle a
 * quien apagó la publicidad. Decisión de Alejandro (25-sep): mantener la
 * confirmación como aviso de servicio y ofrecer el Instagram la primera vez
 * que la clienta toca uno de sus botones, que es cuando la ventana se abre.
 *
 * Una vez al día: tocar tres temas no puede traer tres veces el mismo botón.
 */
final class InstagramOfrecido
{
    private const TTL_SEGUNDOS = 86400;

    public static function marcar(string $phone): void
    {
        Cache::put(self::clave($phone), true, self::TTL_SEGUNDOS);
    }

    /** Lo reserva para esta vez; false si ya se ofreció hoy. */
    public static function reservar(string $phone): bool
    {
        return Cache::add(self::clave($phone), true, self::TTL_SEGUNDOS);
    }

    public static function soltar(string $phone): void
    {
        Cache::forget(self::clave($phone));
    }

    private static function clave(string $phone): string
    {
        return 'ia:instagram-ofrecido:'.ltrim($phone, '+');
    }
}
