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
 * décima fila dice «Muéstrame más servicios» y trae la siguiente
 * tanda.
 *
 * Hace falta guardarlos porque de la fila tocada solo vuelve el TÍTULO:
 * WhatsApp no nos devuelve en qué página iba. Sin esta memoria, tocar
 * esa fila volvería a buscar desde cero y le mostraría los mismos diez.
 *
 * Vive en caché y no en la conversación porque es de un momento: si
 * vuelve mañana, la lista se arma otra vez desde el catálogo, que para
 * entonces puede haber cambiado.
 */
final class ServiciosPendientes
{
    /*
     * Lo mismo que el pedido (UltimoPedido): ocho horas. Era un cuarto de
     * hora, y quien volvía de hacer otra cosa tocaba «Muéstrame más
     * servicios» en una lista que el bot ya había olvidado. Si el pedido
     * sigue vivo, la lista que lo acompaña también.
     */
    private const TTL_SEGUNDOS = 28800;

    /**
     * Lo que dice la fila que trae la siguiente tanda.
     *
     * En imperativo y no «No veo el mío»: la fila es un botón, y un
     * botón se nombra por lo que HACE, no por lo que le pasa a quien lo
     * toca. Veintitrés caracteres, justo debajo del tope de Meta.
     */
    public const VER_MAS = 'Muéstrame más servicios';

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

        foreach ([
            'muestrame mas', 'muestreme mas', 'mostrar mas', 'ver mas',
            'otros servicios', 'mas servicios', 'no veo el mio',
        ] as $frase) {
            if (str_contains($limpio, $frase)) {
                return true;
            }
        }

        return false;
    }

    /**
     * ¿Es el TOQUE de la fila, y no una frase parecida?
     *
     * `pideVerMas` es a propósito holgado para lo escrito a mano, y por
     * eso no sirve para decidir temprano: «ver más días» también lo
     * cumple, y quien está eligiendo día no quiere servicios. Esto es lo
     * contrario -- el título exacto, aguantando el recorte a 24 de
     * WhatsApp -- y con eso sí se puede pasar por encima de lo que se
     * haya preguntado: tocar la fila es inequívoco.
     */
    public static function esLaFila(string $dicho): bool
    {
        $limpio = self::plano($dicho);
        $fila = self::plano(self::VER_MAS);

        return $limpio !== '' && ($limpio === $fila || (mb_strlen($limpio) >= 15 && str_starts_with($fila, $limpio)));
    }

    private static function plano(string $texto): string
    {
        // Sin los puntos suspensivos que pone quien recorta el título.
        $sin = preg_replace('/(\.{3}|…)\s*$/u', '', trim($texto)) ?? $texto;

        return rtrim(strtr(
            mb_strtolower(trim($sin)),
            ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n'],
        ));
    }

    private static function clave(string $phone): string
    {
        return 'ia:servicios-pendientes:'.ltrim($phone, '+');
    }
}
