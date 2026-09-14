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
        if ($charged === []) {
            return [];
        }

        /*
         * La ULTIMA se deduce, igual que en `Reparto`.
         *
         * Redondear cada linea por su cuenta gana o pierde un peso. El caso
         * que lo destapo: un combo de 85.000 al 50% partido en dos servicios
         * daba 24.606 + 17.895 = 42.501, cuando la comision de esa visita es
         * 42.500 -- y eso es exactamente lo que el sistema viejo, que cobra el
         * combo en UNA linea, tenia guardado.
         *
         * El total exacto se calcula ANTES de redondear nada y la ultima linea
         * absorbe la diferencia. Asi la suma de las comisiones es siempre la
         * comision de la visita, aunque cada linea tenga su propio porcentaje.
         */
        $exactas = [];
        $total = 0.0;

        foreach ($charged as $i => $amount) {
            $exacta = (float) $amount * (float) ($rates[$i] ?? 0);
            $exactas[] = $exacta;
            $total += $exacta;
        }

        $result = [];
        $acumulado = 0.0;
        $ultima = count($exactas) - 1;

        foreach ($exactas as $i => $exacta) {
            if ($i === $ultima) {
                break;
            }

            $parte = round($exacta);
            $acumulado += $parte;
            $result[] = $parte;
        }

        /*
         * Nunca negativa. Con porcentajes muy distintos y montos chicos, la
         * deduccion podria dar -1 y una comision negativa es un descuento que
         * nadie pidio.
         */
        $result[] = max(0.0, round($total) - $acumulado);

        return $result;
    }
}
