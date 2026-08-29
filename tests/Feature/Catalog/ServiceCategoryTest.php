<?php

namespace Tests\Feature\Catalog;

use App\Models\Business;
use App\Models\Resource;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\User;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Scheduling\SchedulingScenario;
use Tests\TestCase;

/**
 * Categorías de servicios y edición masiva de comisiones.
 *
 * Existen para no entrar a 20 fichas cuando cambia el porcentaje de una
 * familia entera.
 */
class ServiceCategoryTest extends TestCase
{
    use RefreshDatabase, SchedulingScenario;

    private Business $business;

    private User $admin;

    private Resource $ana;

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

        $this->ana = Resource::create([
            'business_id' => $this->business->id, 'type' => Resource::TYPE_STAFF,
            'name' => 'Ana', 'is_active' => true,
        ]);

        Sanctum::actingAs($this->admin->fresh());
    }

    private function servicio(string $name, ?float $rate, ?int $categoryId = null): Service
    {
        $service = $this->makeService($this->business, 60, [$this->ana]);
        $service->update([
            'name' => $name,
            'price' => 100000,
            'commission_rate' => $rate,
            'service_category_id' => $categoryId,
        ]);

        return $service;
    }

    public function test_crear_una_categoria_con_su_comision(): void
    {
        $id = $this->postJson('/api/v1/service-categories', [
            'name' => 'Pestañas',
            'commission_rate' => 0.40,
        ])->assertCreated()->json('id');

        $fila = collect($this->getJson('/api/v1/service-categories')->assertOk()->json())
            ->firstWhere('id', $id);

        $this->assertSame('Pestañas', $fila['name']);
        $this->assertEqualsWithDelta(0.40, $fila['commission_rate'], 0.0001);
        $this->assertSame(0, $fila['services_count']);
    }

    public function test_cambiar_la_comision_de_la_categoria_alcanza_a_sus_servicios(): void
    {
        $categoria = ServiceCategory::create([
            'business_id' => $this->business->id, 'name' => 'Manicure', 'commission_rate' => 0.30,
        ]);

        // Sin porcentaje propio: heredan.
        $a = $this->servicio('Manicure clásico', null, $categoria->id);
        $b = $this->servicio('Manicure spa', null, $categoria->id);
        // Con porcentaje propio: no lo pierden.
        $c = $this->servicio('Manicure premium', 0.45, $categoria->id);

        $this->putJson("/api/v1/service-categories/{$categoria->id}", [
            'name' => 'Manicure',
            'commission_rate' => 0.38,
        ])->assertOk();

        $this->assertEqualsWithDelta(0.38, $a->fresh()->commissionRateFor($this->ana), 0.0001);
        $this->assertEqualsWithDelta(0.38, $b->fresh()->commissionRateFor($this->ana), 0.0001);
        // El que sí definió el suyo manda sobre su familia.
        $this->assertEqualsWithDelta(0.45, $c->fresh()->commissionRateFor($this->ana), 0.0001);
    }

    public function test_una_categoria_sin_comision_no_impone_nada(): void
    {
        $categoria = ServiceCategory::create([
            'business_id' => $this->business->id, 'name' => 'Varios', 'commission_rate' => null,
        ]);

        $servicio = $this->servicio('Algo', null, $categoria->id);

        $this->assertNull($servicio->fresh()->commissionRateFor($this->ana));
    }

    /*
    |--------------------------------------------------------------------------
    | Edición masiva
    |--------------------------------------------------------------------------
    */

    public function test_cambiar_la_comision_de_varios_servicios_de_una_vez(): void
    {
        $servicios = collect(range(1, 5))->map(fn (int $i) => $this->servicio("Servicio {$i}", 0.30));

        $respuesta = $this->putJson('/api/v1/services/bulk-commission', [
            'service_ids' => $servicios->pluck('id')->all(),
            'commission_rate' => 0.40,
        ])->assertOk();

        $this->assertSame(5, $respuesta->json('updated'));
        $this->assertSame('Se actualizaron 5 servicios.', $respuesta->json('message'));

        foreach ($servicios as $servicio) {
            $this->assertEqualsWithDelta(0.40, $servicio->fresh()->commissionRateFor($this->ana), 0.0001);
        }
    }

    public function test_poner_la_comision_en_nulo_los_devuelve_a_su_categoria(): void
    {
        $categoria = ServiceCategory::create([
            'business_id' => $this->business->id, 'name' => 'Manicure', 'commission_rate' => 0.35,
        ]);

        $servicio = $this->servicio('Manicure', 0.20, $categoria->id);

        // Nulo NO es 0: es "vuelve a heredar de tu familia".
        $this->putJson('/api/v1/services/bulk-commission', [
            'service_ids' => [$servicio->id],
            'commission_rate' => null,
        ])->assertOk();

        $this->assertEqualsWithDelta(0.35, $servicio->fresh()->commissionRateFor($this->ana), 0.0001);
    }

    public function test_se_pueden_mover_a_una_categoria_en_el_mismo_paso(): void
    {
        $categoria = ServiceCategory::create([
            'business_id' => $this->business->id, 'name' => 'Pestañas', 'commission_rate' => 0.40,
        ]);

        $servicios = collect(range(1, 3))->map(fn (int $i) => $this->servicio("Pestañas {$i}", null));

        $this->putJson('/api/v1/services/bulk-commission', [
            'service_ids' => $servicios->pluck('id')->all(),
            'commission_rate' => null,
            'service_category_id' => $categoria->id,
        ])->assertOk();

        foreach ($servicios as $servicio) {
            $this->assertEqualsWithDelta(0.40, $servicio->fresh()->commissionRateFor($this->ana), 0.0001);
        }
    }

    public function test_no_se_tocan_servicios_de_otro_negocio(): void
    {
        $otro = $this->makeBusiness();
        $ajeno = \Illuminate\Support\Facades\DB::table('services')->insertGetId([
            'business_id' => $otro->id, 'name' => 'Ajeno', 'slug' => 'ajeno-'.uniqid(),
            'duration_min' => 60, 'buffer_before_min' => 0, 'buffer_after_min' => 0,
            'price' => 50000, 'commission_rate' => 0.10, 'is_active' => true,
            'is_bookable_online' => true, 'sort_order' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $propio = $this->servicio('Propio', 0.30);

        $this->putJson('/api/v1/services/bulk-commission', [
            'service_ids' => [$propio->id, $ajeno],
            'commission_rate' => 0.40,
        ])->assertOk()->assertJsonPath('updated', 1);

        // El del otro spa quedó como estaba.
        $this->assertDatabaseHas('services', ['id' => $ajeno, 'commission_rate' => 0.1000]);
    }

    /*
    |--------------------------------------------------------------------------
    | Borrar
    |--------------------------------------------------------------------------
    */

    public function test_borrar_una_categoria_con_servicios_pide_confirmar(): void
    {
        $categoria = ServiceCategory::create([
            'business_id' => $this->business->id, 'name' => 'Manicure', 'commission_rate' => 0.35,
        ]);
        $this->servicio('Manicure', null, $categoria->id);

        // Sin avisar, esos servicios se quedarían sin el porcentaje que
        // heredaban y la siguiente liquidación saldría distinta sin que nadie
        // sepa por qué.
        $this->deleteJson("/api/v1/service-categories/{$categoria->id}")
            ->assertStatus(409)
            ->assertJsonPath('affected_services', 1);

        $this->deleteJson("/api/v1/service-categories/{$categoria->id}?confirm=1")->assertOk();

        // La categoría se va; el servicio sobrevive sin ella.
        $this->assertDatabaseMissing('service_categories', ['id' => $categoria->id]);
        $this->assertDatabaseCount('services', 1);
    }

    public function test_borrar_una_categoria_vacia_no_pregunta(): void
    {
        $categoria = ServiceCategory::create([
            'business_id' => $this->business->id, 'name' => 'Sin uso',
        ]);

        $this->deleteJson("/api/v1/service-categories/{$categoria->id}")->assertOk();
    }

    public function test_una_persona_del_equipo_no_toca_el_catalogo(): void
    {
        $staff = User::create([
            'business_id' => $this->business->id, 'name' => 'Ana',
            'email' => 'ana@prueba.test', 'password' => Hash::make('password123'), 'is_active' => true,
        ]);
        PermissionCatalog::applyRole($staff, PermissionCatalog::ROLE_STAFF);

        Sanctum::actingAs($staff->fresh());

        $this->postJson('/api/v1/service-categories', ['name' => 'Mía', 'commission_rate' => 1])
            ->assertForbidden();
        $this->putJson('/api/v1/services/bulk-commission', [
            'service_ids' => [1], 'commission_rate' => 1,
        ])->assertForbidden();
    }
}
