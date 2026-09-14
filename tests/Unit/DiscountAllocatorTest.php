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

        /*
         * Contra los valores REDONDEADOS: se reparte en pesos enteros, asi que
         * unos precios con centavos no pueden dar partes que sumen centavos.
         * La garantia que importa sigue en pie -- ni se pierde ni se gana un
         * peso -- y ademas ya no queda ningun centavo dando vueltas.
         */
        $esperado = array_sum(array_map(fn ($p) => round($p), $prices)) - round(7777.77);

        $this->assertEqualsWithDelta(
            $esperado,
            array_sum($charged),
            0.001,
            'La suma de lo cobrado debe ser el subtotal menos el descuento, sin residuo.',
        );

        foreach ($charged as $monto) {
            $this->assertSame(round($monto), $monto, 'Quedaron centavos en una linea.');
        }
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

    public function test_la_comision_de_la_visita_no_gana_un_peso_al_partirse(): void
    {
        /*
         * El caso real que lo destapo, con los numeros de Luxury.
         *
         * Combo "Semi Rubber Manos + Semi Pies": carta 95.000, se cobra
         * 85.000, comision al 50%. El sistema viejo lo cobra en UNA linea y
         * guarda 42.500.
         *
         * Nexolu lo parte en sus dos servicios -- hace falta para saber quien
         * hizo cada mitad -- y redondeando cada comision por su cuenta daba
         * 24.606 + 17.895 = 42.501. Un peso de mas por visita, que en la
         * nomina del mes son los pesos que nadie sabe explicar.
         */
        $cobrado = DiscountAllocator::allocate([55000, 40000], 10000);

        $this->assertSame([49211.0, 35789.0], $cobrado);
        $this->assertSame(85000.0, array_sum($cobrado));

        $comisiones = DiscountAllocator::commissions($cobrado, [0.5, 0.5]);

        $this->assertSame(42500.0, array_sum($comisiones), 'La comision de la visita es 42.500.');
    }

    public function test_ninguna_comision_queda_negativa(): void
    {
        // Deducir la ultima no puede convertirla en un descuento que nadie
        // pidio, por raros que sean los porcentajes.
        foreach ([[0.9, 0.01], [1.0, 0.0], [0.0, 1.0]] as $tasas) {
            foreach (DiscountAllocator::commissions([7, 3], $tasas) as $c) {
                $this->assertGreaterThanOrEqual(0, $c);
            }
        }
    }

    public function test_una_linea_sin_porcentaje_no_genera_comision(): void
    {
        $this->assertSame([0.0, 15000.0], DiscountAllocator::commissions([50000, 50000], [null, 0.30]));
    }
}
