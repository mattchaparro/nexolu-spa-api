<?php

namespace Tests\Feature\Cash;

use App\Models\Appointment;
use App\Models\Business;
use App\Models\PaymentMethod;
use App\Models\PlatformPaymentMethod;
use App\Models\Resource;
use App\Models\Service;
use App\Models\User;
use App\Services\PaymentMethodProvisioner;
use App\Support\PermissionCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Scheduling\SchedulingScenario;
use Tests\TestCase;

/**
 * Medios de pago del catalogo global, y el servicio sin cita.
 */
class WalkInAndMethodsTest extends TestCase
{
    use RefreshDatabase, SchedulingScenario;

    private Business $business;

    private User $admin;

    private User $manicurista;

    private Resource $maria;

    private Service $service;

    protected function setUp(): void
    {
        parent::setUp();

        PermissionCatalog::sync();
        $this->seedCatalog();

        $this->business = $this->makeBusiness(['slot_granularity_min' => 15]);

        $this->admin = User::create([
            'business_id' => $this->business->id, 'name' => 'Admin',
            'email' => 'admin@prueba.test', 'password' => Hash::make('password123'), 'is_active' => true,
        ]);
        $this->admin->assignRole(PermissionCatalog::ROLE_ADMIN);

        $this->manicurista = User::create([
            'business_id' => $this->business->id, 'name' => 'Maria',
            'email' => 'maria@prueba.test', 'password' => Hash::make('password123'), 'is_active' => true,
        ]);
        $this->manicurista->assignRole(PermissionCatalog::ROLE_STAFF);

        $this->maria = Resource::create([
            'business_id' => $this->business->id,
            'type' => Resource::TYPE_STAFF,
            'user_id' => $this->manicurista->id,
            'name' => 'Maria',
            'is_active' => true,
        ]);

        foreach (range(1, 7) as $weekday) {
            \App\Models\ResourceSchedule::create([
                'business_id' => $this->business->id, 'resource_id' => $this->maria->id,
                'weekday' => $weekday, 'start_time' => '00:00:00', 'end_time' => '23:59:00',
                'effective_from' => '2020-01-01',
            ]);
        }

        $this->service = $this->makeService($this->business, 45, [$this->maria]);
        $this->service->update(['price' => 45000, 'commission_rate' => 0.30]);

        app(PaymentMethodProvisioner::class)->provisionDefaults($this->business);
    }

    private function seedCatalog(): void
    {
        foreach ([
            ['efectivo', 'Efectivo', true],
            ['datafono', 'Datáfono', false],
            ['transferencia', 'Transferencia', false],
            ['nequi', 'Nequi', false],
            ['bono', 'Bono regalo', false],
        ] as $i => [$key, $label, $cash]) {
            PlatformPaymentMethod::create([
                'key' => $key, 'label' => $label, 'counts_as_cash' => $cash, 'sort_order' => $i,
            ]);
        }
    }

    public function test_un_negocio_nuevo_arranca_con_los_medios_por_defecto(): void
    {
        Sanctum::actingAs($this->admin);

        $methods = $this->getJson('/api/v1/payment-methods')->assertOk()->json();

        $this->assertGreaterThanOrEqual(4, count($methods));
        $this->assertContains('Efectivo', array_column($methods, 'name'));
    }

    public function test_el_negocio_elige_del_catalogo_pero_no_define_que_es_efectivo(): void
    {
        Sanctum::actingAs($this->admin);

        $catalogo = $this->getJson('/api/v1/payment-methods/catalog')->assertOk()->json();
        $datafono = collect($catalogo)->firstWhere('label', 'Datáfono');

        // counts_as_cash viene del catalogo global y no es editable por el
        // negocio: dejarlo por negocio permitiria marcar el datafono como
        // efectivo y descuadrar todos los cierres.
        $this->assertFalse($datafono['counts_as_cash']);
        $this->assertTrue(collect($catalogo)->firstWhere('label', 'Efectivo')['counts_as_cash']);
    }

