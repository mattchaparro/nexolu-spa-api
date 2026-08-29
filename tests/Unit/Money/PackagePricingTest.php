<?php

namespace Tests\Unit\Money;

use App\Support\Money\PackagePricing as PP;
use PHPUnit\Framework\TestCase;

/**
 * El precio de un combo.
 *
 * Es lo único del catálogo cuyo precio no está escrito en ningún lado: se
 * calcula. Y un combo mal calculado cobra de menos en cada venta sin que nadie
 * lo note hasta el cierre del mes.
 */
class PackagePricingTest extends TestCase
{
    public function test_sin_descuento_el_combo_vale_la_suma(): void
    {
        $q = PP::quote([100000, 45000], PP::TYPE_NONE, null);

        $this->assertSame(145000.0, $q['list_total']);
        $this->assertSame(0.0, $q['discount']);
        $this->assertSame(145000.0, $q['total']);
    }

    public function test_precio_cerrado(): void
    {
        // "El combo novia vale 250.000", sumen lo que sumen sus servicios.
        $q = PP::quote([100000, 120000, 90000], PP::TYPE_PRICE, 250000);

        $this->assertSame(310000.0, $q['list_total']);
        $this->assertSame(60000.0, $q['discount']);
        $this->assertSame(250000.0, $q['total']);
    }

    public function test_porcentaje(): void
    {
        $q = PP::quote([100000, 100000], PP::TYPE_PERCENT, 15);

        $this->assertSame(30000.0, $q['discount']);
        $this->assertSame(170000.0, $q['total']);
        $this->assertSame(15.0, $q['savings_percent']);
    }

    public function test_descuento_en_pesos(): void
    {
        $q = PP::quote([100000, 45000], PP::TYPE_FIXED, 25000);

        $this->assertSame(25000.0, $q['discount']);
        $this->assertSame(120000.0, $q['total']);
    }

    public function test_el_ahorro_se_expresa_en_porcentaje_para_el_cliente(): void
    {
        // "Ahorras 19,4%" es lo que se pone en la página; el número tiene que
        // salir del mismo cálculo que cobra, no de una cuenta aparte.
        $q = PP::quote([100000, 120000, 90000], PP::TYPE_PRICE, 250000);

        $this->assertSame(19.4, $q['savings_percent']);
    }

    /*
    |--------------------------------------------------------------------------
    | Lo que no puede pasar
    |--------------------------------------------------------------------------
    */

    public function test_el_descuento_nunca_supera_el_total(): void
    {
        // 300.000 de descuento sobre 145.000 daría un total negativo, y a
        // partir de ahí todo lo que toque esa cita —caja, comisión, nómina—
        // queda mal.
        $q = PP::quote([100000, 45000], PP::TYPE_FIXED, 300000);

        $this->assertSame(145000.0, $q['discount']);
        $this->assertSame(0.0, $q['total']);
    }

    public function test_un_precio_cerrado_mayor_que_la_suma_no_cobra_de_mas(): void
    {
        // Sería un recargo disfrazado de combo. Se ve raro en pantalla, que es
        // mejor que cobrarle a alguien algo que no acordó.
        $q = PP::quote([100000], PP::TYPE_PRICE, 150000);

        $this->assertSame(0.0, $q['discount']);
        $this->assertSame(100000.0, $q['total']);
    }

    public function test_un_porcentaje_imposible_se_recorta(): void
    {
        $this->assertSame(100000.0, PP::quote([100000], PP::TYPE_PERCENT, 250)['discount']);
        $this->assertSame(0.0, PP::quote([100000], PP::TYPE_PERCENT, -10)['discount']);
    }

    public function test_un_combo_vacio_no_explota(): void
    {
        $q = PP::quote([], PP::TYPE_PERCENT, 20);

        $this->assertSame(0.0, $q['total']);
        $this->assertSame(0.0, $q['savings_percent']);
    }

    public function test_sin_valor_configurado_no_hay_descuento(): void
    {
        // El combo recién creado, antes de decidir cuánto rebaja.
        $this->assertSame(0.0, PP::quote([100000], PP::TYPE_PERCENT, null)['discount']);
    }
}
