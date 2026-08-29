<?php

namespace Tests\Feature\SuperAdmin;

use App\Models\Business;
use App\Models\PaymentMethod;
use App\Models\Service;
use App\Models\User;
use App\Support\BusinessFeaturePresets;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Scheduling\SchedulingScenario;
use Tests\TestCase;

/**
 * El panel de plataforma: administrar todos los spas y barberias.
 *
 * Lo que estas pruebas cuidan sobre todo es la frontera. El superadmin cruza
 * tenants a proposito; cualquier otro NO debe poder, ni siquiera siendo admin
 * de su propio negocio.
 */
class PlatformTest extends TestCase
{
    use RefreshDatabase, SchedulingScenario;

    private User $platform;

    protected function setUp(): void
    {
        parent::setUp();

        PermissionCatalog::sync();

        $this->platform = User::create([
            'business_id' => null,
            'name' => 'Plataforma',
            'email' => 'plataforma@nexolu.test',
            'password' => Hash::make('password123'),
            'is_super_admin' => true,
            'is_active' => true,
        ]);
    }

    private function actAsPlatform(): void
    {
        Sanctum::actingAs($this->platform);
    }

    public function test_crear_un_negocio_lo_deja_listo_para_operar(): void
    {
        $this->actAsPlatform();

        $response = $this->postJson('/api/v1/superadmin/businesses', [
            'name' => 'Barbería El Corte',
            'vertical' => BusinessFeaturePresets::VERTICAL_BARBERIA,
            'subscription_plan' => BusinessFeaturePresets::PLAN_PRO,
            'owner_name' => 'Carlos',
            'owner_email' => 'carlos@corte.test',
            'owner_password' => 'password123',
        ]);

        $response->assertCreated()
            ->assertJsonPath('slug', 'barberia-el-corte')
            ->assertJsonPath('vertical', BusinessFeaturePresets::VERTICAL_BARBERIA)
            ->assertJsonPath('is_active', true);

        $businessId = $response->json('id');

        // Un negocio a medias -- sin dueno que entre, o sin con que cobrar --
        // no sirve y hay que limpiarlo a mano. Por eso va todo en una
        // transaccion.
        $owner = User::withoutGlobalScope('business')->where('email', 'carlos@corte.test')->first();
        $this->assertNotNull($owner);
        $this->assertSame($businessId, $owner->business_id);
        $this->assertTrue($owner->hasRole(PermissionCatalog::ROLE_ADMIN));

        $this->assertSame(
            1,
            PaymentMethod::withoutGlobalScope('business')->where('business_id', $businessId)->count(),
        );

        // La vertical ajusta los defaults: una barberia no necesita cabinas.
        $this->assertFalse($response->json('resolved_features.multi_resource'));
        $this->assertTrue($response->json('resolved_features.scheduling'));
    }

    public function test_dos_negocios_con_el_mismo_nombre_reciben_slugs_distintos(): void
    {
        $this->actAsPlatform();

        $payload = fn (string $email) => [
            'name' => 'Nails Studio',
            'vertical' => BusinessFeaturePresets::VERTICAL_SPA_UNAS,
            'owner_name' => 'Dueña',
            'owner_email' => $email,
            'owner_password' => 'password123',
        ];

        $this->postJson('/api/v1/superadmin/businesses', $payload('a@t.test'))
            ->assertCreated()->assertJsonPath('slug', 'nails-studio');
        $this->postJson('/api/v1/superadmin/businesses', $payload('b@t.test'))
            ->assertCreated()->assertJsonPath('slug', 'nails-studio-2');
    }

    public function test_el_listado_muestra_todos_los_negocios_con_sus_conteos(): void
    {
        $this->actAsPlatform();
        $uno = $this->makeBusiness();
        $dos = $this->makeBusiness();
        $this->makeResource($uno, 'Maria');
        Service::create([
            'business_id' => $uno->id, 'name' => 'Manicure', 'slug' => 'm-'.uniqid(),
            'duration_min' => 45, 'price' => 45000, 'is_active' => true,
        ]);

        $response = $this->getJson('/api/v1/superadmin/businesses')->assertOk();

        $this->assertGreaterThanOrEqual(2, count($response->json()));

        $row = collect($response->json())->firstWhere('id', $uno->id);
        $this->assertSame(1, $row['counts']['resources']);
        $this->assertSame(1, $row['counts']['services']);
        $this->assertSame(0, collect($response->json())->firstWhere('id', $dos->id)['counts']['services']);
    }

    public function test_cambiar_las_banderas_ignora_las_que_no_existen(): void
    {
        $this->actAsPlatform();
        $business = $this->makeBusiness();

        $response = $this->patchJson("/api/v1/superadmin/businesses/{$business->id}", [
            'feature_flags' => [
                'loyalty' => false,
                // Una bandera inventada quedaria guardada para siempre sin
                // que nada la lea.
                'flag_que_no_existe' => true,
            ],
        ])->assertOk();

        $this->assertArrayNotHasKey('flag_que_no_existe', $response->json('feature_flags'));
        $this->assertFalse($response->json('resolved_features.loyalty'));
    }

