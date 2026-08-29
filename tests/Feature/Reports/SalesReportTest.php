<?php

namespace Tests\Feature\Reports;

use App\Models\Business;
use App\Models\PaymentMethod;
use App\Models\Resource;
use App\Models\ResourceSchedule;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\User;
use App\Support\PermissionCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Scheduling\SchedulingScenario;
use Tests\TestCase;

/**
 * El reporte de ventas.
 *
 * Responde las preguntas que el dueño hace todos los días: cuánto entró, quién
 * lo hizo, por qué medio, y cuánto de eso es comisión.
 */
class SalesReportTest extends TestCase
{
    use RefreshDatabase, SchedulingScenario;

    private Business $business;

    private User $admin;

    private Resource $ana;

    private Resource $lucia;

    private Service $manicure;

    private Service $pestanas;

    private PaymentMethod $efectivo;

    private PaymentMethod $nequi;

    protected function setUp(): void
    {
        parent::setUp();

        PermissionCatalog::sync();

        $this->business = $this->makeBusiness();

        $this->admin = User::create([
            'business_id' => $this->business->id, 'name' => 'Dueña',
            'email' => 'admin@prueba.test', 'password' => Hash::make('password123'), 'is_active' => true,
        ]);
        PermissionCatalog::applyRole($this->admin, PermissionCatalog::ROLE_ADMIN);

        $this->ana = $this->staff('Ana', 0.50);
        $this->lucia = $this->staff('Lucia', 0.30);

        $this->manicure = $this->makeService($this->business, 60, [$this->ana, $this->lucia]);
        $this->manicure->update(['name' => 'Manicure', 'price' => 100000]);

        $this->pestanas = $this->makeService($this->business, 90, [$this->ana, $this->lucia]);
        $this->pestanas->update(['name' => 'Pestañas', 'price' => 200000]);

        $this->efectivo = PaymentMethod::create([
            'business_id' => $this->business->id, 'name' => 'Efectivo',
            'counts_as_cash' => true, 'is_active' => true, 'sort_order' => 1,
        ]);
        $this->nequi = PaymentMethod::create([
            'business_id' => $this->business->id, 'name' => 'Nequi',
            'counts_as_cash' => false, 'is_active' => true, 'sort_order' => 2,
        ]);

        Sanctum::actingAs($this->admin->fresh());
    }

    private function staff(string $name, float $rate): Resource
    {
        $resource = Resource::create([
            'business_id' => $this->business->id, 'type' => Resource::TYPE_STAFF,
            'name' => $name, 'is_active' => true, 'commission_rate' => $rate,
        ]);

        foreach (range(1, 7) as $weekday) {
            ResourceSchedule::create([
                'business_id' => $this->business->id, 'resource_id' => $resource->id,
                'weekday' => $weekday, 'start_time' => '00:00:00', 'end_time' => '23:59:00',
                'effective_from' => '2020-01-01',
            ]);
        }

        return $resource;
    }

    /** Un servicio prestado y cobrado, con la fecha de cobro que se indique. */
    private function vender(
        Resource $resource,
        Service $service,
        PaymentMethod $method,
        int $diasAtras = 0,
        int $hora = 10,
    ): void {
        $cuando = CarbonImmutable::now($this->business->businessTimezone())
            ->subDays($diasAtras)->setTime($hora, 0);

        $cita = $this->postJson('/api/v1/walk-in', [
            'service_id' => $service->id,
            'resource_id' => $resource->id,
            'started_at' => $cuando->format('Y-m-d H:i:s'),
            'client_name' => 'Alguien',
            'payment_method_id' => $method->id,
        ])->assertCreated();

        // El cobro se sella con la hora real; se corre a la fecha del servicio,
        // que es lo que pasaría en producción cobrando el mismo día.
        \App\Models\Appointment::withoutGlobalScope('business')
            ->whereKey($cita->json('id'))
            ->update(['checked_out_at' => $cuando->utc()]);
    }

    private function reporte(array $params = []): \Illuminate\Testing\TestResponse
    {
        return $this->getJson('/api/v1/reports/sales?'.http_build_query($params))->assertOk();
    }

    /*
    |--------------------------------------------------------------------------
    | ¿Cuánto entró?
    |--------------------------------------------------------------------------
    */

