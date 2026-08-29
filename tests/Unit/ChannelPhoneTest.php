<?php

namespace Tests\Unit;

use App\Support\ChannelPhone;
use PHPUnit\Framework\TestCase;

/**
 * Normalizacion de telefonos.
 *
 * Importa mas de lo que parece: el telefono es lo unico que distingue a dos
 * clientes que se llaman igual, y lo que evita duplicar a la misma persona.
 * Blue Souls concatenaba "57" a mano en ocho sitios distintos.
 */
class ChannelPhoneTest extends TestCase
{
    public function test_un_movil_colombiano_recibe_su_indicativo(): void
    {
        $this->assertSame('573001112233', ChannelPhone::normalize('3001112233', 'CO'));
    }

    public function test_el_mismo_numero_escrito_de_seis_formas_da_lo_mismo(): void
    {
        foreach ([
            '3001112233',
            '300 111 2233',
            '300-111-2233',
            '+57 300 111 2233',
            '573001112233',
            '(300) 111 2233',
        ] as $entrada) {
            $this->assertSame(
                '573001112233',
                ChannelPhone::normalize($entrada, 'CO'),
                "Fallo con la forma: {$entrada}",
            );
        }
    }

    public function test_un_numero_que_ya_trae_indicativo_no_lo_recibe_dos_veces(): void
    {
        // El error clasico: 57 + 573001112233.
        $this->assertSame('573001112233', ChannelPhone::normalize('573001112233', 'CO'));
    }

    public function test_el_indicativo_sale_del_pais_del_negocio(): void
    {
        // Es el punto de todo: el pais es un parametro, no una constante.
        $this->assertSame('525512345678', ChannelPhone::normalize('5512345678', 'MX'));
        $this->assertSame('51987654321', ChannelPhone::normalize('987654321', 'PE'));
        $this->assertSame('12125551234', ChannelPhone::normalize('2125551234', 'US'));
    }

    public function test_un_pais_desconocido_cae_a_colombia(): void
    {
        $this->assertSame('573001112233', ChannelPhone::normalize('3001112233', 'ZZ'));
    }

    public function test_un_numero_demasiado_corto_o_largo_se_rechaza(): void
    {
        $this->assertNull(ChannelPhone::normalize('12345', 'CO'));
        $this->assertNull(ChannelPhone::normalize('1234567890123456789', 'CO'));
    }

    public function test_texto_sin_digitos_se_rechaza(): void
    {
        $this->assertNull(ChannelPhone::normalize('no es un telefono', 'CO'));
        $this->assertNull(ChannelPhone::normalize('', 'CO'));
    }

    public function test_el_pais_se_acepta_en_minuscula(): void
    {
        $this->assertSame('573001112233', ChannelPhone::normalize('3001112233', 'co'));
    }
}
