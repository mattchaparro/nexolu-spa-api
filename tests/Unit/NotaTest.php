<?php

namespace Tests\Unit;

use App\Support\Ratings\Nota;
use PHPUnit\Framework\TestCase;

/**
 * Que un 3 de 3 no se lea como un 3 de 5.
 *
 * Es todo el punto: la encuesta vieja de Luxury preguntaba puntualidad con
 * tres botones y atencion con cinco. Sin esto, la pantalla de nomina le decia
 * al dueño "puntualidad 2,96" al lado de "atencion 4,96" -- que se lee como si
 * el local llegara tarde siempre, cuando 2,96 sobre 3 es casi perfecto.
 */
class NotaTest extends TestCase
{
    public function test_el_tope_de_cualquier_escala_es_cien(): void
    {
        $this->assertSame(100.0, Nota::porcentaje(3, 3));
        $this->assertSame(100.0, Nota::porcentaje(4, 4));
        $this->assertSame(100.0, Nota::porcentaje(5, 5));
    }

    public function test_el_piso_de_cualquier_escala_es_cero(): void
    {
        // Y no 33% ni 20%: quien puso el peor boton quedo igual de mal, tuviera
        // tres opciones o cinco.
        $this->assertSame(0.0, Nota::porcentaje(1, 3));
        $this->assertSame(0.0, Nota::porcentaje(1, 5));
    }

    public function test_el_medio_cae_donde_debe(): void
    {
        $this->assertSame(50.0, Nota::porcentaje(2, 3));
        $this->assertSame(50.0, Nota::porcentaje(3, 5));
    }

    public function test_sin_nota_no_hay_porcentaje(): void
    {
        // Null y no cero: "no califico" no es "califico psimo".
        $this->assertNull(Nota::porcentaje(null, 5));
        $this->assertNull(Nota::porcentaje(4, null));
    }

    public function test_una_escala_de_uno_no_dice_nada(): void
    {
        // Sin esto seria una division por cero.
        $this->assertNull(Nota::porcentaje(1, 1));
    }

    public function test_una_nota_fuera_de_su_escala_se_acota(): void
    {
        // Un 5 guardado contra una escala de 3 es un dato roto, pero devolver
        // 200% seria peor que acotarlo.
        $this->assertSame(100.0, Nota::porcentaje(5, 3));
        $this->assertSame(0.0, Nota::porcentaje(0, 5));
    }

    public function test_promedia_escalas_distintas(): void
    {
        // Los tres son el tope de su escala: el promedio es 100, no 4.
        $this->assertSame(100.0, Nota::promedio([[5, 5], [4, 4], [3, 3]]));

        // Y las que no tienen nota no cuentan, ni suman ni restan.
        $this->assertSame(100.0, Nota::promedio([[5, 5], [null, 5], [3, 3]]));
        $this->assertNull(Nota::promedio([[null, 5]]));
    }

    public function test_el_promedio_real_de_luxury(): void
    {
        /*
         * La puntualidad de verdad de Luxury: 127 clientas pusieron 3 de 3,
         * 13 pusieron 2 y una puso 1.
         *
         * Crudo da 2,89 y, puesto al lado de "atencion 4,96", se lee como si
         * el local llegara tarde siempre. Sobre su escala da 94,7% -- es decir
         * 4,79 de 5 -- que es lo que de verdad opinaron.
         *
         * A mano: 127x100 + 13x50 + 1x0 = 13.350, entre 141 = 94,68.
         */
        $notas = array_merge(
            array_fill(0, 127, [3, 3]),
            array_fill(0, 13, [2, 3]),
            [[1, 3]],
        );

        $this->assertSame(94.7, Nota::promedio($notas));
        $this->assertSame(4.79, Nota::sobreCinco(Nota::promedio($notas)));
    }
}
