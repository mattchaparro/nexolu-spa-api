<?php

namespace Tests\Feature\Ai;

use App\Ai\LoQueMasPiden;
use App\Models\Appointment;
use App\Models\Business;
use App\Models\Client;
use App\Models\Service;
use App\Support\PermissionCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Scheduling\SchedulingScenario;
use Tests\TestCase;

/**
 * Lo que se ofrece primero es lo que la gente pide.
 *
 * Una lista de WhatsApp aguanta diez filas y la categoría Manicure de
 * Luxury tiene veintitrés. Alguien decide cuáles diez, y decidía el
 * alfabeto: la clienta veía Cambio de esmalte, Capping y cuatro
 * Recubrimientos, y NO veía Tradicional ni Semipermanente -- que juntos
 * son más de mil de las citas del local. Las dos que quería quedaban
 * fuera de la pantalla.
 */
class LoQueMasPidenTest extends TestCase
{
    use RefreshDatabase, SchedulingScenario;

    private Business $business;

    private Client $cliente;

    private ?\App\Models\Resource $persona = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-09-16 08:00', 'America/Bogota'));
        PermissionCatalog::sync();

        $this->business = $this->makeBusiness();
        $this->cliente = Client::create([
            'business_id' => $this->business->id,
            'name' => 'Carolina',
            'phone' => '573001112233',
            'is_active' => true,
        ]);
    }

    private function citas(Service $servicio, int $cuantas, int $mesesAtras = 1): void
    {
        $persona = $this->persona ??= $this->makeResource($this->business, 'Maria');

        for ($i = 0; $i < $cuantas; $i++) {
            $cuando = CarbonImmutable::now()->subMonths($mesesAtras)->subDays($i);

            $cita = Appointment::create([
                'business_id' => $this->business->id,
                'location_id' => $this->business->primaryLocation()?->id,
                'client_id' => $this->cliente->id,
                'client_name' => $this->cliente->name,
                'client_phone' => $this->cliente->phone,
                'starts_at' => $cuando,
                'ends_at' => $cuando->addHour(),
                'status' => Appointment::STATUS_CONFIRMED,
            ]);

            $cita->items()->create([
                'business_id' => $this->business->id,
                'service_id' => $servicio->id,
                'resource_id' => $persona->id,
                'starts_at' => $cita->starts_at,
                'ends_at' => $cita->ends_at,
                'service_starts_at' => $cita->starts_at,
                'service_ends_at' => $cita->ends_at,
                'price' => $servicio->price,
                'sort_order' => 0,
            ]);
        }
    }

    public function test_el_mas_pedido_va_primero_aunque_empiece_por_z(): void
    {
        $capping = $this->makeService($this->business, name: 'Capping');
        $tradicional = $this->makeService($this->business, name: 'Zradicional');

        $this->citas($capping, 2);
        $this->citas($tradicional, 9);

        $orden = LoQueMasPiden::ordenar(
            $this->business->id,
            collect([$capping, $tradicional]),
        )->pluck('name');

        $this->assertSame(['Zradicional', 'Capping'], $orden->all());
    }

    public function test_lo_que_nadie_pide_queda_al_final(): void
    {
        $pedido = $this->makeService($this->business, name: 'Semipermanente');
        $nunca = $this->makeService($this->business, name: 'Alfabeticamente primero');

        $this->citas($pedido, 3);

        $orden = LoQueMasPiden::ordenar(
            $this->business->id,
            collect([$nunca, $pedido]),
        )->pluck('name');

        $this->assertSame(['Semipermanente', 'Alfabeticamente primero'], $orden->all());
    }

    public function test_lo_que_se_dejo_de_prestar_hace_anos_no_encabeza(): void
    {
        // Un servicio que vendió muchísimo en 2023 y nada desde entonces
        // no puede seguir siendo lo primero que ve una clienta hoy.
        $viejo = $this->makeService($this->business, name: 'Lo de antes');
        $deAhora = $this->makeService($this->business, name: 'Lo de ahora');

        $this->citas($viejo, 20, mesesAtras: 30);
        $this->citas($deAhora, 2);

        $orden = LoQueMasPiden::ordenar(
            $this->business->id,
            collect([$viejo, $deAhora]),
        )->pluck('name');

        $this->assertSame(['Lo de ahora', 'Lo de antes'], $orden->all());
    }

    public function test_si_el_local_ordeno_su_catalogo_manda_el_local(): void
    {
        /*
         * Ahí dijo a propósito qué quiere empujar, y eso puede no ser lo
         * más vendido: lo nuevo, o lo que deja más margen. Los seis años
         * de historia no le pasan por encima a una decisión del dueño.
         */
        $masVendido = $this->makeService($this->business, name: 'El de siempre');
        $masVendido->update(['sort_order' => 9]);

        $elQueQuierenEmpujar = $this->makeService($this->business, name: 'El nuevo');
        $elQueQuierenEmpujar->update(['sort_order' => 1]);

        $this->citas($masVendido, 50);

        $orden = LoQueMasPiden::ordenar(
            $this->business->id,
            collect([$elQueQuierenEmpujar, $masVendido]),
        )->pluck('name');

        $this->assertSame(['El nuevo', 'El de siempre'], $orden->all());
    }

    public function test_un_negocio_sin_historia_no_se_rompe(): void
    {
        // Un local que abre hoy: sin citas, el orden que entra es el que
        // sale, y nada explota.
        $uno = $this->makeService($this->business, name: 'Uno');
        $dos = $this->makeService($this->business, name: 'Dos');

        $orden = LoQueMasPiden::ordenar($this->business->id, collect([$uno, $dos]))->pluck('name');

        $this->assertSame(['Uno', 'Dos'], $orden->all());
    }
}
