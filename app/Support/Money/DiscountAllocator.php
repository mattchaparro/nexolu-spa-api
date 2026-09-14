<?php

namespace App\Support\Money;

/**
 * Reparte un descuento entre las lineas de una cuenta.
 *
 * Es logica pura a proposito: no toca la base ni conoce modelos. Repartir
 * plata sin perder pesos es la clase de calculo que hay que poder probar en
 * milisegundos y con veinte casos, no levantando un servidor HTTP por cada
 * uno.
 *
 * El reparto es proporcional al peso de cada linea, y la ULTIMA absorbe el
 * redondeo. Sin eso, tres lineas y un descuento que no divide exacto dejan
 * centavos sueltos y el cierre de caja no cuadra por una diferencia que nadie
 * sabe explicar.
 */
final class DiscountAllocator
{
    /**
     * @param  list<float>  $prices  Precio de lista de cada linea.
     * @param  float  $discount  Descuento total a repartir.
     * @return list<float>  Lo que efectivamente se cobra por cada linea.
     */
    public static function allocate(array $prices, float $discount): array
    {
        if ($prices === []) {
            return [];
        }

        /*
         * Todo en PESOS ENTEROS, desde la entrada.
         *
         * En Colombia no circulan centavos: nadie cobra 49.210,53 ni paga una
         * comision de 24.605,26. Redondeando tambien lo que entra, la garantia
         * de que la suma cuadre vuelve a ser exacta -- con precios en centavos
         * y partes enteras no podia cuadrar nunca.
         */
        $prices = array_map(fn ($p) => round((float) $p), $prices);
        $discount = round($discount);

        $subtotal = array_sum($prices);

        if ($discount <= 0 || $subtotal <= 0) {
            return array_map(fn (float $p) => round($p), $prices);
        }

        if ($discount > $subtotal) {
            throw new \InvalidArgumentException('El descuento no puede superar el total.');
        }

        $last = count($prices) - 1;
        $distributed = 0.0;
        $charged = [];

        foreach ($prices as $i => $price) {
            if ($i === $last) {
                // La ultima linea se lleva lo que quede, no su proporcion
                // redondeada: es lo que garantiza que la suma de las partes
                // sea exactamente el total.
                $share = round($discount - $distributed);
            } else {
                $share = round($discount * ($price / $subtotal));
                $distributed += $share;
            }

            $charged[] = round($price - $share);
        }

        return $charged;
    }

    /**
     * Comision de cada linea, calculada sobre lo COBRADO.
     *
     * Sobre lo cobrado y no sobre el precio de lista: si no, el negocio paga
     * comision por plata que nunca entro.
     *
     * @param  list<float>  $charged
     * @param  list<float|null>  $rates
     * @return list<float>
     */
    public static function commissions(array $charged, array $rates): array
    {
        $result = [];

        foreach ($charged as $i => $amount) {
            $result[] = round($amount * (float) ($rates[$i] ?? 0));
        }

        return $result;
    }
}