    public function test_por_defecto_muestra_hoy(): void
    {
        $this->vender($this->ana, $this->manicure, $this->efectivo);
        $this->vender($this->lucia, $this->manicure, $this->efectivo, hora: 12);
        // De ayer: no debe contarse.
        $this->vender($this->ana, $this->pestanas, $this->efectivo, diasAtras: 1);

        $totales = $this->reporte()->json('totals');

        $this->assertSame(2, $totales['services']);
        $this->assertEqualsWithDelta(200000, $totales['charged'], 0.01);
    }

    public function test_cuanto_de_lo_que_entro_es_comision(): void
    {
        // Ana al 50% sobre 100.000, Lucia al 30% sobre 100.000.
        $this->vender($this->ana, $this->manicure, $this->efectivo);
        $this->vender($this->lucia, $this->manicure, $this->efectivo, hora: 12);

        $totales = $this->reporte()->json('totals');

        $this->assertEqualsWithDelta(80000, $totales['commission'], 0.01);
        // Lo que le queda al negocio antes de arriendo e insumos.
        $this->assertEqualsWithDelta(120000, $totales['after_commission'], 0.01);
        $this->assertEqualsWithDelta(100000, $totales['average_ticket'], 0.01);
    }

    /*
    |--------------------------------------------------------------------------
    | ¿Cuánto hizo cada persona?
    |--------------------------------------------------------------------------
    */

    public function test_cuanto_entro_por_cada_persona(): void
    {
        $this->vender($this->ana, $this->manicure, $this->efectivo);
        $this->vender($this->ana, $this->pestanas, $this->efectivo, hora: 12);
        $this->vender($this->lucia, $this->manicure, $this->efectivo, hora: 14);

        $porPersona = collect($this->reporte()->json('by_person'));

        $ana = $porPersona->firstWhere('name', 'Ana');
        $this->assertSame(2, $ana['services']);
        $this->assertEqualsWithDelta(300000, $ana['charged'], 0.01);
        $this->assertEqualsWithDelta(150000, $ana['commission'], 0.01);
        // Su porcentaje efectivo del período: con servicios a porcentajes
        // distintos no sería ninguno de ellos, y es el número que dice de
        // verdad cuánto cuesta esa persona.
        $this->assertEqualsWithDelta(0.50, $ana['effective_rate'], 0.0001);

        // De mayor a menor: la primera fila es la que más vendió.
        $this->assertSame('Ana', $porPersona->first()['name']);
    }

    public function test_el_porcentaje_efectivo_promedia_servicios_a_tasas_distintas(): void
    {
        // Ana va al 50%, pero en pestañas se pactó 20%.
        $this->pestanas->resources()->updateExistingPivot($this->ana->id, [
            'commission_rate_override' => 0.20,
        ]);

        $this->vender($this->ana, $this->manicure, $this->efectivo);   // 100k al 50% = 50k
        $this->vender($this->ana, $this->pestanas, $this->efectivo, hora: 12); // 200k al 20% = 40k

        $ana = collect($this->reporte()->json('by_person'))->firstWhere('name', 'Ana');

        $this->assertEqualsWithDelta(90000, $ana['commission'], 0.01);
        // 90.000 sobre 300.000: ni 50% ni 20%.
        $this->assertEqualsWithDelta(0.30, $ana['effective_rate'], 0.0001);
    }

    public function test_se_puede_filtrar_por_persona(): void
    {
        $this->vender($this->ana, $this->manicure, $this->efectivo);
        $this->vender($this->lucia, $this->manicure, $this->efectivo, hora: 12);

        $reporte = $this->reporte(['resource_id' => $this->lucia->id]);

        $this->assertEqualsWithDelta(100000, $reporte->json('totals.charged'), 0.01);
        $this->assertSame(['Lucia'], array_column($reporte->json('by_person'), 'name'));
    }

    /*
    |--------------------------------------------------------------------------
    | ¿Cuánto entró por Nequi?
    |--------------------------------------------------------------------------
    */

    public function test_cuanto_entro_por_cada_medio_de_pago(): void
    {
        $this->vender($this->ana, $this->manicure, $this->efectivo);
        $this->vender($this->ana, $this->pestanas, $this->nequi, hora: 12);

        $porMedio = collect($this->reporte()->json('by_payment_method'));

        $nequi = $porMedio->firstWhere('name', 'Nequi');
        $this->assertEqualsWithDelta(200000, $nequi['charged'], 0.01);
        $this->assertFalse($nequi['counts_as_cash']);

        // Y aparte, cuánto de todo lo del período entró en efectivo.
        $this->assertEqualsWithDelta(100000, $this->reporte()->json('totals.cash'), 0.01);
    }

