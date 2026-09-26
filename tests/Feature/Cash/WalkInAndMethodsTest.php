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
        PermissionCatalog::applyRole($this->admin, PermissionCatalog::ROLE_ADMIN);

        $this->manicurista = User::create([
            'business_id' => $this->business->id, 'name' => 'Maria',
            'email' => 'maria@prueba.test', 'password' => Hash::make('password123'), 'is_active' => true,
        ]);
        PermissionCatalog::applyRole($this->manicurista, PermissionCatalog::ROLE_STAFF);

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
        // La migración ya siembra el catálogo real (con Bold); estas pruebas
        // hablan de uno propio, así que se parte de cero.
        PlatformPaymentMethod::query()->delete();

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

    public function test_elegir_un_medio_reusa_el_que_vino_del_sistema_anterior(): void
    {
        // Un negocio que viene del sistema anterior: sus medios no apuntan
        // al catálogo, pero son los que tienen los cobros.
        PaymentMethod::withoutGlobalScope('business')->where('business_id', $this->business->id)->delete();
        $viejo = PaymentMethod::create([
            'business_id' => $this->business->id,
            'name' => 'Efectivo',
            'counts_as_cash' => true,
            'is_active' => true,
        ]);
        $efectivo = PlatformPaymentMethod::where('key', 'efectivo')->first();

        app(PaymentMethodProvisioner::class)->sync($this->business, [$efectivo->id]);

        $activos = PaymentMethod::withoutGlobalScope('business')
            ->where('business_id', $this->business->id)->where('is_active', true)->get();
        $this->assertSame([$viejo->id], $activos->pluck('id')->all());
        $this->assertSame($efectivo->id, $viejo->fresh()->platform_payment_method_id);
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
            'client_name' => 'Alguien sin cita',
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

    public function test_un_servicio_sin_cita_le_crea_ficha_al_cliente(): void
    {
        Sanctum::actingAs($this->manicurista);

        $efectivo = PaymentMethod::withoutGlobalScope('business')
            ->where('business_id', $this->business->id)->where('name', 'Efectivo')->first();

        $this->postJson('/api/v1/walk-in', [
            'service_id' => $this->service->id,
            'client_name' => 'Alguien sin cita',
            'client_phone' => '3007776655',
            'payment_method_id' => $efectivo->id,
        ])->assertCreated();

        // Bug real: la logica de crear ficha vivia dentro del controlador de
        // citas y el walk-in la esquivaba, asi que el cliente quedaba con el
        // nombre suelto y sin aparecer en el listado. Ahora ambos caminos usan
        // el mismo ClientResolver.
        $client = \App\Models\Client::withoutGlobalScope('business')
            ->where('phone', '573007776655')->first();

        $this->assertNotNull($client);
        $this->assertSame('Alguien', $client->name);
        $this->assertSame('sin cita', $client->last_name);

        Sanctum::actingAs($this->admin);
        $this->assertCount(1, $this->getJson("/api/v1/clients/{$client->id}")->json('history'));
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

    public function test_ponerse_al_dia_deja_el_servicio_y_la_plata_en_su_dia(): void
    {
        /*
         * Alejandra empezo a usar la aplicacion con una semana de servicios sin
         * subir. Los sube el miercoles, pero los presto el sabado.
         *
         * Dos cosas tienen que caer en el SABADO: el servicio -- del que sale
         * su comision de ese corte -- y la plata, porque el cierre de caja se
         * arma por la fecha del cobro y ese dinero entro al cajon el sabado. Si
         * cae hoy, el arqueo del miercoles pide una plata que no esta y el del
         * sabado queda corto para siempre.
         */
        Sanctum::actingAs($this->manicurista);

        $tz = $this->business->businessTimezone();
        $efectivo = PaymentMethod::withoutGlobalScope('business')
            ->where('business_id', $this->business->id)->where('name', 'Efectivo')->first();

        $sabado = CarbonImmutable::now($tz)->subDays(4)->setTime(14, 0);

        $this->postJson('/api/v1/walk-in', [
            'service_id' => $this->service->id,
            'client_name' => 'Sin cita',
            'started_at' => $sabado->format('Y-m-d\TH:i'),
            'payment_method_id' => $efectivo->id,
        ])->assertCreated();

        Sanctum::actingAs($this->admin);

        $eseDia = $this->getJson('/api/v1/cash/closing/preview?date='.$sabado->toDateString())->assertOk();
        $this->assertEqualsWithDelta(45000, $eseDia->json('total_charged'), 0.01);

        $hoy = CarbonImmutable::now($tz)->toDateString();
        $this->assertEqualsWithDelta(
            0,
            $this->getJson("/api/v1/cash/closing/preview?date={$hoy}")->json('total_charged'),
            0.01,
            'La plata del sabado no puede aparecer en el cierre de hoy.',
        );
    }

    public function test_registrar_lo_de_un_dia_sin_jornada_no_se_rechaza(): void
    {
        /*
         * Registrar no es agendar. Si el servicio se presto un dia en que ella
         * no tenia horario -- o fuera de el --, el sistema no puede negarse: ya
         * paso, y hay que cobrarlo y comisionarlo igual. La jornada protege la
         * agenda del futuro.
         */
        Sanctum::actingAs($this->manicurista);

        $tz = $this->business->businessTimezone();
        $aDestiempo = CarbonImmutable::now($tz)->subDays(3)->setTime(23, 0);

        $this->postJson('/api/v1/walk-in', [
            'service_id' => $this->service->id,
            'client_name' => 'Sin cita',
            'started_at' => $aDestiempo->format('Y-m-d\TH:i'),
        ])->assertCreated();
    }

    public function test_el_cierre_separa_lo_que_cada_profesional_cobro_en_efectivo(): void
    {
        Sanctum::actingAs($this->manicurista);

        $efectivo = PaymentMethod::withoutGlobalScope('business')
            ->where('business_id', $this->business->id)->where('name', 'Efectivo')->first();
        $datafono = PaymentMethod::withoutGlobalScope('business')
            ->where('business_id', $this->business->id)->where('name', 'Datáfono')->first();

        /*
         * Con el reloj quieto a media tarde.
         *
         * La prueba dice "hace tres horas" queriendo decir "hoy", y eso es
         * mentira si corre a las 00:24: hace tres horas era ayer, y el cobro
         * cae -- con razon -- en el cierre de ayer. Se congela el reloj para
         * que lo que se prueba sea el reparto por medio de pago y no la hora a
         * la que alguien lanzo la suite.
         */
        $this->travelTo(CarbonImmutable::now($this->business->businessTimezone())->setTime(15, 0));

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
        PermissionCatalog::applyRole($reception, PermissionCatalog::ROLE_RECEPTION);
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