    public function test_cambiar_la_configuracion_de_agenda_afecta_la_disponibilidad(): void
    {
        $this->actAsPlatform();
        $business = $this->makeBusiness(['slot_granularity_min' => 60]);
        $resource = $this->makeResource($business, start: '09:00:00', end: '12:00:00');
        $service = $this->makeService($business, 60, [$resource]);

        $admin = User::create([
            'business_id' => $business->id, 'name' => 'Admin', 'email' => 'admin@t.test',
            'password' => Hash::make('password123'), 'is_active' => true,
        ]);
        $admin->assignRole(PermissionCatalog::ROLE_ADMIN);

        $fecha = $this->wednesday()->toDateString();

        Sanctum::actingAs($admin);
        $antes = count($this->getJson("/api/v1/availability?service_id={$service->id}&date={$fecha}")->json('slots'));

        $this->actAsPlatform();
        $this->patchJson("/api/v1/superadmin/businesses/{$business->id}", [
            'scheduling_settings' => ['slot_granularity_min' => 30],
        ])->assertOk();

        // fresh() no es cosmetico: Sanctum::actingAs reutiliza el MISMO objeto
        // PHP entre peticiones, con su relacion `business` ya cargada. Sin
        // recargarlo, el segundo request lee la configuracion vieja y la
        // prueba mide algo que no cambio. En produccion no pasa: cada request
        // resuelve el usuario desde la base.
        Sanctum::actingAs($admin->fresh());
        $despues = count($this->getJson("/api/v1/availability?service_id={$service->id}&date={$fecha}")->json('slots'));

        // Con granularidad de 30 en vez de 60 caben mas horarios de inicio.
        $this->assertGreaterThan($antes, $despues);
    }

    public function test_suspender_un_negocio_no_toca_sus_datos(): void
    {
        $this->actAsPlatform();
        $business = $this->makeBusiness();
        $this->makeResource($business, 'Maria');

        $this->patchJson("/api/v1/superadmin/businesses/{$business->id}/toggle")
            ->assertOk()
            ->assertJsonPath('is_active', false);

        // Suspender es impedir entrar, no borrar. Un negocio que se atrasa y
        // despues se pone al dia tiene que encontrar su agenda intacta.
        $this->assertSame(1, $this->patchJson("/api/v1/superadmin/businesses/{$business->id}/toggle")
            ->assertJsonPath('is_active', true)
            ->json('counts.resources'));
    }

    public function test_el_dashboard_lista_los_negocios_sin_actividad(): void
    {
        $this->actAsPlatform();
        $this->makeBusiness();

        $this->getJson('/api/v1/superadmin/dashboard')
            ->assertOk()
            ->assertJsonPath('businesses.total', 1)
            ->assertJsonCount(1, 'idle');
    }

    public function test_el_admin_de_un_negocio_no_entra_al_panel_de_plataforma(): void
    {
        $business = $this->makeBusiness();
        $admin = User::create([
            'business_id' => $business->id, 'name' => 'Admin', 'email' => 'admin@t.test',
            'password' => Hash::make('password123'), 'is_active' => true,
        ]);
        $admin->assignRole(PermissionCatalog::ROLE_ADMIN);

        Sanctum::actingAs($admin);

        // Ser admin de SU spa no da acceso a los demas. La marca de plataforma
        // es una columna aparte justamente para que no se herede de un rol.
        foreach (['/businesses', '/dashboard', '/feature-catalog'] as $ruta) {
            $this->getJson("/api/v1/superadmin{$ruta}")->assertStatus(403);
        }

        $this->postJson('/api/v1/superadmin/businesses', [
            'name' => 'Mio', 'vertical' => 'barberia',
            'owner_name' => 'x', 'owner_email' => 'x@t.test', 'owner_password' => 'password123',
        ])->assertStatus(403);
    }

    public function test_un_superadmin_desactivado_no_entra(): void
    {
        $this->platform->update(['is_active' => false]);
        Sanctum::actingAs($this->platform);

        $this->getJson('/api/v1/superadmin/businesses')->assertStatus(403);
    }

    public function test_sin_autenticar_el_panel_responde_401(): void
    {
        $this->getJson('/api/v1/superadmin/businesses')->assertStatus(401);
    }

    public function test_el_comando_crea_y_promueve(): void
    {
        $this->artisan('superadmin:create', [
            'email' => 'nuevo@nexolu.test',
            '--password' => 'una-clave-larga-123',
        ])->assertSuccessful();

        $creado = User::withoutGlobalScope('business')->where('email', 'nuevo@nexolu.test')->first();
        $this->assertTrue((bool) $creado->is_super_admin);
        $this->assertNull($creado->business_id);

        // Promover a alguien que ya existe no lo duplica.
        $business = $this->makeBusiness();
        User::create([
            'business_id' => $business->id, 'name' => 'Ya existe', 'email' => 'existe@t.test',
            'password' => Hash::make('password123'), 'is_active' => true,
        ]);

        $this->artisan('superadmin:create', ['email' => 'existe@t.test', '--password' => 'x'])
            ->assertSuccessful();

        $this->assertSame(1, User::withoutGlobalScope('business')->where('email', 'existe@t.test')->count());
        $this->assertTrue((bool) User::withoutGlobalScope('business')->where('email', 'existe@t.test')->first()->is_super_admin);
    }

    public function test_el_superadmin_no_arrastra_un_negocio_en_su_sesion(): void
    {
        $this->actAsPlatform();
        $business = $this->makeBusiness();

        // Sin business_id, el scope global no filtra: es lo que le permite ver
        // todo. Se comprueba explicitamente porque es la propiedad de la que
        // depende todo el panel.
        $this->assertNull($this->platform->business_id);
        $this->assertSame(1, Business::query()->count());
    }
}
