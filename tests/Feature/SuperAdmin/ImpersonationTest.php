<?php

namespace Tests\Feature\SuperAdmin;

use App\Http\Controllers\Api\V1\SuperAdmin\ImpersonateController;
use App\Models\Business;
use App\Models\LogAction;
use App\Models\User;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Scheduling\SchedulingScenario;
use Tests\TestCase;

/**
 * "Entrar como" un usuario de un negocio, para soporte.
 *
 * Lo que se defiende: que sirva de verdad (el token entra al negocio con los
 * permisos de esa persona, no con los del superadmin) y que deje huella -- si
 * lo que hace soporte se mezcla con lo que hizo el equipo, el dueño lee su
 * auditoria y culpa a la persona equivocada.
 */
class ImpersonationTest extends TestCase
{
    use RefreshDatabase, SchedulingScenario;

    private Business $business;

    private User $plataforma;

    private User $admin;

    private User $maria;

    protected function setUp(): void
    {
        parent::setUp();

        PermissionCatalog::sync();

        $this->business = $this->makeBusiness();

        $this->plataforma = User::create([
            'business_id' => null, 'name' => 'Plataforma', 'email' => 'p@nexolu.test',
            'password' => Hash::make('password123'), 'is_super_admin' => true, 'is_active' => true,
        ]);

        $this->admin = User::create([
            'business_id' => $this->business->id, 'name' => 'Ana',
            'email' => 'ana@prueba.test', 'password' => Hash::make('password123'), 'is_active' => true,
        ]);
        PermissionCatalog::applyRole($this->admin, PermissionCatalog::ROLE_ADMIN);

        $this->maria = User::create([
            'business_id' => $this->business->id, 'name' => 'Maria',
            'email' => 'maria@prueba.test', 'password' => Hash::make('password123'), 'is_active' => true,
        ]);
        PermissionCatalog::applyRole($this->maria, PermissionCatalog::ROLE_STAFF);
    }

    /**
     * Con un token de verdad y no con `Sanctum::actingAs`.
     *
     * `actingAs` fija el usuario del guard para TODO el test, asi que una
     * peticion posterior con otro bearer seguiria resolviendo al superadmin y
     * la prueba pasaria sin probar nada. Aca la gracia es justamente que el
     * token del negocio manda.
     */
    private function comoPlataforma(): self
    {
        return $this->conToken($this->plataforma->createToken('panel')->plainTextToken);
    }

    /**
     * Cambia de identidad entre peticiones del mismo test.
     *
     * Hacen falta las dos cosas: `flushHeaders()` porque `withHeader` se
     * acumula en el cliente de pruebas y el Authorization anterior seguiria
     * ahi, y `forgetGuards()` porque el guard cachea el usuario ya resuelto y
     * la segunda peticion respondia como el primero -- que es exactamente el
     * fallo que esta clase tiene que poder detectar.
     */
    private function conToken(string $token): self
    {
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();

        return $this->withHeader('Authorization', "Bearer {$token}");
    }

    private function impersonar(User $user): string
    {
        return $this->comoPlataforma()
            ->postJson("/api/v1/superadmin/impersonate/{$user->id}")
            ->assertOk()
            ->json('token');
    }

    public function test_entrar_como_devuelve_un_token_del_usuario(): void
    {
        $respuesta = $this->comoPlataforma()
            ->postJson("/api/v1/superadmin/impersonate/{$this->admin->id}")
            ->assertOk();

        $this->assertNotEmpty($respuesta->json('token'));
        $this->assertSame('ana@prueba.test', $respuesta->json('user.email'));
        // Con su negocio adentro: el front lo trata como un login normal.
        $this->assertSame($this->business->id, $respuesta->json('user.business.id'));
    }

    public function test_el_token_entra_al_negocio_con_los_permisos_de_esa_persona(): void
    {
        $token = $this->impersonar($this->maria);

        // Sin actingAs: se usa el token de verdad, como lo haria el front.
        $respuesta = $this->conToken($token)
            ->getJson('/api/v1/me')
            ->assertOk();

        $this->assertSame('maria@prueba.test', $respuesta->json('email'));

        // Y con SUS permisos, no con los del superadmin: una profesional no
        // entra a la nomina. Si soporte viera todo, no estaria viendo lo que
        // ve quien reporto el problema.
        $this->conToken($token)
            ->getJson('/api/v1/payroll/pending')
            ->assertForbidden();
    }

