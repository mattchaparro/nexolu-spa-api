<?php

namespace App\Ai;

use Illuminate\Support\Facades\Cache;

/**
 * Lo último que la clienta pidió, para no hacerla repetirlo.
 *
 * Las clientas simuladas destaparon el mismo derrumbe ocho veces de
 * ocho: en cuanto la conversación pasaba de dos turnos, el modelo perdía
 * algo que ya le habían dicho. Tocaba "Tradicional" después de pedir
 * "mañana en la mañana" y el modelo llamaba a la agenda con «hoy»;
 * tocaba "6 pm" y el modelo llamaba sin servicio, la herramienta
 * rechazaba la llamada y la clienta leía "no pude consultar la agenda".
 * Nadie llegó a agendar.
 *
 * El modelo no es un buen sitio para guardar estado. Esto sí lo es: cada
 * vez que una herramienta entiende un pedido -- qué servicios, qué día,
 * qué franja, para cuántas -- lo deja acá, y la siguiente llamada
 * completa lo que el modelo olvidó. Y cuando lo que llega es un TOQUE
 * sobre algo que se ofreció, lo guardado manda por encima de lo que el
 * modelo diga: la clienta ya dijo el día; el "hoy" se lo inventó él.
 *
 * Vive media hora, en caché y no en la conversación, porque es de una
 * gestión: si vuelve mañana empieza de cero.
 */
final class UltimoPedido
{
    private const TTL_SEGUNDOS = 1800;

    /** Lo que se puede completar desde lo guardado. */
    private const CAMPOS = ['fecha', 'franja', 'juntas', 'empleado', 'sede', 'para_quien', 'nombres'];

    /**
     * @param  array<string, mixed>  $pedido  servicios (nombres reales), fecha (como la dijo),
     *                                        franja, juntas, empleado, sede, opciones (títulos ofrecidos)
     */
    public static function guardar(string $phone, array $pedido): void
    {
        $limpio = array_filter($pedido, fn ($v) => $v !== null && $v !== '' && $v !== []);

        Cache::put(self::clave($phone), $limpio, self::TTL_SEGUNDOS);
    }

    /** @return array<string, mixed> */
    public static function ver(string $phone): array
    {
        return Cache::get(self::clave($phone), []);
    }

    public static function olvidar(string $phone): void
    {
        Cache::forget(self::clave($phone));
    }

    /**
     * Los argumentos de una llamada, completados con lo que ya se sabía.
     *
     * Dos reglas, y el orden importa:
     *
     * 1. Si `servicio` es uno de los que se le OFRECIERON (lo tocó), el
     *    día, la franja y el resto del pedido guardado pisan lo que traiga
     *    la llamada. Es el turno donde el modelo peor recuerda, y lo que
     *    la clienta dijo antes vale más que lo que él supone ahora.
     * 2. Lo que no venga se rellena con lo guardado -- incluido el
     *    servicio, para que "6 pm" después de ver las horas no llegue sin
     *    saber de qué.
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public static function completar(string $phone, array $arguments): array
    {
        $pedido = self::ver($phone);

        if ($pedido === []) {
            return $arguments;
        }

        $eleccion = isset($arguments['servicio'])
            ? self::destruncar((string) $arguments['servicio'], $pedido['opciones'] ?? [])
            : null;
        $tocado = $eleccion !== null;

        if ($tocado) {
            // El nombre COMPLETO del catalogo, no el recorte del boton.
            $arguments['servicio'] = $eleccion;
        }

        /*
         * Lo que la clienta ESCRIBIO en su ultimo mensaje ("mañana", "en
         * la tarde") manda sobre lo que el modelo haya puesto, mientras
         * dure la ventana (ver DateInText::remember). Valentina dijo
         * "para mañana despues de las 5" y el modelo llamo con fecha=hoy:
         * su palabra vale mas que la interpretacion.
         */
        $textoManda = ($pedido['texto_manda_hasta'] ?? 0) >= now()->getTimestamp()
            ? ($pedido['texto_manda'] ?? [])
            : [];

        foreach (self::CAMPOS as $campo) {
            $traeAlgo = isset($arguments[$campo]) && $arguments[$campo] !== '';

            if (isset($pedido[$campo]) && ($tocado || in_array($campo, $textoManda, true) || ! $traeAlgo)) {
                $arguments[$campo] = $pedido[$campo];
            }
        }

        $sinServicio = empty($arguments['servicio']) && empty($arguments['servicios']);

        if ($sinServicio && ! empty($pedido['servicios'])) {
            $arguments['servicios'] = array_values($pedido['servicios']);
            unset($arguments['servicio']);
        }

        return $arguments;
    }

    /**
     * ¿Este texto es una de las opciones ofrecidas? Devuelve cuál, completa.
     *
     * No basta comparar igual por igual: WhatsApp corta los títulos de las
     * listas a 24 caracteres, así que lo que vuelve al tocar «Recubrimiento
     * Rubber sin esmaltado» es «Recubrimiento Rubber si». Con el catálogo
     * real (nombres largos) el toque no calzaba con nada, caía al modelo y
     * el modelo volvía a preguntar lo que la clienta acababa de tocar.
     *
     * @param  list<string>  $opciones
     */
    public static function destruncar(string $texto, array $opciones): ?string
    {
        // Fuera puntos suspensivos (los pone quien recorta) y espacios.
        $t = rtrim(self::plano(preg_replace('/(\.{3}|…)\s*$/u', '', $texto) ?? $texto));

        if ($t === '') {
            return null;
        }

        foreach ($opciones as $opcion) {
            $o = self::plano((string) $opcion);

            // Igual, o el recorte de 24 del título completo. El mínimo de
            // 15 evita que un toque corto "elija" por accidente.
            if ($o === $t || (mb_strlen($t) >= 15 && str_starts_with($o, $t))) {
                return (string) $opcion;
            }
        }

        return null;
    }

    /**
     * La clave de un mapa "título tocado → valor", aguantando el recorte.
     *
     * Mismo problema que `destruncar`, pero para las listas que guardan un
     * valor por fila (las citas, los días): Alejandro tocó la fila «Sáb. 26
     * sep. · 3:30 pm» y volvió «Sáb. 26 sep. · 3:30» -- sin el "pm". El bot
     * buscó exacto, no la encontró, y la cancelación terminó en un "no
     * entendí la fecha".
     *
     * @param  array<string, mixed>  $mapa
     */
    public static function claveDe(array $mapa, string $texto): ?string
    {
        $t = rtrim(self::plano(preg_replace('/(\.{3}|…)\s*$/u', '', $texto) ?? $texto));

        if ($t === '' || $mapa === []) {
            return null;
        }

        if (array_key_exists($t, $mapa)) {
            return $t;
        }

        foreach (array_keys($mapa) as $clave) {
            // El mínimo de 12 evita que un toque corto calce por accidente.
            if (mb_strlen($t) >= 12 && str_starts_with((string) $clave, $t)) {
                return (string) $clave;
            }
        }

        return null;
    }

    private static function plano(string $texto): string
    {
        return trim(mb_strtolower(strtr($texto, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n'])));
    }

    private static function clave(string $phone): string
    {
        return 'ia:ultimo-pedido:'.ltrim($phone, '+');
    }
}
