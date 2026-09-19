<?php

namespace App\Ai;

use Illuminate\Support\Facades\Cache;

/**
 * Los servicios que NO cupieron en la lista que se acaba de mandar.
 *
 * Una lista de WhatsApp aguanta diez filas y la categoría Manicure de
 * Luxury tiene veintitrés. Antes se mandaban diez y se le decía «hay 13
 * más, si no ves el tuyo escríbelo» -- que es pedirle que deletree un
 * nombre de catálogo, justo lo que esta pantalla vino a evitar. Ahora la
 * décima fila dice «No veo el mío» y trae la siguiente tanda.
 *
 * Hace falta guardarlos porque de la fila tocada solo vuelve el TÍTULO:
 * WhatsApp no nos devuelve en qué página iba. Sin esta memoria, «No veo
 * el mío» volvería a buscar desde cero y le mostraría los mismos diez.
 *
 * Vive en caché y no en la conversación porque es de un momento: si
 * vuelve mañana, la lista se arma otra vez desde el catálogo, que para
 * entonces puede haber cambiado.
 */
final class ServiciosPendientes
{
    // Un cuarto de hora: lo que dura mirar una lista y decidir. Más que
    // eso y la segunda tanda le llegaría a alguien que ya se olvidó.
    private const TTL_SEGUNDOS = 900;

    /** Lo que dice la fila que trae la siguiente tanda. */
    public const VER_MAS = 'No veo el mío';

    /** @param list<string> $nombres */
    public static function guardar(string $phone, array $nombres): void
    {
        if ($nombres === []) {
            self::olvidar($phone);

            return;
        }

        Cache::put(self::clave($phone), array_values($nombres), self::TTL_SEGUNDOS);
    }

    /** @return list<string> */
    public static function ver(string $phone): array
    {
        return Cache::get(self::clave($phone), []);
    }

    public static function olvidar(string $phone): void
    {
        Cache::forget(self::clave($phone));
    }

    /**
     * ¿Lo que dijo es "muéstrame los otros"?
     *
     * Se compara con holgura porque puede llegar tocado (el título
     * exacto) o escrito a mano, y en ese caso con cualquier ortografía.
     */
    public static function pideVerMas(string $dicho): bool
    {
        $limpio = strtr(
            mb_strtolower(trim($dicho)),
            ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n'],
        );

        foreach (['no veo el mio', 'ver mas', 'ver mas servicios', 'otros servicios', 'mostrar mas'] as $frase) {
            if (str_contains($limpio, $frase)) {
                return true;
            }
        }

        return false;
    }

    private static function clave(string $phone): string
    {
        return 'ia:servicios-pendientes:'.ltrim($phone, '+');
    }
}