    public function test_el_superadmin_conserva_su_propio_token(): void
    {
        $tokenImpersonado = $this->impersonar($this->admin);

        // El token del superadmin sigue vivo: volver no debe pedir contraseña.
        $propio = $this->plataforma->createToken('propio')->plainTextToken;

        $this->conToken($propio)
            ->getJson('/api/v1/superadmin/dashboard')
            ->assertOk();

        // Y el de impersonacion sigue siendo del negocio, no de plataforma.
        $this->conToken($tokenImpersonado)
            ->getJson('/api/v1/superadmin/dashboard')
            ->assertForbidden();
    }

    public function test_salir_es_un_logout_que_revoca_solo_ese_token(): void
    {
        $token = $this->impersonar($this->maria);

        $this->conToken($token)
            ->postJson('/api/v1/logout')
            ->assertOk();

        // El token de impersonacion queda muerto...
        $this->conToken($token)
            ->getJson('/api/v1/me')
            ->assertUnauthorized();

        // ...y Maria no perdio su sesion real por eso.
        $propio = $this->maria->createToken('celular')->plainTextToken;
        $this->conToken($propio)
            ->getJson('/api/v1/me')
            ->assertOk();
    }

    public function test_el_token_lleva_marcado_quien_impersona(): void
    {
        $this->impersonar($this->maria);

        // Es lo que permite separar despues lo que hizo soporte de lo que
        // hizo el equipo del negocio.
        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_id' => $this->maria->id,
            'name' => ImpersonateController::TOKEN_NAME_PREFIX.$this->plataforma->id,
        ]);
    }

    public function test_queda_registrado_en_la_auditoria(): void
    {
        $this->impersonar($this->maria);

        $log = LogAction::where('action', 'superadmin.impersonation.started')->latest('id')->first();

        $this->assertNotNull($log);
        $this->assertSame($this->business->id, $log->business_id);
        $this->assertSame($this->maria->id, $log->payload['impersonated_user_id']);
        // Marcado a mano: esta peticion viene con el token REAL del
        // superadmin, asi que el marcador automatico no lo detecta.
        $this->assertSame($this->plataforma->id, $log->payload['impersonated_by_superadmin_id']);
    }

    /*
    |--------------------------------------------------------------------------
    | Guardas
    |--------------------------------------------------------------------------
    */

    public function test_no_se_entra_como_otro_usuario_de_plataforma(): void
    {
        $otro = User::create([
            'business_id' => null, 'name' => 'Otro', 'email' => 'otro@nexolu.test',
            'password' => Hash::make('password123'), 'is_super_admin' => true, 'is_active' => true,
        ]);

        Sanctum::actingAs($this->plataforma);

        $this->postJson("/api/v1/superadmin/impersonate/{$otro->id}")->assertStatus(422);
    }

    public function test_no_se_entra_como_alguien_desactivado(): void
    {
        $this->maria->update(['is_active' => false]);

        Sanctum::actingAs($this->plataforma);

        // Mostraria una pantalla que esa persona no puede ver, y llevaria a
        // "arreglar" un problema que no existe.
        $this->postJson("/api/v1/superadmin/impersonate/{$this->maria->id}")
            ->assertStatus(422);
    }

    public function test_el_admin_de_un_negocio_no_puede_impersonar(): void
    {
        Sanctum::actingAs($this->admin->fresh());

        // El caso grave: si el dueño de un spa pudiera, entraria como usuarios
        // de otro spa.
        $this->postJson("/api/v1/superadmin/impersonate/{$this->maria->id}")
            ->assertForbidden();
    }

    public function test_sin_autenticar_responde_401(): void
    {
        $this->postJson("/api/v1/superadmin/impersonate/{$this->maria->id}")
            ->assertUnauthorized();
    }
}
