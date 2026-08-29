<?php

namespace Tests\Unit\Money;

use App\Support\Money\CommissionResolver as CR;
use PHPUnit\Framework\TestCase;

/**
 * Qué porcentaje gana, y por qué.
 *
 * Es la pregunta que peor se responde de memoria y la que más caro sale
 * responder mal: si la cascada se equivoca, alguien cobra de menos y se entera
 * el día de pago.
 */
class CommissionResolverTest extends TestCase
{
    public function test_sin_nada_configurado_no_hay_comision(): void
    {
        $r = CR::resolve();

        $this->assertNull($r['rate']);
        $this->assertSame(CR::SOURCE_NONE, $r['source']);
    }

    public function test_la_categoria_es_el_ultimo_recurso(): void
    {
        // 20 servicios de manicure sin porcentaje propio heredan el de su
        // familia. Es lo que evita tener que tocarlos uno por uno.
        $r = CR::resolve(category: 0.35);

        $this->assertSame(0.35, $r['rate']);
        $this->assertSame(CR::SOURCE_CATEGORY, $r['source']);
    }

    public function test_el_servicio_manda_sobre_su_categoria(): void
    {
        $r = CR::resolve(service: 0.30, category: 0.35);

        $this->assertSame(0.30, $r['rate']);
        $this->assertSame(CR::SOURCE_SERVICE, $r['source']);
    }

    public function test_la_persona_manda_sobre_el_servicio(): void
    {
        /*
         * La decisión que importa. El porcentaje de alguien es parte de su
         * acuerdo laboral: un servicio nuevo que entre al catálogo con otro
         * número no puede cambiarle en silencio lo que gana.
         */
        $r = CR::resolve(person: 0.50, service: 0.30, category: 0.35);

        $this->assertSame(0.50, $r['rate']);
        $this->assertSame(CR::SOURCE_PERSON, $r['source']);
    }

    public function test_el_acuerdo_puntual_gana_sobre_todo(): void
    {
        // Alguien que va al 50% en general pero al 60% en un servicio que sólo
        // ella hace.
        $r = CR::resolve(agreement: 0.60, person: 0.50, service: 0.30, category: 0.35);

        $this->assertSame(0.60, $r['rate']);
        $this->assertSame(CR::SOURCE_AGREEMENT, $r['source']);
    }

    public function test_un_cero_explicito_no_es_lo_mismo_que_no_configurado(): void
    {
        // "Este servicio no paga comisión" es una decisión; heredar el 50% de
        // la persona la ignoraría.
        $r = CR::resolve(service: 0.0, category: 0.35);

        $this->assertSame(0.0, $r['rate']);
        $this->assertSame(CR::SOURCE_SERVICE, $r['source']);
    }

    public function test_un_cero_de_la_persona_tambien_manda(): void
    {
        // Alguien a sueldo fijo, sin comisión, aunque los servicios sí paguen.
        $r = CR::resolve(person: 0.0, service: 0.40);

        $this->assertSame(0.0, $r['rate']);
        $this->assertSame(CR::SOURCE_PERSON, $r['source']);
    }

    public function test_un_porcentaje_imposible_se_recorta(): void
    {
        // Alguien escribió 150 en un campo que espera porcentaje: sin recorte,
        // la comisión saldría mayor que el precio del servicio. Se corta acá
        // porque el dato puede entrar por la API, por carga masiva o por un
        // seeder, no sólo por el formulario.
        $this->assertSame(1.0, CR::resolve(person: 1.5)['rate']);
        $this->assertSame(0.0, CR::resolve(person: -0.2)['rate']);
    }

    public function test_el_motivo_se_puede_mostrar_en_pantalla(): void
    {
        // Sin esto la pantalla sólo puede decir "30%" y quien pregunta por qué
        // se queda sin respuesta.
        $this->assertSame('Porcentaje de la persona', CR::label(CR::SOURCE_PERSON));
        $this->assertSame('Porcentaje de la categoría', CR::label(CR::SOURCE_CATEGORY));
        $this->assertSame('Sin comisión', CR::label(CR::SOURCE_NONE));
    }
}
