<?php

namespace Tests\Feature\Ai;

use App\Ai\HoraLegible;
use Carbon\CarbonImmutable;
use Tests\TestCase;

/**
 * La hora como la dice una persona.
 *
 * El agente escribía "tengo a las 10:00, 11:00, 12:00, 13:00…" -- así no
 * habla nadie acá, y obliga a la clienta a traducir cada hora mentalmente.
 */
class HoraLegibleTest extends TestCase
{
    public function test_siempre_con_minutos_y_am_pm(): void
    {
        /*
         * Las horas en punto iban sin minutos ("9 am"), y en la lista de
         * horas quedaban desparejas al lado de "10:30 am" -- lo vio
         * Alejandro en su teléfono. Todas iguales se leen de un vistazo.
         */
        $this->assertSame('3:00 pm', HoraLegible::deTexto('15:00'));
        $this->assertSame('10:00 am', HoraLegible::deTexto('10:00'));
    }

    public function test_los_minutos_van_con_dos_digitos(): void
    {
        $this->assertSame('3:30 pm', HoraLegible::deTexto('15:30'));
        $this->assertSame('9:05 am', HoraLegible::deTexto('09:05'));
    }

    public function test_el_mediodia_y_la_medianoche_son_las_12(): void
    {
        // El error clásico: 12:00 se vuelve "0 pm" y 00:30 "0:30 am".
        $this->assertSame('12:00 pm', HoraLegible::deTexto('12:00'));
        $this->assertSame('12:30 am', HoraLegible::deTexto('00:30'));
    }

    public function test_convierte_desde_una_fecha_en_la_zona_del_negocio(): void
    {
        $momento = CarbonImmutable::parse('2026-09-19 20:00', 'UTC');

        $this->assertSame('3:00 pm', HoraLegible::de($momento, 'America/Bogota'));
    }
}
