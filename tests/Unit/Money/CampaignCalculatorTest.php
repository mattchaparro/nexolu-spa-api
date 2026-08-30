<?php

namespace Tests\Unit\Money;

use App\Support\Money\CampaignCalculator;
use PHPUnit\Framework\TestCase;

class CampaignCalculatorTest extends TestCase
{
    public function test_la_vigencia_incluye_el_ultimo_dia(): void
    {
        /*
         * Una campaña "del 1 al 15" aplica el 15. Que el último día no cuente
         * es la clase de detalle que se descubre con la clienta reclamando en
         * el mostrador el mismo día que vio el aviso.
         */
        $this->assertTrue(CampaignCalculator::runsOn('2026-05-01', '2026-05-15', '2026-05-15'));
        $this->assertTrue(CampaignCalculator::runsOn('2026-05-01', '2026-05-15', '2026-05-01'));
        $this->assertFalse(CampaignCalculator::runsOn('2026-05-01', '2026-05-15', '2026-05-16'));
        $this->assertFalse(CampaignCalculator::runsOn('2026-05-01', '2026-05-15', '2026-04-30'));
    }

    public function test_sin_fecha_de_fin_corre_hasta_que_se_apague(): void
    {
        $this->assertTrue(CampaignCalculator::runsOn('2026-05-01', null, '2030-01-01'));
        $this->assertFalse(CampaignCalculator::runsOn('2026-05-01', null, '2026-04-30'));
    }

    public function test_un_porcentaje_descuenta_sobre_la_linea(): void
    {
        $this->assertEqualsWithDelta(
            10000,
            CampaignCalculator::discountForPrice(CampaignCalculator::TYPE_PERCENT, 20, 50000),
            0.01,
        );
    }

    public function test_un_monto_fijo_nunca_supera_la_linea(): void
    {
        // 50.000 de descuento sobre un retoque de 25.000 no puede dejar al
        // negocio devolviendo plata.
        $this->assertEqualsWithDelta(
            25000,
            CampaignCalculator::discountForPrice(CampaignCalculator::TYPE_AMOUNT, 50000, 25000),
            0.01,
        );
    }

    public function test_un_porcentaje_fuera_de_rango_se_acota(): void
    {
        $this->assertEqualsWithDelta(
            50000,
            CampaignCalculator::discountForPrice(CampaignCalculator::TYPE_PERCENT, 300, 50000),
            0.01,
        );
    }

    public function test_un_tipo_desconocido_no_descuenta(): void
    {
        // Al revés, un valor mal escrito en la configuración regalaría
        // servicios.
        $this->assertEqualsWithDelta(
            0,
            CampaignCalculator::discountForPrice('porcentaje', 20, 50000),
            0.01,
        );
    }

    public function test_como_se_le_explica_al_cliente(): void
    {
        $this->assertSame(
            'Mes de la madre (−20%)',
            CampaignCalculator::label('Mes de la madre', CampaignCalculator::TYPE_PERCENT, 20),
        );
        $this->assertSame(
            'Semana de pestañas (−$15.000)',
            CampaignCalculator::label('Semana de pestañas', CampaignCalculator::TYPE_AMOUNT, 15000),
        );
    }
}
