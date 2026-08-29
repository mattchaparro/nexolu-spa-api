<?php

namespace Tests\Feature\Scheduling;

use App\Models\Business;
use App\Models\Client;
use App\Models\User;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Los endpoints de agenda sobre HTTP.
 *
 * Existe porque las pruebas de servicio pasaban `null` como cliente y nunca
 * instanciaban App\Models\Client: al modelo le faltaba el `use` de Model y
 * eso no se descubrio hasta llamar la API de verdad. Todo camino que el
 * frontend recorre necesita al menos una prueba que lo recorra tambien.
 */
class BookingApiTest extends TestCase
{
    use RefreshDatabase, SchedulingScenario;

    private Business $business;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        PermissionCatalog::sync();

        $this->business = $this->makeBusiness(['slot_granularity_min' => 60]);
        $this->admin = User::create([
            'business_id' => $this->business->id,
            'name' => 'Admin',
            'email' => 'admin@prueba.test',
            'password' => Hash::make('password123'),
            'is_active' => true,
        ]);
        $this->admin->assignRole(PermissionCatalog::ROLE_ADMIN);
    }

    private function actAsAdmin(): void
    {
        Sanctum::actingAs($this->admin);
    }

    public function test_login_devuelve_token_usuario_permisos_y_features(): void
    {
        $response = $this->postJson('/api/v1/login', [
            'email' => 'admin@prueba.test',
            'password' => 'password123',
            'device_name' => 'prueba',
        ]);

        $response->assertOk()
            ->assertJsonStructure([
                'token',
                'user' => ['id', 'full_name', 'roles', 'permissions', 'business' => ['resolved_features']],
            ]);

        $this->assertContains('citas.crear', $response->json('user.permissions'));
        $this->assertTrue($response->json('user.business.resolved_features.scheduling'));
    }

    public function test_login_no_revela_si_el_correo_existe(): void
    {
        $inexistente = $this->postJson('/api/v1/login', [
            'email' => 'nadie@prueba.test', 'password' => 'x', 'device_name' => 'p',
        ]);
        $claveMala = $this->postJson('/api/v1/login', [
            'email' => 'admin@prueba.test', 'password' => 'incorrecta', 'device_name' => 'p',
        ]);

        $inexistente->assertStatus(422);
        $claveMala->assertStatus(422);
        $this->assertSame(
            $inexistente->json('errors.email'),
            $claveMala->json('errors.email'),
            'Mensajes distintos convierten el login en un oraculo de correos registrados.',
        );
    }

    public function test_agendar_reduce_la_disponibilidad(): void
    {
        $this->actAsAdmin();
        $resource = $this->makeResource($this->business, start: '09:00:00', end: '12:00:00');
        $service = $this->makeService($this->business, 60, [$resource]);
        $fecha = $this->wednesday()->toDateString();

        $antes = $this->getJson("/api/v1/availability?service_id={$service->id}&date={$fecha}");
        $antes->assertOk();
        $slot = $antes->json('slots.0');

        $this->postJson('/api/v1/appointments', [
            'service_id' => $service->id,
            'resource_id' => $slot['resource_id'],
            'starts_at' => $slot['starts_at'],
            'client_name' => 'Laura Gomez',
            'client_phone' => '3001112233',
        ])->assertCreated()->assertJsonPath('client_phone', '573001112233');

        $despues = $this->getJson("/api/v1/availability?service_id={$service->id}&date={$fecha}");
        $this->assertLessThan(count($antes->json('slots')), count($despues->json('slots')));
    }

    public function test_una_hora_sin_desfase_se_interpreta_en_la_zona_del_negocio(): void
    {
        $this->actAsAdmin();
        $resource = $this->makeResource($this->business, start: '09:00:00', end: '18:00:00');
        $service = $this->makeService($this->business, 60, [$resource]);
        $fecha = $this->wednesday()->toDateString();

        // Es lo que manda el calendario al tocar una celda: hora local pura.
        // Bug real: parse() sin zona la leia como UTC y setTimezone la
        // convertia, asi que unas 11:00 aterrizaban a las 06:00.
        $this->postJson('/api/v1/appointments', [
            'service_id' => $service->id,
            'resource_id' => $resource->id,
            'starts_at' => "{$fecha} 11:00:00",
            'client_name' => 'Daniela',
        ])->assertCreated()->assertJsonPath('label', '11:00');

        $this->getJson("/api/v1/agenda?from={$fecha}")
            ->assertOk()
            ->assertJsonPath('days.0.resources.0.appointments.0.start', '11:00');
    }

    public function test_agendar_sobre_un_hueco_tomado_responde_409(): void
    {
        $this->actAsAdmin();
        $resource = $this->makeResource($this->business, start: '09:00:00', end: '12:00:00');
        $service = $this->makeService($this->business, 60, [$resource]);
        $fecha = $this->wednesday()->toDateString();

        $slot = $this->getJson("/api/v1/availability?service_id={$service->id}&date={$fecha}")->json('slots.0');

        $payload = [
            'service_id' => $service->id,
            'resource_id' => $slot['resource_id'],
            'starts_at' => $slot['starts_at'],
            'client_name' => 'Primera',
        ];

        $this->postJson('/api/v1/appointments', $payload)->assertCreated();

        // 409 y no 500: que otra persona llegara primero es un desenlace
        // normal, y el front lo distingue para recargar la disponibilidad.
        $this->postJson('/api/v1/appointments', $payload + ['client_name' => 'Segunda'])
            ->assertStatus(409);
    }

    public function test_el_listado_del_dia_devuelve_la_cita_con_su_servicio_y_profesional(): void
    {
        $this->actAsAdmin();
        $resource = $this->makeResource($this->business, 'Maria', '09:00:00', '12:00:00');
        $service = $this->makeService($this->business, 60, [$resource], name: 'Manicure clasico');
        $fecha = $this->wednesday()->toDateString();

        $slot = $this->getJson("/api/v1/availability?service_id={$service->id}&date={$fecha}")->json('slots.0');

        $this->postJson('/api/v1/appointments', [
            'service_id' => $service->id,
            'resource_id' => $slot['resource_id'],
            'starts_at' => $slot['starts_at'],
            'client_name' => 'Laura Gomez',
        ])->assertCreated();

        $this->getJson("/api/v1/appointments?date={$fecha}")
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.client_name', 'Laura Gomez')
            ->assertJsonPath('0.items.0.service_name', 'Manicure clasico')
            ->assertJsonPath('0.items.0.resource_name', 'Maria');
    }

    public function test_cancelar_devuelve_el_hueco(): void
    {
        $this->actAsAdmin();
        $resource = $this->makeResource($this->business, start: '09:00:00', end: '12:00:00');
        $service = $this->makeService($this->business, 60, [$resource]);
        $fecha = $this->wednesday()->toDateString();

        $slot = $this->getJson("/api/v1/availability?service_id={$service->id}&date={$fecha}")->json('slots.0');
        $cita = $this->postJson('/api/v1/appointments', [
            'service_id' => $service->id,
            'resource_id' => $slot['resource_id'],
            'starts_at' => $slot['starts_at'],
            'client_name' => 'Laura',
        ])->json('id');

        $antes = count($this->getJson("/api/v1/availability?service_id={$service->id}&date={$fecha}")->json('slots'));

        $this->postJson("/api/v1/appointments/{$cita}/cancel", ['reason' => 'No puede venir'])
            ->assertOk()
            ->assertJsonPath('status', 'cancelled');

        $despues = count($this->getJson("/api/v1/availability?service_id={$service->id}&date={$fecha}")->json('slots'));
        $this->assertGreaterThan($antes, $despues);
    }

    public function test_crear_cliente_normaliza_el_telefono_y_rechaza_duplicados(): void
    {
        $this->actAsAdmin();

        $this->postJson('/api/v1/clients', ['name' => 'Laura', 'last_name' => 'Gomez', 'phone' => '300 111 2233'])
            ->assertCreated()
            ->assertJsonPath('phone', '573001112233');

        // Mismo numero escrito distinto: debe reconocerse como el mismo.
        $this->postJson('/api/v1/clients', ['name' => 'Otra', 'phone' => '+57 300-111-2233'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('phone');
    }

    public function test_la_busqueda_de_clientes_no_vuelca_la_base_con_una_consulta_vacia(): void
    {
        $this->actAsAdmin();
        Client::create(['business_id' => $this->business->id, 'name' => 'Laura', 'phone' => '573001112233']);

        $this->getJson('/api/v1/clients/search?q=')->assertOk()->assertJsonCount(0);
        $this->getJson('/api/v1/clients/search?q=a')->assertOk()->assertJsonCount(0);
        $this->getJson('/api/v1/clients/search?q=lau')->assertOk()->assertJsonCount(1);
    }

    public function test_buscar_un_nombre_sin_digitos_no_devuelve_a_todo_el_mundo(): void
    {
        $this->actAsAdmin();
        Client::create(['business_id' => $this->business->id, 'name' => 'Laura', 'phone' => '573001112233']);
        Client::create(['business_id' => $this->business->id, 'name' => 'Carolina', 'phone' => '573004445566']);

        // Bug real, visto en la UI: al quitar los no-digitos del termino
        // quedaba una cadena vacia y la condicion de telefono se volvia
        // LIKE '%%', que matchea a todo cliente con telefono. Buscar
        // "Carolina" devolvia tambien a Laura.
        $this->getJson('/api/v1/clients/search?q=Carolina')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.name', 'Carolina');

        // Y buscar por telefono debe seguir funcionando.
        $this->getJson('/api/v1/clients/search?q=4445566')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.name', 'Carolina');
    }

    public function test_sin_autenticar_todo_responde_401_en_json(): void
    {
        foreach (['/api/v1/me', '/api/v1/services', '/api/v1/appointments?date=2026-09-16'] as $ruta) {
            $this->getJson($ruta)->assertStatus(401);
        }
    }

    public function test_un_miembro_del_equipo_sin_permiso_no_puede_cancelar(): void
    {
        $staff = User::create([
            'business_id' => $this->business->id,
            'name' => 'Maria',
            'email' => 'maria@prueba.test',
            'password' => Hash::make('password123'),
            'is_active' => true,
        ]);
        $staff->assignRole(PermissionCatalog::ROLE_STAFF);

        $resource = $this->makeResource($this->business, start: '09:00:00', end: '12:00:00');
        $service = $this->makeService($this->business, 60, [$resource]);
        $fecha = $this->wednesday()->toDateString();

        $this->actAsAdmin();
        $slot = $this->getJson("/api/v1/availability?service_id={$service->id}&date={$fecha}")->json('slots.0');
        $cita = $this->postJson('/api/v1/appointments', [
            'service_id' => $service->id,
            'resource_id' => $slot['resource_id'],
            'starts_at' => $slot['starts_at'],
            'client_name' => 'Laura',
        ])->json('id');

        // El rol staff agenda, pero no cancela: eso es de recepcion o admin.
        Sanctum::actingAs($staff);
        $this->postJson("/api/v1/appointments/{$cita}/cancel")->assertStatus(403);
    }
}
