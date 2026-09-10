<?php

namespace App\Support\Ratings;

/**
 * Distinguir una opinion de un "no, gracias".
 *
 * La ultima pregunta de la encuesta es abierta -- algo como "¿quieres agregar
 * algo mas?" -- y la mayoria contesta que no. De los 60 comentarios de Luxury,
 * 43 son "no", "no gracias" o "gracias":
 *
 *     no             28
 *     no gracias      8
 *     gracias         3
 *     no, gracias     2
 *
 * Eso no es una opinion, es una respuesta de cortesia a otra pregunta.
 * Mostrarle a una manicurista una lista de "No" "No" "No" es peor que no
 * mostrarle nada: parece que las clientas le estan diciendo que no a algo.
 *
 * NO se filtra por largo. "La mejor" tiene ocho letras y es exactamente lo que
 * alguien quiere leer; un umbral de caracteres la tiraria junto con los "no".
 * Se filtra por lo que DICE: si todas sus palabras son cortesia o negacion,
 * no hay opinion adentro.
 */
final class Comentario
{
    /**
     * Palabras que por si solas no son una opinion.
     *
     * A proposito NO estan "todo" ni "bien": "todo bien" es una opinion, corta
     * pero opinion. Ante la duda se conserva -- perder un elogio de verdad
     * cuesta mas que dejar pasar un "ok".
     */
    private const CORTESIA = [
        'no', 'nop', 'nope', 'ninguno', 'ninguna', 'nada', 'nel',
        'gracias', 'grasias', 'graciad', 'muchas', 'mucha', 'mil',
        'ok', 'oka', 'okay', 'okey', 'vale', 'listo', 'ya',
        'por', 'favor', 'de', 'las', 'los',
    ];

    public static function esOpinion(?string $texto): bool
    {
        $palabras = self::palabras($texto);

        if ($palabras === []) {
            return false;
        }

        foreach ($palabras as $palabra) {
            if (! in_array($palabra, self::CORTESIA, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * El texto reducido a sus palabras, sin tildes, signos ni emojis.
     *
     * Sin esto, "¡Gracias! 😊" y "no , gracias 🫂" no coincidirian con nada:
     * la gente escribe con emojis y signos, no con la forma canonica.
     *
     * @return list<string>
     */
    private static function palabras(?string $texto): array
    {
        if ($texto === null) {
            return [];
        }

        $limpio = mb_strtolower(trim($texto));

        $limpio = strtr($limpio, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u',
        ]);

        // Todo lo que no sea letra o numero pasa a ser separador: signos,
        // emojis y espacios raros por igual.
        $limpio = preg_replace('/[^a-z0-9ñ]+/u', ' ', $limpio) ?? '';

        return array_values(array_filter(explode(' ', trim($limpio)), fn ($p) => $p !== ''));
    }
}
