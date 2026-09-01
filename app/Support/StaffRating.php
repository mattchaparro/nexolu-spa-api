<?php

namespace App\Support;

/**
 * La puntuacion publica de una persona del equipo.
 *
 * Logica pura, sin base de datos, porque la regla que importa no es el
 * promedio -- eso es una division -- sino CUANDO se puede mostrar. Y esa
 * decision tiene consecuencias sobre una persona real, asi que merece estar
 * escrita en un solo lugar y probada.
 *
 * DOS REGLAS, y las dos protegen a quien trabaja:
 *
 * 1. MINIMO DE CALIFICACIONES. Con dos respuestas, una clienta que tuvo un mal
 *    dia deja a alguien en 3.0 para siempre en la vitrina del local. Un
 *    promedio de pocos datos no es informacion, es ruido con cara de dato.
 *
 * 2. SIN CALIFICACIONES NO SE INVENTA NADA. Ni 0, ni 5, ni "nuevo". Se
 *    devuelve null y la pagina simplemente no muestra estrellas: un 0.0 al
 *    lado de una foto lee como "pesimo", no como "todavia no sabemos".
 */
final class StaffRating
{
    /**
     * Cuantas calificaciones hacen falta antes de publicar un promedio.
     *
     * Cinco es poco para la estadistica y suficiente para la vitrina: menos
     * castiga a quien acaba de entrar, mas deja a casi todo el equipo sin
     * estrellas durante meses y la seccion pierde el sentido.
     */
    public const MINIMO = 5;

    /**
     * El promedio a mostrar, o null si todavia no hay suficiente.
     *
     * @param  list<int|float|null>  $scores  Las notas crudas, tal cual salieron.
     */
    public static function average(array $scores, int $minimo = self::MINIMO): ?float
    {
        $validos = self::valid($scores);

        if (count($validos) < $minimo) {
            return null;
        }

        return round(array_sum($validos) / count($validos), 1);
    }

    /**
     * Cuantas calificaciones cuentan.
     *
     * Se expone junto al promedio porque "4.8" solo, sin decir de cuantas, es
     * una cifra que no se puede juzgar. La pagina muestra las dos.
     *
     * @param  list<int|float|null>  $scores
     */
    public static function count(array $scores): int
    {
        return count(self::valid($scores));
    }

    /**
     * Descarta lo que no es una nota utilizable.
     *
     * Nulos -- alguien respondio la encuesta sin poner estrellas -- y valores
     * fuera de rango, que solo pueden venir de un payload manipulado. Se
     * ignoran en silencio en vez de reventar: una nota rara no puede tumbar la
     * pagina publica del negocio.
     *
     * @param  list<int|float|null>  $scores
     * @return list<float>
     */
    private static function valid(array $scores): array
    {
        $result = [];

        foreach ($scores as $score) {
            if ($score === null || ! is_numeric($score)) {
                continue;
            }

            $value = (float) $score;

            if ($value >= 1 && $value <= 5) {
                $result[] = $value;
            }
        }

        return $result;
    }
}
