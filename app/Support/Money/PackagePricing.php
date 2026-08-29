<?php

namespace App\Support\Money;

/**
 * Cuanto cuesta un combo y cuanto se descuenta.
 *
 * Sin base de datos: recibe los precios de lista y como se expresa el
 * descuento, y devuelve el total y el descuento. Vive aparte porque un combo
 * es lo unico del catalogo cuyo precio no esta escrito en ningun lado -- se
 * calcula -- y porque un combo mal calculado cobra de menos en cada venta sin
 * que nadie lo note hasta el cierre del mes.
 *
 * IMPORTANTE: el descuento sale de aca como UN monto, no como precios nuevos
 * por linea. Eso es a proposito: el checkout ya sabe repartir un descuento
 * proporcionalmente entre las lineas (DiscountAllocator) y calcular la
 * comision sobre lo COBRADO. Si el combo reescribiera los precios de linea,
 * habria dos formas de rebajar en el sistema y solo una de las dos bajaria la
 * comision -- y la gente cobraria comision sobre plata que el negocio no
 * recibio.
 */
final class PackagePricing
{
    /** El combo vale esto, punto. */
    public const TYPE_PRICE = 'price';

    /** Un porcentaje sobre la suma de sus servicios. */
    public const TYPE_PERCENT = 'percent';

    /** Tantos pesos menos que la suma. */
    public const TYPE_FIXED = 'fixed';

    /** Sin descuento: es un atajo para agendar varias cosas juntas. */
    public const TYPE_NONE = 'none';

    /** @return list<string> */
    public static function types(): array
    {
        return [self::TYPE_PRICE, self::TYPE_PERCENT, self::TYPE_FIXED, self::TYPE_NONE];
    }

    public static function label(string $type): string
    {
        return match ($type) {
            self::TYPE_PRICE => 'Precio cerrado',
            self::TYPE_PERCENT => 'Porcentaje de descuento',
            self::TYPE_FIXED => 'Descuento en pesos',
            default => 'Sin descuento',
        };
    }

    /**
     * @param  list<float>  $prices  Precios de lista de cada servicio.
     * @return array{list_total: float, discount: float, total: float, savings_percent: float}
     */
    public static function quote(array $prices, string $type, ?float $value): array
    {
        $listTotal = round(array_sum(array_map('floatval', $prices)), 2);
        $discount = self::discountFor($listTotal, $type, $value);

        return [
            'list_total' => $listTotal,
            'discount' => $discount,
            'total' => round($listTotal - $discount, 2),
            // Lo que se le dice al cliente: "ahorras 18%".
            'savings_percent' => $listTotal > 0 ? round(($discount / $listTotal) * 100, 1) : 0.0,
        ];
    }

    private static function discountFor(float $listTotal, string $type, ?float $value): float
    {
        if ($value === null || $listTotal <= 0) {
            return 0.0;
        }

        $discount = match ($type) {
            // Un precio cerrado MAYOR que la suma no sube nada: seria un
            // recargo disfrazado de combo, y el checkout no sabe cobrar de
            // mas. Se trata como sin descuento y se ve raro en pantalla, que
            // es mejor que cobrar algo que nadie acordo.
            self::TYPE_PRICE => max(0.0, $listTotal - $value),

            self::TYPE_PERCENT => $listTotal * (min(100.0, max(0.0, $value)) / 100),

            self::TYPE_FIXED => max(0.0, $value),

            default => 0.0,
        };

        // Nunca mas que el total: un descuento de 300.000 sobre 200.000 daria
        // un total negativo, y a partir de ahi todo lo que toque esa cita
        // -- caja, comision, nomina -- queda mal.
        return round(min($discount, $listTotal), 2);
    }
}
