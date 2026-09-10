<?php

namespace App\Support\Ratings;

/**
 * Una nota, leida sobre su propia escala.
 *
 * Existe porque las notas de Luxury no vienen todas sobre cinco. La encuesta
 * vieja de ManyChat preguntaba con distinta cantidad de botones -- atencion
 * sobre 5, servicio sobre 4, puntualidad sobre 3 -- y la propia pregunta las
 * tres sobre 5. Promediar los numeros crudos mezcla cosas que no son
 * comparables: un 3 de 3 es lo mejor que una clienta puede dar, y un 3 de 5 es
 * un reclamo.
 *
 * Se normaliza con (nota - 1) / (escala - 1), no con nota / escala. La opcion
 * mas baja que se OFRECE es 1, no 0: quien puso el peor boton de una escala de
 * 3 esta igual de descontento que quien puso el peor de una de 5, y
 * `1/3 = 33%` diria que el primero quedo algo satisfecho.
 */
final class Nota
{
    /**
     * De 0 a 100, o null si no hay nota.
     *
     * Null y no cero: "no califico" no es "califico psimo", y meterlo como
     * cero en un promedio hunde a alguien por opiniones que nadie escribio.
     */
    public static function porcentaje(?int $nota, ?int $escala): ?float
    {
        if ($nota === null || $escala === null || $escala < 2) {
            return null;
        }

        $acotada = max(1, min($nota, $escala));

        return round((($acotada - 1) / ($escala - 1)) * 100, 1);
    }

    /**
     * El promedio de varias notas con escalas distintas, de 0 a 100.
     *
     * @param  iterable<array{0:?int, 1:?int}>  $notas  pares [nota, escala]
     */
    public static function promedio(iterable $notas): ?float
    {
        $suma = 0.0;
        $cuantas = 0;

        foreach ($notas as [$nota, $escala]) {
            $p = self::porcentaje($nota, $escala);

            if ($p !== null) {
                $suma += $p;
                $cuantas++;
            }
        }

        return $cuantas === 0 ? null : round($suma / $cuantas, 1);
    }

    /**
     * El mismo promedio dicho sobre cinco, para mostrarlo como estrellas.
     *
     * Es la misma cifra: la pantalla decide si dice "98%" o "4,9". Tenerlo en
     * un solo sitio evita que una pantalla redondee distinto que la otra y que
     * dos numeros que deberian ser iguales no lo parezcan.
     */
    public static function sobreCinco(?float $porcentaje): ?float
    {
        return $porcentaje === null ? null : round(1 + ($porcentaje / 100) * 4, 2);
    }
}
