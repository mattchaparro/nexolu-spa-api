<?php

namespace App\Ai;

/**
 * La fecha y la franja, sacadas del texto CRUDO de la clienta.
 *
 * El modelo arma los argumentos de las herramientas y a veces los arma
 * mal: Valentina escribió "para mañana después de las 5" y la llamada
 * salió con fecha=hoy — el bot le contestó que hoy no había horas, dos
 * veces. Lo que la clienta escribió es un dato, no una interpretación:
 * si dijo "mañana", "el jueves" o "en la tarde", eso se extrae en código
 * y MANDA sobre lo que el modelo ponga en `fecha`/`franja` durante los
 * minutos siguientes (ver UltimoPedido::completar).
 *
 * Resuelve solo lo inequívoco (hoy, mañana, pasado mañana, días de la
 * semana, las tres franjas). Lo demás — "el 25", "en quince días" — se
 * queda con el modelo: ante la duda, no se manda.
 *
 * La resolución a fecha concreta ocurre después, con FechaDicha y la
 * zona del negocio (América/Bogotá): aquí solo se captura la palabra.
 */
final class DateInText
{
    /**
     * Cuántos segundos manda lo extraído por encima del modelo.
     *
     * Era 180 y no alcanzó: Gloria dijo "mañana" en el turno 7 y para el
     * turno 9 -- una señora mayor no teclea rápido -- la ventana había
     * vencido, el modelo metió fecha=hoy y el bot buscó "el lunes 21"
     * siendo lunes 21. Veinte minutos cubren una conversación lenta
     * completa; si en ese lapso ella cambia de fecha con palabras que
     * esto entiende, se actualiza sola, y el pedido entero vence a los 30.
     */
    private const WINDOW_SECONDS = 1200;

    /**
     * @return array{fecha: ?string, franja: ?string}
     */
    public static function find(string $text): array
    {
        $t = self::plain($text);

        $franja = self::daypart($t);

        /*
         * "mañana en la mañana": la frase de franja se quita ANTES de
         * buscar la fecha, para que el "mañana" que quede sea el del día.
         */
        $sinFranja = preg_replace('/\b(en|por|de|a) la (manana|tarde|noche)\b/u', ' ', $t) ?? $t;

        $fecha = null;

        foreach (['pasado manana' => 'pasado mañana', 'manana' => 'mañana', 'hoy' => 'hoy'] as $aguja => $dicho) {
            if (preg_match('/\b'.$aguja.'\b/u', $sinFranja)) {
                $fecha = $dicho;
                break;
            }
        }

        if ($fecha === null && preg_match('/\b(lunes|martes|miercoles|jueves|viernes|sabado|domingo)\b/u', $sinFranja, $m)) {
            $fecha = $m[1];
        }

        return ['fecha' => $fecha, 'franja' => $franja];
    }

    /**
     * Extrae y deja mandando en el pedido lo que la clienta dijo.
     *
     * Se llama con cada mensaje entrante, antes del modelo. Los campos
     * encontrados quedan en UltimoPedido con una ventana corta en la que
     * pisan lo que el modelo mande — pasada la ventana vuelven a ser un
     * relleno normal (si ella cambia de fecha con palabras que esto no
     * entiende, el modelo recupera la voz).
     */
    public static function remember(string $phone, string $text): void
    {
        ['fecha' => $fecha, 'franja' => $franja] = self::find($text);

        if ($fecha === null && $franja === null) {
            return;
        }

        $manda = array_keys(array_filter(['fecha' => $fecha !== null, 'franja' => $franja !== null]));

        UltimoPedido::guardar($phone, [
            ...UltimoPedido::ver($phone),
            ...array_filter(['fecha' => $fecha, 'franja' => $franja]),
            'texto_manda' => $manda,
            'texto_manda_hasta' => now()->getTimestamp() + self::WINDOW_SECONDS,
        ]);
    }

    /**
     * Deja mandando campos que la clienta acaba de fijar SIN escribir.
     *
     * Un botón tocado («Mañana», un día de la lista) es palabra suya igual
     * que el texto: el valor ya debe estar guardado en el pedido; esto
     * solo le da la autoridad de la ventana.
     *
     * @param  list<string>  $campos
     */
    public static function pin(string $phone, array $campos): void
    {
        $pedido = UltimoPedido::ver($phone);

        UltimoPedido::guardar($phone, [
            ...$pedido,
            'texto_manda' => array_values(array_unique([...($pedido['texto_manda'] ?? []), ...$campos])),
            'texto_manda_hasta' => now()->getTimestamp() + self::WINDOW_SECONDS,
        ]);
    }

    private static function daypart(string $t): ?string
    {
        if (preg_match('/\b(en|por|de|a) la manana\b/u', $t) || preg_match('/\btemprano\b/u', $t)) {
            return 'mañana';
        }

        if (preg_match('/\b(en|por|de|a) la tarde\b/u', $t)) {
            return 'tarde';
        }

        if (preg_match('/\b(en|por|de|a) la noche\b/u', $t)) {
            return 'noche';
        }

        /*
         * "después de las 5" en un salón es de la tarde; con 18 o más es
         * de la noche. Las horas de la mañana ("después de las 9") son
         * ambiguas y no se tocan.
         */
        if (preg_match('/\b(despues|a partir) de las? (\d{1,2})\b/u', $t, $m)) {
            $hora = (int) $m[2];

            return match (true) {
                $hora >= 18 => 'noche',
                $hora >= 12 || $hora <= 7 => 'tarde',
                default => null,
            };
        }

        return null;
    }

    private static function plain(string $text): string
    {
        $t = mb_strtolower(trim($text));
        $t = strtr($t, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n']);

        return preg_replace('/\s+/u', ' ', $t) ?? $t;
    }
}
