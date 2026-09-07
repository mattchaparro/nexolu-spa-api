<?php

namespace Tests\Unit\Money;

use App\Support\Money\Reparto;
use PHPUnit\Framework\TestCase;

/**
 * El reparto de plata entre las partes de un combo.
 *
 * La propiedad que se prueba una y otra vez es siempre la misma: LA SUMA DE
 * LAS PARTES ES EL TOTAL. Si eso falla, el descuadre no se ve el dia de la
 * migracion; se ve semanas despues, en un cierre de caja que sobra o falta
 * por un peso y que nadie puede explicar.
 */
class RepartoTest extends TestCase
{
    public function test_reparte_a_prorrata_del_precio_de_lista(): void
    {
        // Tradi Manos + Pies: 20.000 de manicure + 30.000 de pedicure.
        $this->assertSame([20000.0, 30000.0], Reparto::proporcional(50000, [20000, 30000]));
    }

    public function test_la_suma_es_exactamente_el_total_aunque_no_divida(): void
    {
        /*
         * 100 entre tres partes iguales da 33,33 tres veces = 99,99. La
         * ultima tiene que llevarse el centavo que falta.
         */
        $partes = Reparto::proporcional(100, [1, 1, 1]);

        $this->assertSame(100.0, array_sum($partes));
        $this->assertSame([33.33, 33.33, 33.34], $partes);
    }

    public function test_ningun_reparto_pierde_ni_gana_plata(): void
    {
        // Barrido sobre montos y pesos feos: la invariante no depende del caso.
        foreach ([50000, 85000, 95000, 33333, 1, 0, 70000.55] as $monto) {
            foreach ([[45000, 40000], [20000, 30000], [1, 2, 7], [55000, 40000]] as $pesos) {
                $partes = Reparto::proporcional((float) $monto, $pesos);

                $this->assertEqualsWithDelta(
                    (float) $monto,
                    array_sum($partes),
                    0.001,
                    "Se perdio plata repartiendo {$monto} entre ".count($pesos).' partes',
                );
            }
        }
    }

    public function test_un_solo_servicio_se_lleva_todo(): void
    {
        $this->assertSame([45000.0], Reparto::proporcional(45000, [45000]));
    }

    public function test_sin_partes_no_reparte_nada(): void
    {
        $this->assertSame([], Reparto::proporcional(50000, []));
    }

    public function test_con_precios_en_cero_reparte_en_partes_iguales(): void
    {
        /*
         * Un servicio de cortesia a precio cero existe en catalogos reales.
         * Dividir por cero ahi tumbaria la migracion de una cita entera; en
         * partes iguales al menos conserva el total.
         */
        $partes = Reparto::proporcional(50000, [0, 0]);

        $this->assertSame(50000.0, array_sum($partes));
        $this->assertSame([25000.0, 25000.0], $partes);
    }

    public function test_un_precio_negativo_no_le_roba_al_otro(): void
    {
        // Dato corrupto en el origen: se trata como cero, no como resta.
        $partes = Reparto::proporcional(50000, [-10000, 50000]);

        $this->assertSame(50000.0, array_sum($partes));
        $this->assertSame(0.0, $partes[0]);
    }
}
