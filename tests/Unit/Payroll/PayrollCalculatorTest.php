<?php

namespace Tests\Unit\Payroll;

use App\Support\Payroll\BasePeriod;
use App\Support\Payroll\PayrollCalculator;
use App\Support\Payroll\PayrollMode;
use PHPUnit\Framework\TestCase;

/**
 * La cuenta de la liquidacion, sin base de datos.
 *
 * Es la parte que hay que poder comprobar contra un papel: si esto se
 * equivoca, alguien recibe menos plata de la que trabajo.
 */
class PayrollCalculatorTest extends TestCase
{
    public function test_a_comision_se_paga_lo_que_genero(): void
    {
        $r = PayrollCalculator::settle(
            PayrollMode::COMMISSION,
            baseAmount: 0, basePeriod: BasePeriod::MONTH,
            days: 15, daysWithBase: 0,
            commissionTotal: 640000,
        );

        $this->assertSame(0.0, $r['base_total']);
        $this->assertSame(640000.0, $r['earned_total']);
        $this->assertSame(640000.0, $r['net_total']);
    }

    public function test_una_base_configurada_no_se_paga_si_el_modo_es_a_comision(): void
    {
        // Cambiarle el modo a alguien no debe dejar plata colgando: la base
        // sigue en la ficha pero no participa.
        $r = PayrollCalculator::settle(
            PayrollMode::COMMISSION,
            baseAmount: 1_400_000, basePeriod: BasePeriod::MONTH,
            days: 30, daysWithBase: 30,
            commissionTotal: 500000,
        );

        $this->assertSame(0.0, $r['base_total']);
        $this->assertSame(500000.0, $r['net_total']);
    }

    public function test_base_mas_comision_suma_las_dos(): void
    {
        // Base mensual de 1.400.000 = 46.666,67 por dia. Quince dias = 700.000.
        $r = PayrollCalculator::settle(
            PayrollMode::BASE_PLUS_COMMISSION,
            baseAmount: 1_400_000, basePeriod: BasePeriod::MONTH,
            days: 15, daysWithBase: 15,
            commissionTotal: 320000,
        );

        $this->assertSame(700000.0, $r['base_total']);
        $this->assertSame(1_020_000.0, $r['earned_total']);
    }

    public function test_el_mes_son_treinta_dias_no_los_del_calendario(): void
    {
        // Febrero completo (28 dias) contra un mes de 30: la tarifa diaria es
        // la misma. Sin esta convencion, el mismo sueldo pagaria mas por dia
        // en febrero que en enero.
        $febrero = PayrollCalculator::settle(
            PayrollMode::BASE_PLUS_COMMISSION,
            baseAmount: 1_500_000, basePeriod: BasePeriod::MONTH,
            days: 28, daysWithBase: 28,
            commissionTotal: 0,
        );

        $this->assertSame(1_400_000.0, $febrero['base_total']);
    }

    /*
    |--------------------------------------------------------------------------
    | Minimo garantizado
    |--------------------------------------------------------------------------
    */

    public function test_el_minimo_completa_cuando_la_comision_no_alcanza(): void
    {
        // Piso quincenal de 700.000; genero 450.000.
        $r = PayrollCalculator::settle(
            PayrollMode::GUARANTEED_MINIMUM,
            baseAmount: 700000, basePeriod: BasePeriod::FORTNIGHT,
            days: 15, daysWithBase: 15,
            commissionTotal: 450000,
        );

        $this->assertSame(700000.0, $r['earned_total']);
        // Lo que le costo al negocio sostener el piso.
        $this->assertSame(250000.0, $r['topped_up']);
        // La base reportada es SOLO el complemento: sumar el piso completo
        // ademas de la comision cuadraria mal el comprobante.
        $this->assertSame(250000.0, $r['base_total']);
        $this->assertSame(450000.0, $r['commission_total']);
        $this->assertSame(700000.0, $r['base_total'] + $r['commission_total']);
    }

    public function test_el_minimo_no_recorta_a_quien_produce_mas(): void
    {
        $r = PayrollCalculator::settle(
            PayrollMode::GUARANTEED_MINIMUM,
            baseAmount: 700000, basePeriod: BasePeriod::FORTNIGHT,
            days: 15, daysWithBase: 15,
            commissionTotal: 1_100_000,
        );

        $this->assertSame(1_100_000.0, $r['earned_total']);
        // Cero complemento: ya se paga sola.
        $this->assertSame(0.0, $r['topped_up']);
        $this->assertSame(0.0, $r['base_total']);
    }

