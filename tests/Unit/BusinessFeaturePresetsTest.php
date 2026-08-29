<?php

namespace Tests\Unit;

use App\Support\BusinessFeaturePresets;
use PHPUnit\Framework\TestCase;

/**
 * Resolucion de planes y banderas.
 *
 * El POS ya tuvo el bug de mostrarle a un negocio del plan Basico modulos que
 * no contrato, por resolver las banderas en dos sitios distintos. Aca se
 * prueba la unica implementacion.
 */
class BusinessFeaturePresetsTest extends TestCase
{
    public function test_cada_plan_habilita_mas_que_el_anterior(): void
    {
        $basico = array_keys(array_filter(BusinessFeaturePresets::basico()));
        $pro = array_keys(array_filter(BusinessFeaturePresets::pro()));
        $full = array_keys(array_filter(BusinessFeaturePresets::full()));

        $this->assertEmpty(array_diff($basico, $pro), 'Pro debe incluir todo lo de Basico.');
        $this->assertEmpty(array_diff($pro, $full), 'Full debe incluir todo lo de Pro.');
        $this->assertGreaterThan(count($basico), count($pro));
        $this->assertGreaterThan(count($pro), count($full));
    }

    public function test_todo_plan_devuelve_el_catalogo_completo_de_banderas(): void
    {
        // Una bandera ausente del mapa no seria "apagada" sino indefinida, y
        // el front no sabria que hacer con ella.
        foreach ([BusinessFeaturePresets::basico(), BusinessFeaturePresets::pro(), BusinessFeaturePresets::full()] as $plan) {
            foreach (BusinessFeaturePresets::catalog() as $flag) {
                $this->assertArrayHasKey($flag, $plan, "Falta la bandera {$flag}.");
            }
        }
    }

    public function test_el_plan_basico_no_incluye_la_reserva_en_linea(): void
    {
        $this->assertFalse(BusinessFeaturePresets::basico()['online_booking']);
        $this->assertTrue(BusinessFeaturePresets::pro()['online_booking']);
    }

    public function test_todo_plan_incluye_agenda(): void
    {
        // Es un producto de agenda: un plan sin ella no tendria sentido.
        foreach ([BusinessFeaturePresets::basico(), BusinessFeaturePresets::pro(), BusinessFeaturePresets::full()] as $plan) {
            $this->assertTrue($plan['scheduling']);
        }
    }

    public function test_un_negocio_sin_plan_lo_recibe_todo(): void
    {
        // Igual que en el POS con los negocios anteriores a los feature flags:
        // ante la duda se habilita, no se recorta en silencio.
        $this->assertSame(BusinessFeaturePresets::full(), BusinessFeaturePresets::fromPlan(null));
        $this->assertSame(BusinessFeaturePresets::full(), BusinessFeaturePresets::fromPlan('plan-que-no-existe'));
    }

    public function test_la_vertical_ajusta_los_defaults_sin_cambiar_el_plan(): void
    {
        // Una barberia rara vez necesita cabinas.
        $this->assertFalse(BusinessFeaturePresets::fromVertical(BusinessFeaturePresets::VERTICAL_BARBERIA)['multi_resource']);
        $this->assertTrue(BusinessFeaturePresets::fromVertical(BusinessFeaturePresets::VERTICAL_ESTETICA)['multi_resource']);
        $this->assertSame([], BusinessFeaturePresets::fromVertical(BusinessFeaturePresets::VERTICAL_SPA_UNAS));
    }

    public function test_el_catalogo_no_tiene_banderas_repetidas(): void
    {
        $catalog = BusinessFeaturePresets::catalog();

        $this->assertSame($catalog, array_values(array_unique($catalog)));
    }

    public function test_las_verticales_declaradas_coinciden_con_las_constantes(): void
    {
        $this->assertSame(
            ['spa_unas', 'barberia', 'estetica'],
            BusinessFeaturePresets::verticals(),
        );
    }
}