    public function test_habilitar_y_deshabilitar_medios(): void
    {
        Sanctum::actingAs($this->admin);

        $efectivo = PlatformPaymentMethod::where('key', 'efectivo')->first();
        $bono = PlatformPaymentMethod::where('key', 'bono')->first();

        $response = $this->putJson('/api/v1/payment-methods/catalog', [
            'platform_payment_method_ids' => [$efectivo->id, $bono->id],
        ])->assertOk();

        $habilitados = collect($response->json())->where('enabled', true)->pluck('label')->all();

        $this->assertEqualsCanonicalizing(['Efectivo', 'Bono regalo'], $habilitados);

        // Los que se quitan se DESACTIVAN, no se borran: los cobros
        // historicos los referencian.
        $this->assertDatabaseHas('payment_methods', [
            'business_id' => $this->business->id,
            'name' => 'Datáfono',
            'is_active' => false,
        ]);
    }

    public function test_no_se_puede_quedar_sin_ningun_medio(): void
    {
        Sanctum::actingAs($this->admin);

        // Sin medios la caja queda inoperante y el error solo aparece al
        // intentar el primer cobro.
        $this->putJson('/api/v1/payment-methods/catalog', ['platform_payment_method_ids' => []])
            ->assertStatus(422);
    }

    public function test_corregir_el_catalogo_llega_a_los_negocios(): void
    {
        $plataforma = User::create([
            'business_id' => null, 'name' => 'Plataforma', 'email' => 'p@nexolu.test',
            'password' => Hash::make('password123'), 'is_super_admin' => true, 'is_active' => true,
        ]);

        $nequi = PlatformPaymentMethod::where('key', 'nequi')->first();

        Sanctum::actingAs($plataforma);
        $this->patchJson("/api/v1/superadmin/payment-methods/{$nequi->id}", ['label' => 'Nequi / Daviplata'])
            ->assertOk();

        // El negocio vuelve a sincronizar y recibe la correccion.
        Sanctum::actingAs($this->admin);
        $ids = PlatformPaymentMethod::where('is_active', true)->pluck('id')->all();
        $this->putJson('/api/v1/payment-methods/catalog', ['platform_payment_method_ids' => $ids])->assertOk();

        $this->assertDatabaseHas('payment_methods', [
            'business_id' => $this->business->id,
            'name' => 'Nequi / Daviplata',
        ]);
    }

    public function test_una_manicurista_registra_y_cobra_a_alguien_sin_cita(): void
    {
        Sanctum::actingAs($this->manicurista);

        $efectivo = PaymentMethod::withoutGlobalScope('business')
            ->where('business_id', $this->business->id)->where('name', 'Efectivo')->first();

        $response = $this->postJson('/api/v1/walk-in', [
            'service_id' => $this->service->id,
            // Sin resource_id: se asume ella misma. Elegirse en un
            // desplegable seria absurdo.
            'client_name' => 'Señora que llegó',
            'payment_method_id' => $efectivo->id,
        ])->assertCreated();

        $this->assertSame(Appointment::STATUS_COMPLETED, $response->json('status'));
        $this->assertTrue($response->json('is_paid'));
        $this->assertEqualsWithDelta(45000, $response->json('total'), 0.01);
        $this->assertSame('Maria', $response->json('items.0.resource_name'));
        // 30% de 45.000: la comision queda registrada igual que en una cita
        // agendada.
        $this->assertEqualsWithDelta(13500, $response->json('commission_total'), 0.01);
    }

