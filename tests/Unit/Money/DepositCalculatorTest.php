<?php

namespace Tests\Unit\Money;

use App\Support\Money\DepositCalculator;
use PHPUnit\Framework\TestCase;

/**
 * El abono para separar una cita.
 *
 * Todo lo que se prueba acá son bordes de configuración: son los que le
 * cobrarían de más a un cliente en la pantalla de reserva, donde nadie del
 * local está mirando para corregirlo.
 */
class DepositCalculatorTest extends TestCase
{
    public function test_un_porcentaje_del_total(): void
    {
        $this->assertEqualsWithDelta(
            30000,
            DepositCalculator::forTotal(100000, DepositCalculator::TYPE_PERCENT, 30),
            0.01,
        );
    }

    public function test_un_monto_fijo(): void
    {
        $this->assertEqualsWithDelta(
            20000,
            DepositCalculator::forTotal(100000, DepositCalculator::TYPE_FIXED, 20000),
            0.01,
        );
    }

    public function test_el_abono_nunca_supera_el_total(): void
    {
        // Un fijo de 50.000 sobre un servicio de 25.000 sería cobrarle por
        // adelantado más de lo que vale. Se topa en el total.
        $this->assertEqualsWithDelta(
            25000,
            DepositCalculator::forTotal(25000, DepositCalculator::TYPE_FIXED, 50000),
            0.01,
        );
    }

    public function test_un_porcentaje_fuera_de_rango_se_acota(): void
    {
        $this->assertEqualsWithDelta(
            100000,
            DepositCalculator::forTotal(100000, DepositCalculator::TYPE_PERCENT, 300),
            0.01,
        );

        $this->assertEqualsWithDelta(
            0,
            DepositCalculator::forTotal(100000, DepositCalculator::TYPE_PERCENT, -10),
            0.01,
        );
    }

    public function test_sin_politica_no_se_pide_nada(): void
    {
        $this->assertEqualsWithDelta(0, DepositCalculator::forTotal(100000, null, null), 0.01);
        $this->assertEqualsWithDelta(
            0,
            DepositCalculator::forTotal(100000, DepositCalculator::TYPE_NONE, 30),
            0.01,
        );
    }

    public function test_un_tipo_desconocido_no_cobra_nada(): void
    {
        /*
         * Al revés -- asumir un default "razonable" -- le cobraría plata por
         * adelantado a un cliente por culpa de un valor mal escrito en la
         * configuración del negocio.
         */
        $this->assertEqualsWithDelta(
            0,
            DepositCalculator::forTotal(100000, 'porcentaje', 30),
            0.01,
        );
    }

    public function test_un_total_en_cero_no_pide_abono(): void
    {
        $this->assertEqualsWithDelta(
            0,
            DepositCalculator::forTotal(0, DepositCalculator::TYPE_PERCENT, 30),
            0.01,
        );
    }

    public function test_como_se_le_explica_al_cliente(): void
    {
        $this->assertSame('30%', DepositCalculator::label(DepositCalculator::TYPE_PERCENT, 30));
        $this->assertSame('$20.000', DepositCalculator::label(DepositCalculator::TYPE_FIXED, 20000));
        $this->assertNull(DepositCalculator::label(DepositCalculator::TYPE_NONE, 30));
        $this->assertNull(DepositCalculator::label(DepositCalculator::TYPE_PERCENT, 0));
    }
}
