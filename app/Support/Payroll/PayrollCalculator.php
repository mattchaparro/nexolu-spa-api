<?php

namespace App\Support\Payroll;

/**
 * La cuenta de una liquidacion. Sin base de datos, sin modelos.
 *
 * Vive aparte porque es la parte que hay que poder probar a mano contra un
 * papel: si esto se equivoca, alguien recibe menos plata de la que trabajo y
 * no hay log que lo explique.
 *
 * La secuencia es siempre la misma:
 *
 *     devengado = segun el modo (comision, base+comision, o el mayor)
 *     neto      = devengado + bonos − descuentos
 *
 * El neto puede quedar en negativo -- una profesional que pidio mas anticipos
 * de lo que produjo -- y NO se recorta a cero. Ese saldo es real y taparlo es
 * como se pierde plata: quien liquida tiene que verlo y decidir si lo pasa al
 * periodo siguiente.
 */
final class PayrollCalculator
{
    /**
     * @param  string  $mode  Uno de PayrollMode.
     * @param  float  $baseAmount  La base tal como esta configurada.
     * @param  string  $basePeriod  Sobre que unidad esta expresada (BasePeriod).
     * @param  int  $days  Dias del periodo que se liquida, inclusive.
     * @param  int  $daysWithBase  De esos dias, cuantos tenian base vigente.
     *                             Menor que $days cuando la base era temporal
     *                             y se vencio en mitad del periodo.
     * @param  float  $commissionTotal  Comision ya congelada de sus servicios.
     * @param  float  $bonusTotal  Bonos y extras del periodo.
     * @param  float  $deductionTotal  Anticipos, insumos, multas.
     * @return array{
     *     base_total: float,
     *     commission_total: float,
     *     earned_total: float,
     *     bonus_total: float,
     *     deduction_total: float,
     *     net_total: float,
     *     topped_up: float,
     * }
     */
    public static function settle(
        string $mode,
        float $baseAmount,
        string $basePeriod,
        int $days,
        int $daysWithBase,
        float $commissionTotal,
        float $bonusTotal = 0.0,
        float $deductionTotal = 0.0,
    ): array {
        $baseTotal = self::proratedBase($mode, $baseAmount, $basePeriod, $days, $daysWithBase);

        // Cuanto le tuvo que completar el negocio para llegar al piso. Se
        // devuelve aparte porque es el numero que dice si el minimo garantizado
        // esta costando: cero significa que ya se paga sola.
        $toppedUp = 0.0;

        $earned = match ($mode) {
            PayrollMode::BASE_PLUS_COMMISSION => $baseTotal + $commissionTotal,

            PayrollMode::GUARANTEED_MINIMUM => max($baseTotal, $commissionTotal),

            // PayrollMode::COMMISSION y cualquier valor desconocido: pagar solo
            // lo que efectivamente genero es el default seguro. Un modo mal
            // escrito no debe regalar una base que nadie configuro.
            default => $commissionTotal,
        };

        if ($mode === PayrollMode::GUARANTEED_MINIMUM) {
            $toppedUp = max(0.0, $baseTotal - $commissionTotal);
            // En este modo la base no se suma: es un piso. Reportarla como
            // devengada ademas de la comision cuadraria mal el comprobante.
            $baseTotal = $toppedUp;
        }

        return [
            'base_total' => self::round($baseTotal),
            'commission_total' => self::round($commissionTotal),
            'earned_total' => self::round($earned),
            'bonus_total' => self::round($bonusTotal),
            'deduction_total' => self::round($deductionTotal),
            'net_total' => self::round($earned + $bonusTotal - $deductionTotal),
            'topped_up' => self::round($toppedUp),
        ];
    }

    /**
     * La base que corresponde a los dias liquidados.
     *
     * Se convierte a tarifa diaria y se multiplica por los dias con base
     * vigente. Un mes son 30 dias por convencion: sin eso, liquidar en febrero
     * pagaria mas por dia que liquidar en enero por el mismo sueldo.
     */
    private static function proratedBase(
        string $mode,
        float $baseAmount,
        string $basePeriod,
        int $days,
        int $daysWithBase,
    ): float {
        if (! PayrollMode::usesBase($mode) || $baseAmount <= 0) {
            return 0.0;
        }

        $daysWithBase = max(0, min($daysWithBase, $days));

        if ($daysWithBase === 0) {
            return 0.0;
        }

        return ($baseAmount / BasePeriod::days($basePeriod)) * $daysWithBase;
    }

    /**
     * Dos decimales, igual que DiscountAllocator y CashSummary. La moneda es
     * del negocio y no todas las del catalogo son sin centavos; redondear a
     * entero aca haria que la nomina cuadre distinto que la caja.
     */
    private static function round(float $value): float
    {
        return round($value, 2);
    }
}
