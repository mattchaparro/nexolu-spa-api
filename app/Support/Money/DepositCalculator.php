<?php

namespace App\Support\Money;

/**
 * Cuanto hay que abonar para separar una cita.
 *
 * Sin base de datos, como el resto de la aritmetica de dinero: es la clase de
 * calculo que se rompe en los bordes -- un porcentaje sobre cero, un fijo mas
 * caro que el servicio, un valor negativo cargado por error en la
 * configuracion -- y esos casos se prueban escritos a mano, no sembrando citas
 * por HTTP.
 *
 * El abono NO es ingreso cuando se recibe: es plata del cliente que el negocio
 * todavia no se ha ganado. Se reconoce cuando el servicio se presta, igual que
 * el resto del cobro. Por eso aca solo se calcula el monto; quien decide en que
 * dia entra es el cierre de caja.
 */
final class DepositCalculator
{
    public const TYPE_NONE = 'none';

    public const TYPE_PERCENT = 'percent';

    public const TYPE_FIXED = 'fixed';

    /** @return list<string> */
    public static function types(): array
    {
        return [self::TYPE_NONE, self::TYPE_PERCENT, self::TYPE_FIXED];
    }

    /**
     * El abono que corresponde a un total, ya redondeado.
     *
     * Nunca supera el total: cobrar por adelantado mas de lo que vale el
     * servicio no es un abono, es un error de configuracion, y el cliente no
     * tiene por que enterarse de el en la pantalla de reserva.
     */
    public static function forTotal(float $total, ?string $type, ?float $value): float
    {
        if ($total <= 0 || $value === null || $value <= 0) {
            return 0.0;
        }

        $amount = match ($type) {
            self::TYPE_PERCENT => $total * (self::clampPercent($value) / 100),
            self::TYPE_FIXED => $value,
            // Un tipo desconocido no cobra nada. Al reves -- asumir un default
            // "razonable" -- le cobraria plata por adelantado a un cliente por
            // culpa de un valor mal escrito en la configuracion.
            default => 0.0,
        };

        return round(min($amount, $total), 2);
    }

    /** El porcentaje acotado a [0, 100]. */
    public static function clampPercent(float $value): float
    {
        return max(0.0, min(100.0, $value));
    }

    /** Como se le explica la politica al cliente. */
    public static function label(?string $type, ?float $value): ?string
    {
        if ($value === null || $value <= 0) {
            return null;
        }

        return match ($type) {
            self::TYPE_PERCENT => rtrim(rtrim(number_format(self::clampPercent($value), 2, ',', '.'), '0'), ',').'%',
            self::TYPE_FIXED => '$'.number_format($value, 0, ',', '.'),
            default => null,
        };
    }
}
