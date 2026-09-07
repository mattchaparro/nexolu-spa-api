<?php

namespace App\Support\Money;

/**
 * Reparte un monto entre varias partes, a prorrata, sin perder ni un peso.
 *
 * Vive aparte de quien lo usa porque la regla que lo hace correcto es sutil:
 * la ULTIMA parte no se calcula, se deduce. Repartir 50.000 entre dos partes
 * redondeando cada una por su cuenta puede dar 24.999,50 + 25.000,50, o peor,
 * dos veces 25.000,01 que suman 50.000,02. Ese peso suelto aparece semanas
 * despues como un descuadre de caja que nadie sabe explicar.
 *
 * Deduciendo la ultima, la suma es EXACTAMENTE el monto, siempre.
 */
final class Reparto
{
    /**
     * @param  list<float>  $pesos  Cuanto vale cada parte (precio de lista, por ejemplo).
     * @return list<float> Un monto por parte, en el mismo orden.
     */
    public static function proporcional(float $monto, array $pesos): array
    {
        $cuantas = count($pesos);

        if ($cuantas === 0) {
            return [];
        }

        if ($cuantas === 1) {
            return [round($monto, 2)];
        }

        /*
         * Pesos invalidos (todos cero, o negativos) se reparten en partes
         * iguales en vez de romper. Un catalogo con un servicio a precio cero
         * es raro pero existe -- una cortesia, un servicio nuevo sin precio --
         * y no es motivo para perder la cita entera.
         */
        $limpios = array_map(fn ($p) => max(0.0, (float) $p), $pesos);
        $total = array_sum($limpios);

        if ($total <= 0.0) {
            $limpios = array_fill(0, $cuantas, 1.0);
            $total = (float) $cuantas;
        }

        $reparto = [];
        $acumulado = 0.0;

        for ($i = 0; $i < $cuantas - 1; $i++) {
            $parte = round($monto * $limpios[$i] / $total, 2);
            $acumulado += $parte;
            $reparto[] = $parte;
        }

        // La ultima absorbe el redondeo: es lo que garantiza que la suma cuadre.
        $reparto[] = round($monto - $acumulado, 2);

        return $reparto;
    }
}
