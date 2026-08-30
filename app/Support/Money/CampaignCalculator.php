<?php

namespace App\Support\Money;

/**
 * La aritmetica de una campana de descuento, sin base de datos.
 *
 * Lo que se prueba aca son los bordes que le costarian plata al negocio: un
 * porcentaje mayor a cien, un monto fijo mas caro que el servicio, una
 * vigencia mal escrita.
 */
final class CampaignCalculator
{
    public const TYPE_PERCENT = 'percent';

    public const TYPE_AMOUNT = 'amount';

    public const APPLIES_ALL = 'all';

    public const APPLIES_SERVICES = 'services';

    public const APPLIES_CATEGORIES = 'categories';

    /** @return list<string> */
    public static function types(): array
    {
        return [self::TYPE_PERCENT, self::TYPE_AMOUNT];
    }

    /** @return list<string> */
    public static function scopes(): array
    {
        return [self::APPLIES_ALL, self::APPLIES_SERVICES, self::APPLIES_CATEGORIES];
    }

    /**
     * Si la campana corre ese dia.
     *
     * Los limites son INCLUSIVOS: una campana "del 1 al 15" aplica el 15. Que
     * el ultimo dia no cuente es la clase de detalle que se descubre con la
     * clienta reclamando en el mostrador el mismo dia que vio el aviso.
     */
    public static function runsOn(string $startsOn, ?string $endsOn, string $date): bool
    {
        if ($date < $startsOn) {
            return false;
        }

        return $endsOn === null || $date <= $endsOn;
    }

    /**
     * Cuanto descuenta sobre UNA linea.
     *
     * Nunca mas que la linea: un monto fijo de 50.000 sobre un retoque de
     * 25.000 descuenta 25.000, no deja al negocio devolviendo plata.
     */
    public static function discountForPrice(string $type, float $value, float $price): float
    {
        if ($price <= 0 || $value <= 0) {
            return 0.0;
        }

        $amount = match ($type) {
            self::TYPE_PERCENT => $price * (self::clampPercent($value) / 100),
            self::TYPE_AMOUNT => $value,
            // Un tipo desconocido no descuenta nada: al reves, un valor mal
            // escrito en la configuracion regalaria servicios.
            default => 0.0,
        };

        return round(min($amount, $price), 2);
    }

    public static function clampPercent(float $value): float
    {
        return max(0.0, min(100.0, $value));
    }

    /** Como se le explica la campana al cliente. */
    public static function label(string $name, string $type, float $value): string
    {
        $cuanto = $type === self::TYPE_PERCENT
            ? rtrim(rtrim(number_format(self::clampPercent($value), 2, ',', '.'), '0'), ',').'%'
            : '$'.number_format($value, 0, ',', '.');

        return "{$name} (−{$cuanto})";
    }
}
