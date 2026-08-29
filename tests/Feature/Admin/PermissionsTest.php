<?php

namespace Tests\Feature\Admin;

use App\Models\Business;
use App\Models\Client;
use App\Models\Resource;
use App\Models\ResourceSchedule;
use App\Models\Service;
use App\Models\User;
use App\Services\Scheduling\BookingService;
use App\Support\PermissionCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Scheduling\SchedulingScenario;
use Tests\TestCase;

/**
 * Los permisos del equipo.
 *
 * La regla del negocio que estas pruebas protegen: una profesional atiende a
 * quien le toca, pero la base de clientes con telefonos es del negocio. Si el
 * equipo se la lleva, atiende por fuera. Por eso el rol de profesional arranca
 * sin acceso a clientes y sin poder agendar, y por eso el telefono no viaja en
 * la agenda de quien no tiene permiso.
 */
class PermissionsTest extends TestCase
{
    use RefreshDatabase, SchedulingScenario;

    private Business $business;

    private User $admin;

    private User $manicurista;

    private Resource $maria;

    private Service $service;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        PermissionCatalog::sync();

        $this->business = $this->makeBusiness();

        $this->admin = $this->makeUser('Ana', 'ana@prueba.test', PermissionCatalog::ROLE_ADMIN);
        $this->manicurista = $this->makeUser('Maria', 'maria@prueba.test', PermissionCatalog::ROLE_STAFF);

        $this->maria = Resource::create([
            'business_id' => $this->business->id,
            'type' => Resource::TYPE_STAFF,
            'user_id' => $this->manicurista->id,
            'name' => 'Maria',
            'is_active' => true,
        ]);

        foreach (range(1, 7) as $weekday) {
            ResourceSchedule::create([
                'business_id' => $this->business->id, 'resource_id' => $this->maria->id,
                'weekday' => $weekday, 'start_time' => '00:00:00', 'end_time' => '23:59:00',
                'effective_from' => '2020-01-01',
            ]);
        }

        $this->service = $this->makeService($this->business, 45, [$this->maria]);

