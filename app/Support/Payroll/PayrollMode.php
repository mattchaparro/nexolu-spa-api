<?php

namespace App\Support\Payroll;

/**
 * Como se le paga a una profesional.
 *
 * Los tres modos existen porque los tres pasan en el mismo local: la que lleva
 * anos y vive de su comision, la que entra con un sueldo mientras arma
 * clientela, y la que tiene un piso garantizado para que una semana floja no
 * la deje sin nada.
 */
final class PayrollMode
{
    /** Solo comision sobre lo que cobro. */
    public const COMMISSION = 'commission';

    /** La base del periodo MAS la comision. */
    public const BASE_PLUS_COMMISSION = 'base_plus_commission';

    /** La comision, y si no llega a la base se le completa hasta la base. */
    public const GUARANTEED_MINIMUM = 'guaranteed_minimum';

    /** @return list<string> */
    public static function all(): array
    {
        return [self::COMMISSION, self::BASE_PLUS_COMMISSION, self::GUARANTEED_MINIMUM];
    }

    public static function label(string $mode): string
    {
        return match ($mode) {
            self::COMMISSION => 'Solo comisión',
            self::BASE_PLUS_COMMISSION => 'Base + comisión',
            self::GUARANTEED_MINIMUM => 'Comisión con mínimo garantizado',
            default => $mode,
        };
    }

    /**
     * Lista blanca, no `!== COMMISSION`: un modo mal escrito no debe caer del
     * lado que paga una base que nadie configuro.
     */
    public static function usesBase(string $mode): bool
    {
        return in_array($mode, [self::BASE_PLUS_COMMISSION, self::GUARANTEED_MINIMUM], true);
    }
}
