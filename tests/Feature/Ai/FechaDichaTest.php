<?php

namespace Tests\Feature\Ai;

use App\Ai\FechaDicha;
use Carbon\CarbonImmutable;
use Tests\TestCase;

/**
 * La fecha como la dice la gente.
 *
 * Pasó en producción: la clienta pidió "el lunes" y el agente buscó el
 * martes 22. Una hora equivocada no es un detalle -- es alguien que llega
 * al local cuando no lo esperan. Calcular días parece trivial y el modelo
 * lo hace mal, así que la cuenta la hace el código.
 */
class FechaDichaTest extends TestCase
{
    /** Sábado 19 de septiembre de 2026. */
    private function sabado(): CarbonImmutable
    {
        return CarbonImmutable::parse('2026-09-19 15:00', 'America/Bogota');
    }

    private function resolver(string $texto): ?string
    {
        return FechaDicha::resolver($texto, 'America/Bogota', $this->sabado())?->format('Y-m-d');
    }

    public function test_hoy_manana_y_pasado_manana(): void
    {
        $this->assertSame('2026-09-19', $this->resolver('hoy'));
        $this->assertSame('2026-09-20', $this->resolver('mañana'));
        $this->assertSame('2026-09-20', $this->resolver('manana'));
        $this->assertSame('2026-09-21', $this->resolver('pasado mañana'));
    }

    public function test_un_dia_de_la_semana_es_el_mas_cercano(): void
    {
        // El caso que falló: dicho un sábado, "el lunes" es el 21.
        $this->assertSame('2026-09-21', $this->resolver('el lunes'));
        $this->assertSame('2026-09-21', $this->resolver('lunes'));
        $this->assertSame('2026-09-24', $this->resolver('el jueves'));
    }

    public function test_sin_tildes_y_como_sea_que_lo_escriban(): void
    {
        $this->assertSame('2026-09-23', $this->resolver('MIERCOLES'));
        $this->assertSame('2026-09-23', $this->resolver('el  miércoles '));
    }

    public function test_en_ocho_empuja_una_semana(): void
    {
        /*
         * "El lunes en ocho" es el de la otra semana. Sin esto, alguien
         * que pide con dos semanas de anticipación queda agendada en dos
         * días.
         */
        $this->assertSame('2026-09-28', $this->resolver('el lunes en ocho'));
        $this->assertSame('2026-09-28', $this->resolver('lunes de la otra semana'));
    }

    public function test_una_fecha_exacta_pasa_tal_cual(): void
    {
        $this->assertSame('2026-10-05', $this->resolver('2026-10-05'));
    }

    public function test_lo_que_no_se_entiende_devuelve_null(): void
    {
        // Null y no una fecha inventada: el agente pregunta en vez de
        // agendar cualquier día.
        $this->assertNull($this->resolver('cuando puedas'));
        $this->assertNull($this->resolver(''));
        $this->assertNull($this->resolver('el 30 de febrero'));
    }
}
