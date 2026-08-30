<?php

namespace Tests\Feature\SuperAdmin;

use App\Models\Business;
use App\Models\Resource;
use App\Models\User;
use App\Support\BusinessFeaturePresets;
use App\Support\BusinessPlanLimits;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Scheduling\SchedulingScenario;
use Tests\TestCase;

/**
 * Los topes por plan.
 *
 * Hasta ahora los tres planes se diferenciaban sólo en qué módulos veían, y
 * nada impedía que un negocio del plan más barato cargara treinta personas en
 * la agenda.
 *
 * Lo que se defiende acá no es el cobro sino lo contrario: que un tope
 * comercial no le rompa el día de trabajo a nadie.
 */
class PlanLimitsTest extends TestCase
{
    use RefreshDatabase, SchedulingScenario;

    private Business $business;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        PermissionCatalog::sync();

        $this->business = $this->makeBusiness();
        $this->business->update(['subscription_plan' => BusinessFeaturePresets::PLAN_BASICO]);

        $this->admin = User::create([
            'business_id' => $this->business->id, 'name' => 'Dueña',
            'email' => 'admin@prueba.test', 'password' => Hash::make('password123'), 'is_active' => true,
        ]);
        PermissionCatalog::applyRole($this->admin, PermissionCatalog::ROLE_ADMIN);
        Sanctum::actingAs($this->admin->fresh());
    }

    private function agregarPersona(string $nombre): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/v1/resources', [
            'type' => Resource::TYPE_STAFF,
            'name' => $nombre,
        ]);
    }

    public function test_el_plan_basico_topa_en_tres_personas(): void
    {
        $this->agregarPersona('Uno')->assertCreated();
        $this->agregarPersona('Dos')->assertCreated();
        $this->agregarPersona('Tres')->assertCreated();

        $respuesta = $this->agregarPersona('Cuatro')->assertStatus(422);

        // El mensaje dice el número y qué hacer: un "no se pudo" pelado manda
        // a soporte a quien podía resolverlo solo.
        $this->assertStringContainsString('3 personas', $respuesta->json('message'));
        $this->assertStringContainsString('desactivar', $respuesta->json('message'));
        $this->assertSame(3, $respuesta->json('limit.max'));
    }

    public function test_desactivar_a_alguien_libera_el_cupo(): void
    {
        $primera = $this->agregarPersona('Uno')->assertCreated()->json('id');
        $this->agregarPersona('Dos')->assertCreated();
        $this->agregarPersona('Tres')->assertCreated();
        $this->agregarPersona('Cuatro')->assertStatus(422);

        $this->postJson("/api/v1/resources/{$primera}", ['is_active' => false])->assertOk();

        // Si contáramos los inactivos, un local que rota personal chocaría
        // contra un tope que no puede explicarse.
        $this->agregarPersona('Cuatro')->assertCreated();
    }

    public function test_una_silla_no_gasta_cupo_de_plan(): void
    {
        // El plan se cobra por gente que atiende, no por muebles.
        $this->agregarPersona('Uno')->assertCreated();
        $this->agregarPersona('Dos')->assertCreated();
        $this->agregarPersona('Tres')->assertCreated();

        $this->postJson('/api/v1/resources', [
            'type' => Resource::TYPE_ROOM,
            'name' => 'Cabina 1',
        ])->assertCreated();
    }

    public function test_un_negocio_por_encima_del_tope_sigue_trabajando(): void
    {
        /*
         * Bajó de plan con cinco personas cargadas. No puede agregar más, pero
         * la agenda no se le rompe: bloquear a un negocio por una decisión
         * comercial sería romperle el día para cobrarle.
         */
        $this->business->update(['subscription_plan' => BusinessFeaturePresets::PLAN_FULL]);
        foreach (['Uno', 'Dos', 'Tres', 'Cuatro', 'Cinco'] as $nombre) {
            $this->agregarPersona($nombre)->assertCreated();
        }

        $this->business->update(['subscription_plan' => BusinessFeaturePresets::PLAN_BASICO]);
        // `Sanctum::actingAs` conserva la MISMA instancia de usuario en toda la
        // prueba, con su relación `business` ya cargada: sin volver a
        // autenticar, el controlador seguiría leyendo el plan viejo. En una
        // petición real el usuario se carga de cero cada vez.
        Sanctum::actingAs($this->admin->fresh());

        $this->getJson('/api/v1/resources')->assertOk()->assertJsonCount(5);
        $this->agregarPersona('Seis')->assertStatus(422);

        // Y el disponible nunca sale negativo.
        $uso = $this->business->fresh()->planUsage()[BusinessPlanLimits::MAX_RESOURCES];
        $this->assertSame(5, $uso['used']);
        $this->assertSame(0, $uso['remaining']);
    }

    public function test_el_plan_full_no_topa(): void
    {
        $this->business->update(['subscription_plan' => BusinessFeaturePresets::PLAN_FULL]);

        foreach (range(1, 12) as $i) {
            $this->agregarPersona("Persona {$i}")->assertCreated();
        }
    }

    public function test_la_pantalla_sabe_cuanto_le_queda_antes_de_intentar(): void
    {
        $this->agregarPersona('Uno')->assertCreated();

        $uso = $this->getJson('/api/v1/me')->assertOk()->json('business.plan_usage');

        $this->assertSame(3, $uso[BusinessPlanLimits::MAX_RESOURCES]['limit']);
        $this->assertSame(1, $uso[BusinessPlanLimits::MAX_RESOURCES]['used']);
        $this->assertSame(2, $uso[BusinessPlanLimits::MAX_RESOURCES]['remaining']);
    }

    /*
    |--------------------------------------------------------------------------
    | Excepciones desde superadmin
    |--------------------------------------------------------------------------
    */

    public function test_se_le_puede_ampliar_el_tope_a_un_negocio_sin_cambiarle_el_plan(): void
    {
        $this->agregarPersona('Uno')->assertCreated();
        $this->agregarPersona('Dos')->assertCreated();
        $this->agregarPersona('Tres')->assertCreated();
        $this->agregarPersona('Cuatro')->assertStatus(422);

        $this->conSuperAdmin();
        $this->patchJson("/api/v1/superadmin/businesses/{$this->business->id}", [
            'plan_limits' => [BusinessPlanLimits::MAX_RESOURCES => 5],
        ])->assertOk();

        // Sigue en el plan básico: la excepción es del negocio, no del plan.
        $this->assertSame(
            BusinessFeaturePresets::PLAN_BASICO,
            $this->business->fresh()->subscription_plan,
        );

        Sanctum::actingAs($this->admin->fresh());
        $this->agregarPersona('Cuatro')->assertCreated();
    }

    public function test_un_tope_inventado_no_se_guarda(): void
    {
        // Quedaría en la base para siempre sin que nada lo lea, y quien lo
        // escribió creería que aplicó.
        $this->conSuperAdmin();

        $this->patchJson("/api/v1/superadmin/businesses/{$this->business->id}", [
            'plan_limits' => ['max_unicornios' => 7],
        ])->assertOk();

        $this->assertSame([], $this->business->fresh()->plan_limits);
    }

    private function conSuperAdmin(): void
    {
        $super = User::create([
            'business_id' => null, 'name' => 'Plataforma',
            'email' => 'plataforma@prueba.test', 'password' => Hash::make('password123'),
            'is_super_admin' => true, 'is_active' => true,
        ]);

        // `Sanctum::actingAs` fija el guard para TODA la prueba: sin soltarlo,
        // la petición siguiente seguiría resolviendo a la dueña del negocio.
        $this->flushHeaders();
        app('auth')->forgetGuards();
        Sanctum::actingAs($super->fresh());
    }
}
