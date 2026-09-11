<?php

namespace Tests\Unit;

use App\Support\Clients\Segmento;
use PHPUnit\Framework\TestCase;

/**
 * Los umbrales salen de los datos de Luxury, no de un manual.
 *
 * Sus 759 fichas por visitas cobradas: 357 con cero, 197 con una, 134 entre
 * dos y cuatro, 50 entre cinco y nueve, 24 con diez o mas. Por eso "frecuente"
 * arranca en cinco -- deja un grupo de ~74, chico y de verdad fiel. En tres
 * metia a 134 y la etiqueta dejaba de distinguir.
 */
class SegmentoTest extends TestCase
{
    public function test_frecuente_arranca_en_cinco(): void
    {
        $this->assertSame(Segmento::OCASIONAL, Segmento::porVisitas(4));
        $this->assertSame(Segmento::FRECUENTE, Segmento::porVisitas(5));
        $this->assertSame(Segmento::FRECUENTE, Segmento::porVisitas(22));
    }

    public function test_una_sola_visita_no_es_ocasional(): void
    {
        // Son cosas distintas: a quien vino UNA vez se le invita a volver; a
        // quien vino tres, se le premia. Meterlas juntas pierde las dos.
        $this->assertSame(Segmento::NUEVA, Segmento::porVisitas(1));
        $this->assertSame(Segmento::OCASIONAL, Segmento::porVisitas(2));
    }

    public function test_sin_visitas_no_es_lo_mismo_que_una(): void
    {
        // 357 de las 759 fichas de Luxury estan asi: existen pero nunca se les
        // cobro nada. Llamarlas "nuevas" las mezclaria con quien si vino.
        $this->assertSame(Segmento::SIN_VISITAS, Segmento::porVisitas(0));
    }

    public function test_cada_segmento_se_dice_en_castellano(): void
    {
        foreach (Segmento::todos() as $s) {
            $this->assertNotSame('', Segmento::etiqueta($s));
            $this->assertNotSame($s, Segmento::etiqueta($s));
        }
    }
}