    public function test_un_servicio_sin_cita_entra_al_cierre_del_dia(): void
    {
        Sanctum::actingAs($this->manicurista);

        $efectivo = PaymentMethod::withoutGlobalScope('business')
            ->where('business_id', $this->business->id)->where('name', 'Efectivo')->first();

        $this->postJson('/api/v1/walk-in', [
            'service_id' => $this->service->id,
            'client_name' => 'Sin cita',
            'payment_method_id' => $efectivo->id,
        ])->assertCreated();

        // No puede quedar fuera de los reportes solo por no haber estado
        // agendado: por dentro es una cita normal.
        Sanctum::actingAs($this->admin);
        $hoy = CarbonImmutable::now($this->business->businessTimezone())->toDateString();

        $preview = $this->getJson("/api/v1/cash/closing/preview?date={$hoy}")->assertOk();

        $this->assertEqualsWithDelta(45000, $preview->json('total_charged'), 0.01);
        $this->assertSame('Maria', $preview->json('by_resource.0.name'));
        $this->assertEqualsWithDelta(45000, $preview->json('by_resource.0.cash'), 0.01);
    }

    public function test_el_cierre_separa_lo_que_cada_profesional_cobro_en_efectivo(): void
    {
        Sanctum::actingAs($this->manicurista);

        $efectivo = PaymentMethod::withoutGlobalScope('business')
            ->where('business_id', $this->business->id)->where('name', 'Efectivo')->first();
        $datafono = PaymentMethod::withoutGlobalScope('business')
            ->where('business_id', $this->business->id)->where('name', 'Datáfono')->first();

        $now = CarbonImmutable::now($this->business->businessTimezone());

        foreach ([[$efectivo, 3], [$datafono, 2]] as [$metodo, $horasAtras]) {
            $this->postJson('/api/v1/walk-in', [
                'service_id' => $this->service->id,
                'started_at' => $now->subHours($horasAtras)->format('Y-m-d H:i:s'),
                'client_name' => 'Cliente',
                'payment_method_id' => $metodo->id,
            ])->assertCreated();
        }

        Sanctum::actingAs($this->admin);
        $preview = $this->getJson('/api/v1/cash/closing/preview?date='.$now->toDateString())->assertOk();

        $maria = collect($preview->json('by_resource'))->firstWhere('name', 'Maria');

        // Lo que Maria debe ENTREGAR es lo que cobro en efectivo, no todo lo
        // que facturo. Es exactamente lo que se cuadra contra la caja.
        $this->assertEqualsWithDelta(90000, $maria['charged'], 0.01);
        $this->assertEqualsWithDelta(45000, $maria['cash'], 0.01);
        $this->assertEqualsWithDelta(45000, $maria['other'], 0.01);
    }

    public function test_recepcion_debe_decir_quien_presto_el_servicio(): void
    {
        $reception = User::create([
            'business_id' => $this->business->id, 'name' => 'Recepcion',
            'email' => 'recepcion@prueba.test', 'password' => Hash::make('password123'), 'is_active' => true,
        ]);
        $reception->assignRole(PermissionCatalog::ROLE_RECEPTION);
        Sanctum::actingAs($reception);

        // Recepcion no es una profesional: no se puede adivinar quien atendio.
        $this->postJson('/api/v1/walk-in', [
            'service_id' => $this->service->id,
            'client_name' => 'Alguien',
        ])->assertStatus(422);

        $this->postJson('/api/v1/walk-in', [
            'service_id' => $this->service->id,
            'resource_id' => $this->maria->id,
            'client_name' => 'Alguien',
        ])->assertCreated();
    }

    public function test_un_servicio_sin_cita_puede_quedar_sin_cobrar(): void
    {
        Sanctum::actingAs($this->manicurista);

        $response = $this->postJson('/api/v1/walk-in', [
            'service_id' => $this->service->id,
            'client_name' => 'Paga despues',
        ])->assertCreated();

        $this->assertFalse($response->json('is_paid'));
        $this->assertSame(Appointment::STATUS_PENDING, $response->json('status'));
    }

    public function test_el_turno_esta_apagado_por_defecto(): void
    {
        Sanctum::actingAs($this->admin);

        // En un spa nadie abre y cierra caja por turnos. La funcion existe
        // para quien tenga cajera dedicada, pero no se enciende sola.
        $this->assertFalse($this->business->hasFeature('cash_shift'));
        $this->getJson('/api/v1/cash/shift')->assertStatus(403);
        $this->getJson('/api/v1/cash/closing/preview')->assertOk();
    }
}
