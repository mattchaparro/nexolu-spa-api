<?php

namespace Tests\Unit;

use App\Support\Money\DiscountAllocator;
use PHPUnit\Framework\TestCase;

/**
 * Repartir un descuento sin perder pesos.
 *
 * Es la aritmetica mas facil de romper del cobro, y la que hace que un cierre
 * de caja no cuadre por una diferencia que nadie sabe explicar. Se prueba
 * aparte de la base de datos para poder cubrir los bordes con veinte casos en
 * milisegundos.
 */
class DiscountAllocatorTest extends TestCase
{
    public function test_sin_descuento_se_cobra_el_precio_de_lista(): void
    {
        $this->assertSame([50000.0, 30000.0], DiscountAllocator::allocate([50000, 30000], 0));
    }

    public function test_una_sola_linea_se_lleva_todo_el_descuento(): void
    {
        $this->assertSame([40000.0], DiscountAllocator::allocate([50000], 10000));
    }

    public function test_el_reparto_es_proporcional_al_peso_de_cada_linea(): void
    {
        // 50.000 y 30.000 sobre 80.000: al de 50 le toca 62.5% del descuento.
        $charged = DiscountAllocator::allocate([50000, 30000], 8000);

        $this->assertSame([45000.0, 27000.0], $charged);
        $this->assertEqualsWithDelta(72000, array_sum($charged), 0.001);
    }

    public function test_la_suma_de_las_partes_da_exactamente_el_total(): void
    {
        // Tres tercios de 10.000 no dividen exacto: 3333.33 x 3 = 9999.99.
        // Sin que la ultima linea absorba el redondeo se pierde un centavo.
        $charged = DiscountAllocator::allocate([10000, 10000, 10000], 10000);

        $this->assertEqualsWithDelta(20000, array_sum($charged), 0.001);
    }

    public function test_un_reparto_con_muchos_decimales_no_pierde_pesos(): void
    {
        $prices = [33333.33, 16666.67, 25000, 25000];
        $charged = DiscountAllocator::allocate($prices, 7777.77);

        $this->assertEqualsWithDelta(
            array_sum($prices) - 7777.77,
            array_sum($charged),
            0.001,
            'La suma de lo cobrado debe ser el subtotal menos el descuento, sin residuo.',
        );
    }

    public function test_un_descuento_igual_al_total_deja_todo_en_cero(): void
    {
        $charged = DiscountAllocator::allocate([50000, 30000], 80000);

        $this->assertEqualsWithDelta(0, array_sum($charged), 0.001);
    }

    public function test_un_descuento_mayor_al_total_se_rechaza(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        DiscountAllocator::allocate([50000], 60000);
    }

    public function test_una_cuenta_vacia_no_revienta(): void
    {
        $this->assertSame([], DiscountAllocator::allocate([], 1000));
    }

    public function test_lineas_en_cero_no_dividen_por_cero(): void
    {
        $this->assertSame([0.0, 0.0], DiscountAllocator::allocate([0, 0], 0));
    }

    public function test_la_comision_se_calcula_sobre_lo_cobrado(): void
    {
        $charged = DiscountAllocator::allocate([50000], 10000);
        $commissions = DiscountAllocator::commissions($charged, [0.30]);

        // 30% de 40.000, no de 50.000: si no, el negocio paga comision por
        // plata que nunca entro.
        $this->assertSame([12000.0], $commissions);
    }

    public function test_cada_linea_usa_su_propio_porcentaje(): void
    {
        // Una profesional puede tener otro porcentaje en el mismo servicio.
        $commissions = DiscountAllocator::commissions([50000, 50000], [0.30, 0.40]);

        $this->assertSame([15000.0, 20000.0], $commissions);
    }

    public function test_una_linea_sin_porcentaje_no_genera_comision(): void
    {
        $this->assertSame([0.0, 15000.0], DiscountAllocator::commissions([50000, 50000], [null, 0.30]));
    }
}
