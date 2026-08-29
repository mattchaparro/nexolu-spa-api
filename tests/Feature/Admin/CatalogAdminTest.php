<?php

namespace Tests\Feature\Admin;

use App\Models\Business;
use App\Models\Resource;
use App\Models\Service;
use App\Models\User;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Scheduling\SchedulingScenario;
use Tests\TestCase;

/**
 * Administrar el negocio: servicios, equipo, horarios y quien presta que.
 */
class CatalogAdminTest extends TestCase
{
    use RefreshDatabase, SchedulingScenario;

    private Business $business;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        PermissionCatalog::sync();
        Storage::fake('public');

        $this->business = $this->makeBusiness();
        $this->admin = User::create([
            'business_id' => $this->business->id,
            'name' => 'Admin',
            'email' => 'admin@prueba.test',
            'password' => Hash::make('password123'),
            'is_active' => true,
        ]);
        PermissionCatalog::applyRole($this->admin, PermissionCatalog::ROLE_ADMIN);

        Sanctum::actingAs($this->admin);
    }

    public function test_crear_un_servicio_con_imagen(): void
    {
        $response = $this->postJson('/api/v1/services', [
            'name' => 'Manicure semipermanente',
            'duration_min' => 90,
            'buffer_before_min' => 5,
            'buffer_after_min' => 15,
            'price' => 85000,
            'commission_rate' => 0.30,
            'image' => UploadedFile::fake()->image('servicio.jpg'),
        ]);

        $response->assertCreated()
            ->assertJsonPath('duration_min', 90)
            // El cliente ve 90 minutos; el recurso queda ocupado 110.
            ->assertJsonPath('occupied_min', 110)
            ->assertJsonPath('slug', 'manicure-semipermanente');

        $this->assertNotNull($response->json('image_url'));

        $service = Service::first();
        Storage::disk('public')->assertExists($service->image_path);

        // La ruta lleva el negocio: aunque el bucket sea compartido, dos
        // negocios nunca escriben en la misma carpeta.
        $this->assertStringStartsWith("negocios/{$this->business->id}/servicios/", $service->image_path);
    }

    public function test_no_se_permiten_dos_servicios_con_el_mismo_nombre(): void
    {
        $payload = ['name' => 'Manicure', 'duration_min' => 45, 'price' => 45000];

        $this->postJson('/api/v1/services', $payload)->assertCreated();
        $this->postJson('/api/v1/services', $payload)->assertStatus(422)->assertJsonValidationErrors('name');
    }

    public function test_cambiar_la_imagen_borra_la_anterior(): void
    {
        $id = $this->postJson('/api/v1/services', [
            'name' => 'Manicure', 'duration_min' => 45, 'price' => 45000,
            'image' => UploadedFile::fake()->image('vieja.jpg'),
        ])->json('id');

        $anterior = Service::find($id)->image_path;

        $this->postJson("/api/v1/services/{$id}", [
            'name' => 'Manicure', 'duration_min' => 45, 'price' => 45000,
            'image' => UploadedFile::fake()->image('nueva.jpg'),
        ])->assertOk();

        $nueva = Service::find($id)->image_path;

        $this->assertNotSame($anterior, $nueva);
        Storage::disk('public')->assertMissing($anterior);
        Storage::disk('public')->assertExists($nueva);
    }

    public function test_no_se_aceptan_archivos_que_no_sean_imagen(): void
    {
        // SVG queda fuera a proposito: admite scripts embebidos.
        $this->postJson('/api/v1/services', [
            'name' => 'Manicure', 'duration_min' => 45, 'price' => 45000,
            'image' => UploadedFile::fake()->create('malicioso.svg', 10, 'image/svg+xml'),
        ])->assertStatus(422)->assertJsonValidationErrors('image');
    }

    public function test_desactivar_un_servicio_no_lo_borra(): void
    {
        $id = $this->postJson('/api/v1/services', [
            'name' => 'Manicure', 'duration_min' => 45, 'price' => 45000,
        ])->json('id');

        $this->deleteJson("/api/v1/services/{$id}")->assertOk();

        // Borrarlo dejaria sin nombre a las citas historicas y con ellas a los
        // reportes de meses anteriores.
        $this->assertDatabaseHas('services', ['id' => $id, 'is_active' => false]);
        $this->getJson('/api/v1/services')->assertOk()->assertJsonCount(0);
    }

    public function test_crear_una_profesional_crea_tambien_su_usuario(): void
    {
        $response = $this->postJson('/api/v1/resources', [
            'type' => Resource::TYPE_STAFF,
            'name' => 'Maria',
            'last_name' => 'Lopez',
            'email' => 'maria@prueba.test',
            'password' => 'password123',
            'phone' => '3001112233',
            'color' => '#4f46e5',
        ]);

        $response->assertCreated()->assertJsonPath('name', 'Maria Lopez');

        $user = User::where('email', 'maria@prueba.test')->first();
        $this->assertNotNull($user, 'Crear la profesional debe crear su cuenta para entrar.');
        $this->assertTrue($user->hasRole(PermissionCatalog::ROLE_STAFF));
        $this->assertSame('573001112233', $user->phone);
        $this->assertSame($user->id, Resource::find($response->json('id'))->user_id);
    }

    public function test_una_cabina_no_necesita_usuario(): void
    {
        $response = $this->postJson('/api/v1/resources', [
            'type' => Resource::TYPE_ROOM,
            'name' => 'Cabina 1',
        ]);

        $response->assertCreated()->assertJsonPath('user_id', null);
        $this->assertSame(1, User::count(), 'Solo deberia existir el admin.');
    }

    public function test_desactivar_una_profesional_desactiva_su_cuenta(): void
    {
        $id = $this->postJson('/api/v1/resources', [
            'type' => Resource::TYPE_STAFF,
            'name' => 'Maria',
            'email' => 'maria@prueba.test',
            'password' => 'password123',
        ])->json('id');

        $this->postJson("/api/v1/resources/{$id}", ['is_active' => false])->assertOk();

        // Quien ya no trabaja aca no deberia poder seguir entrando.
        $this->assertFalse((bool) User::where('email', 'maria@prueba.test')->first()->is_active);
    }

    public function test_guardar_el_horario_semanal_reemplaza_el_anterior(): void
    {
        $resource = $this->makeResource($this->business, weekdays: [1, 2, 3, 4, 5, 6]);
        $this->assertSame(6, $resource->schedules()->count());

        $this->putJson("/api/v1/resources/{$resource->id}/schedules", [
            'schedules' => [
                ['weekday' => 2, 'start_time' => '10:00', 'end_time' => '16:00'],
                ['weekday' => 4, 'start_time' => '10:00', 'end_time' => '16:00'],
            ],
        ])->assertOk()->assertJsonCount(2);

        // Se reemplaza porque asi se piensa: "esta es mi semana".
        $this->assertSame(2, $resource->schedules()->count());
    }

    public function test_un_horario_que_termina_antes_de_empezar_se_rechaza(): void
    {
        $resource = $this->makeResource($this->business);

        $this->putJson("/api/v1/resources/{$resource->id}/schedules", [
            'schedules' => [['weekday' => 1, 'start_time' => '18:00', 'end_time' => '09:00']],
        ])->assertStatus(422);
    }

    public function test_asignar_quien_presta_el_servicio_con_su_duracion_y_porcentaje(): void
    {
        $maria = $this->makeResource($this->business, 'Maria');
        $ana = $this->makeResource($this->business, 'Ana');

        $id = $this->postJson('/api/v1/services', [
            'name' => 'Semipermanente',
            'duration_min' => 90,
            'price' => 85000,
            'commission_rate' => 0.30,
            'resources' => [
                ['resource_id' => $maria->id],
                // Ana tarda menos y cobra mas en este servicio.
                ['resource_id' => $ana->id, 'duration_override_min' => 75, 'commission_rate_override' => 0.40],
            ],
        ])->json('id');

        $service = Service::with('resources')->find($id);

        $this->assertSame(90, $service->durationFor($maria));
        $this->assertSame(75, $service->durationFor($ana));
        $this->assertEqualsWithDelta(0.30, $service->commissionRateFor($maria), 0.0001);
        $this->assertEqualsWithDelta(0.40, $service->commissionRateFor($ana), 0.0001);
    }

    public function test_un_recurso_de_otro_negocio_no_se_puede_asignar(): void
    {
        $otroNegocio = $this->makeBusiness();

        // Se inserta directo, sin pasar por el modelo: BelongsToBusiness
        // sobreescribe el business_id con el del usuario autenticado al crear
        // -- esa es justamente su defensa contra inyeccion de tenant. Usando
        // el modelo, este "recurso ajeno" naceria dentro del negocio del
        // admin y la prueba no probaria nada.
        $ajenoId = DB::table('resources')->insertGetId([
            'business_id' => $otroNegocio->id,
            'type' => Resource::TYPE_STAFF,
            'name' => 'Ajena',
            'is_bookable_online' => true,
            'is_active' => true,
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $id = $this->postJson('/api/v1/services', [
            'name' => 'Manicure', 'duration_min' => 45, 'price' => 45000,
            'resources' => [['resource_id' => $ajenoId]],
        ])->json('id');

        // El scope de tenancy no cubre una tabla pivote: el filtro tiene que
        // ser explicito, o el payload puede colar recursos de otro negocio.
        $this->assertSame(0, Service::find($id)->resources()->count());
    }

    public function test_editar_el_precio_no_borra_las_asignaciones(): void
    {
        $maria = $this->makeResource($this->business, 'Maria');

        $id = $this->postJson('/api/v1/services', [
            'name' => 'Manicure', 'duration_min' => 45, 'price' => 45000,
            'resources' => [['resource_id' => $maria->id]],
        ])->json('id');

        // Sin `resources` en el payload, las asignaciones no se tocan.
        $this->postJson("/api/v1/services/{$id}", [
            'name' => 'Manicure', 'duration_min' => 45, 'price' => 50000,
        ])->assertOk();

        $this->assertSame(1, Service::find($id)->resources()->count());
    }

    public function test_un_miembro_del_equipo_no_puede_administrar_el_catalogo(): void
    {
        $staff = User::create([
            'business_id' => $this->business->id,
            'name' => 'Maria',
            'email' => 'maria@prueba.test',
            'password' => Hash::make('password123'),
            'is_active' => true,
        ]);
        PermissionCatalog::applyRole($staff, PermissionCatalog::ROLE_STAFF);
        Sanctum::actingAs($staff);

        $this->postJson('/api/v1/services', ['name' => 'X', 'duration_min' => 30, 'price' => 1000])
            ->assertStatus(403);
        $this->postJson('/api/v1/resources', ['type' => 'staff', 'name' => 'X'])
            ->assertStatus(403);
    }
}