    /*
    |--------------------------------------------------------------------------
    | Base temporal: la de quien entra y todavia no tiene clientela
    |--------------------------------------------------------------------------
    */

    public function test_la_base_temporal_se_prorratea_por_los_dias_que_alcanzo(): void
    {
        // Periodo de 20 dias, pero la base vencia al dia 12.
        $r = PayrollCalculator::settle(
            PayrollMode::BASE_PLUS_COMMISSION,
            baseAmount: 1_500_000, basePeriod: BasePeriod::MONTH,
            days: 20, daysWithBase: 12,
            commissionTotal: 300000,
        );

        // 50.000 diarios x 12 dias.
        $this->assertSame(600000.0, $r['base_total']);
        $this->assertSame(900000.0, $r['earned_total']);
    }

    public function test_base_vencida_antes_del_periodo_no_paga_nada(): void
    {
        $r = PayrollCalculator::settle(
            PayrollMode::BASE_PLUS_COMMISSION,
            baseAmount: 1_500_000, basePeriod: BasePeriod::MONTH,
            days: 15, daysWithBase: 0,
            commissionTotal: 800000,
        );

        $this->assertSame(0.0, $r['base_total']);
        $this->assertSame(800000.0, $r['earned_total']);
    }

    public function test_los_dias_con_base_nunca_superan_los_del_periodo(): void
    {
        // Defensa contra una fecha mal calculada aguas arriba: no se puede
        // pagar mas base que dias tiene el periodo.
        $r = PayrollCalculator::settle(
            PayrollMode::BASE_PLUS_COMMISSION,
            baseAmount: 300000, basePeriod: BasePeriod::WEEK,
            days: 7, daysWithBase: 99,
            commissionTotal: 0,
        );

        $this->assertSame(300000.0, $r['base_total']);
    }

    /*
    |--------------------------------------------------------------------------
    | Bonos y descuentos
    |--------------------------------------------------------------------------
    */

    public function test_bonos_suman_y_descuentos_restan(): void
    {
        $r = PayrollCalculator::settle(
            PayrollMode::COMMISSION,
            baseAmount: 0, basePeriod: BasePeriod::MONTH,
            days: 15, daysWithBase: 0,
            commissionTotal: 800000,
            bonusTotal: 50000,
            deductionTotal: 200000,
        );

        $this->assertSame(800000.0, $r['earned_total']);
        $this->assertSame(650000.0, $r['net_total']);
    }

    public function test_el_neto_puede_quedar_en_negativo(): void
    {
        // Pidio mas anticipos de lo que produjo. Recortar a cero escondería
        // una deuda real: quien liquida tiene que verla para decidir si la
        // pasa al periodo siguiente.
        $r = PayrollCalculator::settle(
            PayrollMode::COMMISSION,
            baseAmount: 0, basePeriod: BasePeriod::MONTH,
            days: 15, daysWithBase: 0,
            commissionTotal: 180000,
            deductionTotal: 300000,
        );

        $this->assertSame(-120000.0, $r['net_total']);
    }

    public function test_un_modo_desconocido_paga_solo_comision(): void
    {
        // Default seguro: un modo mal escrito no debe regalar una base que
        // nadie configuro.
        $r = PayrollCalculator::settle(
            'modo_que_no_existe',
            baseAmount: 2_000_000, basePeriod: BasePeriod::MONTH,
            days: 30, daysWithBase: 30,
            commissionTotal: 400000,
        );

        $this->assertSame(0.0, $r['base_total']);
        $this->assertSame(400000.0, $r['net_total']);
    }

    public function test_todo_en_cero_no_explota(): void
    {
        $r = PayrollCalculator::settle(
            PayrollMode::BASE_PLUS_COMMISSION,
            baseAmount: 0, basePeriod: BasePeriod::MONTH,
            days: 0, daysWithBase: 0,
            commissionTotal: 0,
        );

        $this->assertSame(0.0, $r['net_total']);
    }
}
