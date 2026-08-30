<?php

namespace Tests\Unit;

use App\Support\BusinessFeaturePresets;
use App\Support\BusinessPlanLimits;
use PHPUnit\Framework\TestCase;

/**
 * Los topes por plan.
 *
 * El otro eje de la separación: los feature flags deciden QUÉ ve un negocio,
 * esto decide CUÁNTO puede cargar. Lo que se prueba acá son los bordes que
 * dejarían a un local sin poder trabajar.
 */
class BusinessPlanLimitsTest extends TestCase
{
    public function test_cada_plan_trae_su_tope(): void
    {
        $this->assertSame(3, BusinessPlanLimits::fromPlan(BusinessFeaturePresets::PLAN_BASICO)[BusinessPlanLimits::MAX_RESOURCES]);
        $this->assertSame(10, BusinessPlanLimits::fromPlan(BusinessFeaturePresets::PLAN_PRO)[BusinessPlanLimits::MAX_RESOURCES]);
        $this->assertNull(BusinessPlanLimits::fromPlan(BusinessFeaturePresets::PLAN_FULL)[BusinessPlanLimits::MAX_RESOURCES]);
    }

    public function test_un_negocio_sin_plan_no_tiene_topes(): void
    {
        /*
         * Mismo criterio que los feature flags, y la única opción segura: un
         * negocio anterior a esta separación no puede quedarse sin poder
         * agregar gente porque le falte un campo que nadie le llenó.
         */
        $this->assertNull(BusinessPlanLimits::fromPlan(null)[BusinessPlanLimits::MAX_RESOURCES]);
        $this->assertNull(BusinessPlanLimits::fromPlan('un-plan-que-no-existe')[BusinessPlanLimits::MAX_RESOURCES]);
    }

    public function test_cabe_uno_mas_mientras_no_se_llegue_al_tope(): void
    {
        $this->assertTrue(BusinessPlanLimits::allows(3, 0));
        $this->assertTrue(BusinessPlanLimits::allows(3, 2));
        $this->assertFalse(BusinessPlanLimits::allows(3, 3));
    }

    public function test_un_negocio_por_encima_del_tope_no_rompe_nada(): void
    {
        // Bajó de plan con 5 personas cargadas: no puede agregar, pero la
        // pregunta se responde en vez de reventar.
        $this->assertFalse(BusinessPlanLimits::allows(3, 5));
    }

    public function test_sin_tope_siempre_cabe_uno_mas(): void
    {
        $this->assertTrue(BusinessPlanLimits::allows(BusinessPlanLimits::UNLIMITED, 0));
        $this->assertTrue(BusinessPlanLimits::allows(BusinessPlanLimits::UNLIMITED, 500));
    }

    public function test_el_catalogo_viene_etiquetado_para_el_panel(): void
    {
        $descrito = BusinessPlanLimits::describedCatalog();

        $this->assertCount(count(BusinessPlanLimits::catalog()), $descrito);
        $this->assertSame(BusinessPlanLimits::MAX_RESOURCES, $descrito[0]['key']);
        $this->assertNotSame($descrito[0]['key'], $descrito[0]['label']);
    }

    public function test_no_hay_topes_de_uso(): void
    {
        /*
         * Un tope de citas o de clientes se agota justo cuando al negocio le
         * está yendo bien, y deja una agenda sin poder agendar. Si alguien
         * agrega uno, que esta prueba lo obligue a leer el porqué primero.
         */
        foreach (BusinessPlanLimits::catalog() as $key) {
            $this->assertStringNotContainsString('appointments', $key);
            $this->assertStringNotContainsString('clients', $key);
        }
    }
}
