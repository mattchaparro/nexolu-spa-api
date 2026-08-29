<?php

namespace App\Support\Payroll;

/**
 * Sobre que unidad esta expresada la base, y cuantos dias tiene esa unidad.
 *
 * Hace falta porque el periodo de liquidacion es irregular: se paga cuando la
 * profesional pide, no el 15 y el 30. Una base "mensual" hay que convertirla a
 * tarifa diaria para poder prorratearla por los dias que de verdad corrieron.
 *
 * El mes son 30 dias, no los que traiga el calendario. Es la convencion
 * laboral colombiana, y ademas evita que febrero pague mas por dia que enero.
 */
final class BasePeriod
{
    public const DAY = 'day';

    public const WEEK = 'week';

    public const FORTNIGHT = 'fortnight';

    public const MONTH = 'month';

    /** @return list<string> */
    public static function all(): array
    {
        return [self::DAY, self::WEEK, self::FORTNIGHT, self::MONTH];
    }

    public static function days(string $period): int
    {
        return match ($period) {
            self::DAY => 1,
            self::WEEK => 7,
            self::FORTNIGHT => 15,
            self::MONTH => 30,
            default => 30,
        };
    }

    public static function label(string $period): string
    {
        return match ($period) {
            self::DAY => 'Por día',
            self::WEEK => 'Semanal',
            self::FORTNIGHT => 'Quincenal',
            self::MONTH => 'Mensual',
            default => $period,
        };
    }
}