        $this->client = Client::create([
            'business_id' => $this->business->id,
            'name' => 'Carolina',
            'phone' => '+573001112233',
        ]);
    }

    private function makeUser(string $name, string $email, string $role): User
    {
        $user = User::create([
            'business_id' => $this->business->id,
            'name' => $name,
            'email' => $email,
            'password' => Hash::make('password123'),
            'is_active' => true,
        ]);

        PermissionCatalog::applyRole($user, $role);

        return $user->fresh();
    }

    private function bookForClient(?Resource $resource = null): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/v1/appointments', [
            'service_id' => $this->service->id,
            'resource_id' => ($resource ?? $this->maria)->id,
            'starts_at' => $this->wednesday()->format('Y-m-d').' 10:00:00',
            'client_id' => $this->client->id,
        ])->assertCreated();
    }

    /*
    |--------------------------------------------------------------------------
    | Lo que una profesional NO puede hacer por defecto
    |--------------------------------------------------------------------------
    */

    public function test_una_profesional_ve_su_cita_pero_no_el_telefono_del_cliente(): void
    {
        $this->bookForClient();

        Sanctum::actingAs($this->manicurista->fresh());

        $cita = $this->getJson('/api/v1/appointments?date='.$this->wednesday()->format('Y-m-d'))
            ->assertOk()
            ->json('0');

        // El nombre alcanza para saber a quien atiende.
        $this->assertSame('Carolina', $cita['client_name']);
        // El telefono es lo que permite llevarse la clientela: no viaja.
        $this->assertArrayNotHasKey('client_phone', $cita);
    }

    public function test_una_profesional_solo_ve_su_propia_agenda(): void
    {
        $otra = Resource::create([
            'business_id' => $this->business->id, 'type' => Resource::TYPE_STAFF,
            'name' => 'Lucia', 'is_active' => true,
        ]);
        ResourceSchedule::create([
            'business_id' => $this->business->id, 'resource_id' => $otra->id,
            'weekday' => $this->wednesday()->isoWeekday(),
            'start_time' => '00:00:00', 'end_time' => '23:59:00', 'effective_from' => '2020-01-01',
        ]);
        $this->service->resources()->attach($otra->id);

        $this->bookForClient($otra);

        Sanctum::actingAs($this->manicurista->fresh());

        // La cita es de Lucia: sin `citas.ver_todas` no aparece. Ver la
        // agenda del negocio entero revela toda la clientela.
        $this->getJson('/api/v1/appointments?date='.$this->wednesday()->format('Y-m-d'))
            ->assertOk()->assertJsonCount(0);

        $columnas = $this->getJson('/api/v1/agenda?from='.$this->wednesday()->format('Y-m-d'))
            ->assertOk()->json('days.0.resources');

        $this->assertCount(1, $columnas);
        $this->assertSame('Maria', $columnas[0]['name']);
    }

    public function test_recepcion_si_ve_la_agenda_completa(): void
    {
        $recepcion = $this->makeUser('Sofia', 'sofia@prueba.test', PermissionCatalog::ROLE_RECEPTION);

        $this->bookForClient();

        Sanctum::actingAs($recepcion->fresh());

        $cita = $this->getJson('/api/v1/appointments?date='.$this->wednesday()->format('Y-m-d'))
            ->assertOk()->json('0');

        // Su trabajo es llamar para confirmar y reagendar: necesita el
        // telefono y la agenda de todos.
        $this->assertSame('+573001112233', $cita['client_phone']);
    }

    public function test_una_profesional_no_puede_agendar(): void
    {
        Sanctum::actingAs($this->manicurista->fresh());

        $this->postJson('/api/v1/appointments', [
            'service_id' => $this->service->id,
            'resource_id' => $this->maria->id,
            'starts_at' => $this->wednesday()->format('Y-m-d').' 14:00:00',
            'client_name' => 'Quien sea',
        ])->assertForbidden();
    }

    public function test_una_profesional_no_puede_listar_ni_abrir_clientes(): void
    {
        Sanctum::actingAs($this->manicurista->fresh());

        $this->getJson('/api/v1/clients/search?q=Caro')->assertForbidden();
        $this->getJson('/api/v1/clients/'.$this->client->id)->assertForbidden();
    }

    public function test_una_profesional_no_entra_a_la_pantalla_de_permisos(): void
    {
        Sanctum::actingAs($this->manicurista->fresh());

        $this->getJson('/api/v1/permissions')->assertForbidden();
        $this->putJson('/api/v1/permissions/'.$this->admin->id, ['permissions' => []])->assertForbidden();
    }

    /*
    |--------------------------------------------------------------------------
    | Lo que si puede: registrar lo que ya hizo
    |--------------------------------------------------------------------------
    */

    public function test_registrar_un_servicio_sin_cita_es_un_permiso_distinto_de_agendar(): void
    {
        $this->assertTrue($this->manicurista->hasBusinessPermission('servicios.registrar'));
        $this->assertFalse($this->manicurista->hasBusinessPermission('citas.crear'));

        Sanctum::actingAs($this->manicurista->fresh());

        $this->postJson('/api/v1/walk-in', [
            'service_id' => $this->service->id,
            'client_name' => 'Señora que llegó',
        ])->assertCreated();
    }

    public function test_sin_servicios_registrar_tampoco_puede_registrar(): void
    {
        $this->manicurista->syncPermissions(['citas.ver']);

        Sanctum::actingAs($this->manicurista->fresh());

        $this->postJson('/api/v1/walk-in', [
            'service_id' => $this->service->id,
            'client_name' => 'Señora que llegó',
        ])->assertForbidden();
    }

    /*
    |--------------------------------------------------------------------------
    | La pantalla del administrador
    |--------------------------------------------------------------------------
    */

    public function test_el_admin_ve_al_equipo_y_el_catalogo_marcado(): void
    {
        Sanctum::actingAs($this->admin);

        $data = $this->getJson('/api/v1/permissions')->assertOk()->json();

        $maria = collect($data['users'])->firstWhere('email', 'maria@prueba.test');
        $ana = collect($data['users'])->firstWhere('email', 'ana@prueba.test');

        $this->assertSame(PermissionCatalog::ROLE_STAFF, $maria['role']);
        $this->assertFalse($maria['is_admin']);
        $this->assertSame('Maria', $maria['resource_name']);
        $this->assertContains('servicios.registrar', $maria['permissions']);
        $this->assertNotContains('clientes.ver', $maria['permissions']);

        // El admin se ve a si mismo, marcado: la pantalla explica por que no
        // se le pueden editar los permisos en vez de esconderlo.
        $this->assertTrue($ana['is_admin']);
        $this->assertTrue($ana['is_self']);

        // El acceso a clientes sale marcado aparte para que la decision no
        // parezca una casilla mas entre veinte.
        $clientes = collect($data['catalog'])->firstWhere('key', 'clientes');
        $this->assertTrue(collect($clientes['permissions'])->firstWhere('name', 'clientes.ver')['sensitive']);
        $this->assertFalse(collect($data['catalog'])->firstWhere('key', 'agenda')['permissions'][0]['sensitive']);
    }

    public function test_el_admin_le_abre_el_acceso_a_clientes_a_una_profesional(): void
    {
        $this->bookForClient();

        Sanctum::actingAs($this->admin);

        $this->putJson('/api/v1/permissions/'.$this->manicurista->id, [
            'permissions' => ['citas.ver', 'servicios.registrar', 'caja.cobrar', 'clientes.ver'],
        ])->assertOk()->assertJsonPath('role', PermissionCatalog::ROLE_STAFF);

        Sanctum::actingAs($this->manicurista->fresh());

        $cita = $this->getJson('/api/v1/appointments?date='.$this->wednesday()->format('Y-m-d'))
            ->assertOk()->json('0');

        $this->assertSame('+573001112233', $cita['client_phone']);
    }

    public function test_quitar_un_permiso_lo_revoca_de_verdad(): void
    {
        // La razon de que los roles de equipo no lleven permisos colgados:
        // con spatie, un permiso heredado del rol no se le puede quitar a UNA
        // persona. La pantalla mostraria la casilla desmarcada y el acceso
        // seguiria abierto.
        Sanctum::actingAs($this->admin);

        $this->putJson('/api/v1/permissions/'.$this->manicurista->id, [
            'permissions' => ['citas.ver'],
        ])->assertOk();

        $this->assertFalse($this->manicurista->fresh()->hasBusinessPermission('caja.cobrar'));
    }

    public function test_cambiar_de_rol_a_recepcion(): void
    {
        Sanctum::actingAs($this->admin);

        $defaults = PermissionCatalog::defaultsForRole(PermissionCatalog::ROLE_RECEPTION);

        $this->putJson('/api/v1/permissions/'.$this->manicurista->id, [
            'role' => PermissionCatalog::ROLE_RECEPTION,
            'permissions' => $defaults,
        ])->assertOk()->assertJsonPath('role', PermissionCatalog::ROLE_RECEPTION);

        // Recepcion si necesita los datos de contacto: su trabajo es llamar
        // para confirmar y reagendar.
        $this->assertTrue($this->manicurista->fresh()->hasBusinessPermission('clientes.ver'));
        $this->assertTrue($this->manicurista->fresh()->hasBusinessPermission('citas.crear'));
    }

    /*
    |--------------------------------------------------------------------------
    | Guardas
    |--------------------------------------------------------------------------
    */

    public function test_nadie_se_edita_sus_propios_permisos(): void
    {
        Sanctum::actingAs($this->admin);

        $this->putJson('/api/v1/permissions/'.$this->admin->id, ['permissions' => []])
            ->assertStatus(422);
    }

    public function test_no_se_le_recortan_permisos_a_otro_admin(): void
    {
        $otro = $this->makeUser('Beto', 'beto@prueba.test', PermissionCatalog::ROLE_ADMIN);

        Sanctum::actingAs($this->admin);

        // Quitarle permisos a un admin sin quitarle el rol no haria nada
        // -- los tiene por el rol -- y dejaria creyendo que si.
        $this->putJson('/api/v1/permissions/'.$otro->id, ['permissions' => []])
            ->assertStatus(422);
    }

    public function test_no_se_tocan_los_permisos_de_otro_negocio(): void
    {
        $otroNegocio = $this->makeBusiness();

        $ajeno = User::create([
            'business_id' => $otroNegocio->id, 'name' => 'Ajena',
            'email' => 'ajena@otro.test', 'password' => Hash::make('password123'), 'is_active' => true,
        ]);
        PermissionCatalog::applyRole($ajeno, PermissionCatalog::ROLE_STAFF);

        Sanctum::actingAs($this->admin);

        $this->putJson('/api/v1/permissions/'.$ajeno->id, ['permissions' => ['citas.ver']])
            ->assertNotFound();

        $this->assertNotContains(
            'ajena@otro.test',
            array_column($this->getJson('/api/v1/permissions')->json('users'), 'email'),
        );
    }

    public function test_un_permiso_inventado_se_rechaza(): void
    {
        Sanctum::actingAs($this->admin);

        $this->putJson('/api/v1/permissions/'.$this->manicurista->id, [
            'permissions' => ['citas.ver', 'negocio.destruir'],
        ])->assertStatus(422);
    }
}
