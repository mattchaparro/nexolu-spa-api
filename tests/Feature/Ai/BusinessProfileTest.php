<?php

namespace Tests\Feature\Ai;

use App\Models\Business;
use App\Models\Location;
use App\Services\Ia\BusinessProfile;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Scheduling\SchedulingScenario;
use Tests\TestCase;

/**
 * Quien es el negocio, en palabras, para el agente.
 *
 * Sin esto el modelo habla como un formulario: no sabe como se llama el
 * local, donde queda ni con cuanta antelacion se cancela, y contesta
 * "consulta con el establecimiento" a preguntas que el sistema si responde.
 */
class BusinessProfileTest extends TestCase
{
    use RefreshDatabase, SchedulingScenario;

    private Business $business;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::now('America/Bogota')->startOfDay()->setTime(10, 0));

        $this->business = $this->makeBusiness([
            'min_booking_notice_min' => 60,
            'min_cancellation_notice_min' => 180,
        ]);
        $this->business->update(['name' => 'Luxury Nails Spa']);
        $this->makeResource($this->business, 'Maria');
    }

    private function perfil(): string
    {
        return $this->app->make(BusinessProfile::class)->for($this->business->fresh());
    }

    public function test_dice_como_se_llama_y_cuando_abre(): void
    {
        $perfil = $this->perfil();

        $this->assertStringContainsString('Luxury Nails Spa', $perfil);
        $this->assertStringContainsString('09:00', $perfil);
    }

    public function test_dice_las_reglas_que_las_herramientas_no_cuentan(): void
    {
        // El preaviso no sale de ninguna herramienta: o va en el prompt o el
        // agente promete cancelaciones que el sistema va a rechazar.
        $perfil = $this->perfil();

        $this->assertStringContainsString('1 hora', $perfil);
        $this->assertStringContainsString('3 horas', $perfil);
    }

    public function test_con_varias_sedes_le_pide_al_agente_que_pregunte(): void
    {
        /*
         * Si el agente no sabe que hay mas de un local, nunca pregunta a cual
         * va -- y la herramienta de disponibilidad le responde "falta la
         * sede" una y otra vez, en un bucle que la clienta ve como un bot
         * roto.
         */
        Location::create([
            'business_id' => $this->business->id,
            'name' => 'Cedritos', 'slug' => 'cedritos',
            'is_primary' => false, 'is_active' => true,
        ]);

        $perfil = $this->perfil();

        $this->assertStringContainsString('Cedritos', $perfil);
        $this->assertStringContainsString('Pregunta siempre a cuál sede', $perfil);
    }

    public function test_no_mete_precios_ni_servicios(): void
    {
        /*
         * Esos salen de las herramientas, que dan el dato de HOY. En el
         * prompt serian una lista de precios congelada que el modelo
         * repetiria con total seguridad meses despues de que cambio.
         */
        $servicio = $this->makeService($this->business, 60, []);
        $servicio->update(['name' => 'Manicure carisimo', 'price' => 999000]);

        $perfil = $this->perfil();

        $this->assertStringNotContainsString('Manicure carisimo', $perfil);
        $this->assertStringNotContainsString('999000', $perfil);
    }

    public function test_cabe_en_el_tope_que_acepta_el_core(): void
    {
        // El Core rechaza mas de 2000 caracteres: recortar aca es perder una
        // linea del perfil, no la llamada entera.
        $this->business->update(['public_profile' => ['about' => str_repeat('spa ', 900)]]);

        $this->assertLessThanOrEqual(2000, mb_strlen($this->perfil()));
    }
}
