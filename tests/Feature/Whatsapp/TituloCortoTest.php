<?php

namespace Tests\Feature\WhatsApp;

use App\Support\TituloCorto;
use Tests\TestCase;

/**
 * Los nombres de la lista tienen que leerse como nombres.
 *
 * Meta corta los títulos de una fila en 24 caracteres, y cortar a lo
 * bruto dejaba «Press-On + Semipermanent» y «Recubrimiento Acrigel + »
 * en una lista que la clienta va a TOCAR para elegir. El primero parece
 * un error de ortografía; el segundo, un mensaje roto a la mitad. Los
 * dos la hacen dudar justo en el momento de elegir.
 */
class TituloCortoTest extends TestCase
{
    public function test_lo_que_cabe_se_deja_tal_cual(): void
    {
        $this->assertSame('Tradicional', TituloCorto::de('Tradicional'));
        // Justo en el límite: veinticuatro caracteres entran enteros.
        $this->assertSame(str_repeat('a', 24), TituloCorto::de(str_repeat('a', 24)));
    }

    public function test_lo_que_no_cabe_avisa_que_sigue(): void
    {
        $corto = TituloCorto::de('Press-On + Semipermanente');

        $this->assertSame('Press-On + Semipermanen…', $corto);
        $this->assertLessThanOrEqual(24, mb_strlen($corto));
    }

    public function test_no_queda_un_separador_colgando(): void
    {
        // «Recubrimiento Acrigel + » se lee como un mensaje que se cortó.
        $this->assertSame('Recubrimiento Acrigel…', TituloCorto::de('Recubrimiento Acrigel + Semipermanente'));
        $this->assertSame('Retoque Acrigel/Poligel…', TituloCorto::de('Retoque Acrigel/Poligel Softgel'));
    }

    public function test_las_tildes_cuentan_como_una_letra(): void
    {
        // Con `substr` a secas una eñe partida por la mitad deja basura
        // en pantalla; el largo se mide en letras, no en bytes.
        $corto = TituloCorto::de('Recubrimiento Acrílico + Tradicional');

        $this->assertSame('Recubrimiento Acrílico…', $corto);
        $this->assertLessThanOrEqual(24, mb_strlen($corto));
    }

    public function test_un_nombre_de_puros_separadores_no_deja_la_fila_vacia(): void
    {
        // Una fila sin texto no se puede tocar. Vale más un corte feo.
        $this->assertNotSame('…', TituloCorto::de(str_repeat('+', 30)));
    }
}
