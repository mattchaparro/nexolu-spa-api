<?php

namespace Tests\Feature\Admin;

use App\Models\Business;
use App\Models\Resource;
use App\Models\User;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Scheduling\SchedulingScenario;
use Tests\TestCase;

/**
 * El administrador ve el correo de su equipo y le cambia la contraseña.
 *
 * Antes el correo y la contraseña solo se escribían al crear a la persona:
 * una empleada que olvidaba la suya no tenía arreglo desde el sistema.
 */
class StaffLoginTest extends TestCase
{
    use RefreshDatabase, SchedulingScenario;

    private Business $business;

    private User $admin;

    private Resource $maria;

    private User $mariaUser;

    protected function setUp(): void
    {
        parent::setUp();

        PermissionCatalog::sync();
        $this->business = $this->makeBusiness();
        $this->admin = $this->usuario('admin@prueba.test', PermissionCatalog::ROLE_ADMIN);
        $this->mariaUser = $this->usuario('maria@prueba.test', PermissionCatalog::ROLE_STAFF);
        $this->maria = $this->makeResource($this->business, 'Maria');
        $this->maria->update(['user_id' => $this->mariaUser->id]);

        Sanctum::actingAs($this->admin);
    }

    private function usuario(string $email, string $rol): User
    {
        $user = User::create([
            'business_id' => $this->business->id,
            'name' => 'Persona',
            'email' => $email,
            'password' => Hash::make('password123'),
            'is_active' => true,
        ]);
        PermissionCatalog::applyRole($user, $rol);

        return $user;
    }

    public function test_el_admin_ve_el_correo_con_el_que_entra_cada_una(): void
    {
        $fila = collect($this->getJson('/api/v1/resources')->assertOk()->json())
            ->firstWhere('id', $this->maria->id);

        $this->assertSame('maria@prueba.test', $fila['login_email']);
    }

    public function test_quien_no_administra_al_equipo_no_ve_los_correos(): void
    {
        Sanctum::actingAs($this->mariaUser->fresh());

        $fila = collect($this->getJson('/api/v1/resources')->assertOk()->json())
            ->firstWhere('id', $this->maria->id);

        $this->assertArrayNotHasKey('login_email', $fila);
    }

    public function test_cambiar_la_contrasena_y_el_correo(): void
    {
        $this->putJson("/api/v1/resources/{$this->maria->id}/login", [
            'email' => 'maria.nueva@prueba.test',
            'password' => 'nueva-clave-123',
        ])->assertOk()->assertJsonPath('login_email', 'maria.nueva@prueba.test');

        $usuario = $this->mariaUser->fresh();
        $this->assertSame('maria.nueva@prueba.test', $usuario->email);
        $this->assertTrue(Hash::check('nueva-clave-123', $usuario->password));
    }

    public function test_sin_contrasena_solo_cambia_el_correo(): void
    {
        $this->putJson("/api/v1/resources/{$this->maria->id}/login", ['email' => 'otro@prueba.test'])->assertOk();

        $this->assertTrue(Hash::check('password123', $this->mariaUser->fresh()->password));
    }

    public function test_cambiar_la_contrasena_cierra_sus_sesiones(): void
    {
        $this->mariaUser->createToken('celular');
        $this->assertSame(1, $this->mariaUser->tokens()->count());

        $this->putJson("/api/v1/resources/{$this->maria->id}/login", [
            'email' => 'maria@prueba.test',
            'password' => 'nueva-clave-123',
        ])->assertOk();

        $this->assertSame(0, $this->mariaUser->fresh()->tokens()->count());
    }

    public function test_el_correo_no_puede_ser_el_de_otra_persona(): void
    {
        $this->putJson("/api/v1/resources/{$this->maria->id}/login", ['email' => 'admin@prueba.test'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');
    }

    public function test_la_contrasena_necesita_ocho_caracteres(): void
    {
        $this->putJson("/api/v1/resources/{$this->maria->id}/login", [
            'email' => 'maria@prueba.test',
            'password' => 'corta',
        ])->assertStatus(422)->assertJsonValidationErrors('password');
    }

    public function test_darle_acceso_a_quien_no_tenia(): void
    {
        $lucia = $this->makeResource($this->business, 'Lucia');

        // Sin contraseña no se crea una cuenta.
        $this->putJson("/api/v1/resources/{$lucia->id}/login", ['email' => 'lucia@prueba.test'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');

        $this->putJson("/api/v1/resources/{$lucia->id}/login", [
            'email' => 'lucia@prueba.test',
            'password' => 'clave-segura-1',
            'role' => PermissionCatalog::ROLE_STAFF,
        ])->assertOk();

        $cuenta = User::where('email', 'lucia@prueba.test')->sole();
        $this->assertSame($cuenta->id, $lucia->fresh()->user_id);
        $this->assertTrue($cuenta->hasRole(PermissionCatalog::ROLE_STAFF));
    }

    public function test_un_admin_no_toma_la_cuenta_de_la_duena(): void
    {
        $duena = $this->usuario('duena@prueba.test', PermissionCatalog::ROLE_ADMIN);
        $duena->forceFill(['is_owner' => true])->save();
        $recurso = $this->makeResource($this->business, 'Duena');
        $recurso->update(['user_id' => $duena->id]);

        $this->putJson("/api/v1/resources/{$recurso->id}/login", [
            'email' => 'duena@prueba.test',
            'password' => 'otra-clave-123',
        ])->assertForbidden();

        $this->assertTrue(Hash::check('password123', $duena->fresh()->password));
    }

    public function test_quien_no_gestiona_recursos_no_puede(): void
    {
        Sanctum::actingAs($this->mariaUser->fresh());

        $this->putJson("/api/v1/resources/{$this->maria->id}/login", [
            'email' => 'maria@prueba.test',
            'password' => 'nueva-clave-123',
        ])->assertForbidden();
    }

    public function test_una_cabina_no_tiene_acceso(): void
    {
        $cabina = Resource::create([
            'business_id' => $this->business->id,
            'type' => Resource::TYPE_ROOM,
            'name' => 'Cabina 1',
            'is_active' => true,
        ]);

        $this->putJson("/api/v1/resources/{$cabina->id}/login", [
            'email' => 'cabina@prueba.test',
            'password' => 'clave-segura-1',
        ])->assertStatus(422);
    }
}