    public function test_se_puede_filtrar_por_medio_de_pago(): void
    {
        $this->vender($this->ana, $this->manicure, $this->efectivo);
        $this->vender($this->ana, $this->pestanas, $this->nequi, hora: 12);

        $reporte = $this->reporte(['payment_method_id' => $this->nequi->id]);

        $this->assertEqualsWithDelta(200000, $reporte->json('totals.charged'), 0.01);
    }

    /*
    |--------------------------------------------------------------------------
    | Rangos
    |--------------------------------------------------------------------------
    */

    public function test_una_semana_se_desglosa_dia_por_dia(): void
    {
        $tz = $this->business->businessTimezone();

        $this->vender($this->ana, $this->manicure, $this->efectivo, diasAtras: 3);
        $this->vender($this->ana, $this->manicure, $this->efectivo, diasAtras: 1);
        $this->vender($this->lucia, $this->manicure, $this->efectivo, diasAtras: 1, hora: 12);

        $reporte = $this->reporte([
            'from' => CarbonImmutable::now($tz)->subDays(6)->toDateString(),
            'to' => CarbonImmutable::now($tz)->toDateString(),
        ]);

        $porDia = collect($reporte->json('by_day'));

        $this->assertCount(2, $porDia);
        // Ordenado por fecha, no por monto: es una línea de tiempo.
        $this->assertTrue($porDia->first()['date'] < $porDia->last()['date']);

        $ayer = $porDia->firstWhere('date', CarbonImmutable::now($tz)->subDay()->toDateString());
        $this->assertEqualsWithDelta(200000, $ayer['charged'], 0.01);
    }

    public function test_un_cobro_de_la_noche_no_se_va_al_dia_siguiente(): void
    {
        // 8pm en Bogotá es la 1am del día siguiente en UTC. Sin convertir la
        // zona, esta venta aparecería en el día equivocado.
        $this->vender($this->ana, $this->manicure, $this->efectivo, hora: 20);

        $hoy = CarbonImmutable::now($this->business->businessTimezone())->toDateString();

        $this->assertSame($hoy, $this->reporte()->json('by_day.0.date'));
    }

    public function test_lo_no_cobrado_no_es_venta(): void
    {
        // Prestado pero sin cobrar: la misma regla de la caja y la nómina. Con
        // tres reglas distintas, ningún número cuadraría con ningún otro.
        $this->postJson('/api/v1/walk-in', [
            'service_id' => $this->manicure->id,
            'resource_id' => $this->ana->id,
            'client_name' => 'Sin cobrar',
        ])->assertCreated();

        $this->assertSame(0, $this->reporte()->json('totals.services'));
    }

    public function test_un_rango_absurdo_se_rechaza(): void
    {
        $this->reporte();

        $this->getJson('/api/v1/reports/sales?from=2000-01-01&to=2030-01-01')
            ->assertStatus(422);
    }

    /*
    |--------------------------------------------------------------------------
    | Quién lo puede ver
    |--------------------------------------------------------------------------
    */

    public function test_una_persona_del_equipo_no_ve_el_reporte(): void
    {
        $staff = User::create([
            'business_id' => $this->business->id, 'name' => 'Ana',
            'email' => 'ana@prueba.test', 'password' => Hash::make('password123'), 'is_active' => true,
        ]);
        PermissionCatalog::applyRole($staff, PermissionCatalog::ROLE_STAFF);

        Sanctum::actingAs($staff->fresh());

        // Lo que vende todo el equipo es información del negocio, no suya.
        $this->getJson('/api/v1/reports/sales')->assertForbidden();
    }

    public function test_los_filtros_vienen_en_la_respuesta(): void
    {
        // Para poblar los desplegables sin una segunda petición.
        $filtros = $this->reporte()->json('filters');

        $this->assertEqualsCanonicalizing(
            ['Ana', 'Lucia'],
            array_column($filtros['resources'], 'name'),
        );
        $this->assertEqualsCanonicalizing(
            ['Efectivo', 'Nequi'],
            array_column($filtros['payment_methods'], 'name'),
        );
    }
}
