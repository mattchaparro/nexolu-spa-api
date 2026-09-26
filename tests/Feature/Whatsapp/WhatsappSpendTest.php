<?php

namespace Tests\Feature\Whatsapp;

use App\Models\Business;
use App\Models\User;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Scheduling\SchedulingScenario;
use Tests\TestCase;

/** El gasto de WhatsApp lo da Meta vía Connect, y solo lo ve quien administra. */
class WhatsappSpendTest extends TestCase
{
    use RefreshDatabase, SchedulingScenario;

    private Business $business;

    protected function setUp(): void
    {
        parent::setUp();
        PermissionCatalog::sync();
        config([
            'services.comms_core.base_url' => 'https://connect.test',
            'services.comms_core.api_key' => 'llave-del-spa',
        ]);
        $this->business = $this->makeBusiness();
    }

    private function persona(string $rol): User
    {
        $user = User::create([
            'business_id' => $this->business->id, 'name' => $rol, 'email' => $rol.'@prueba.test',
            'password' => Hash::make('password123'), 'is_active' => true,
        ]);
        PermissionCatalog::applyRole($user, $rol);

        return $user->fresh();
    }

    public function test_el_admin_ve_el_gasto_del_mes_de_su_negocio(): void
    {
        Http::fake(['connect.test/v1/usage/whatsapp-spend*' => Http::response([
            'month' => '2026-09', 'currency' => 'COP', 'total' => 7577.18, 'total_usd' => 1.89,
            'by_category' => [['category' => 'MARKETING', 'type' => 'REGULAR', 'volume' => 164, 'cost' => 7547.72]],
            'daily' => [],
        ])]);
        Sanctum::actingAs($this->persona(PermissionCatalog::ROLE_ADMIN));

        $this->getJson('/api/v1/whatsapp/gasto?mes=2026-09')
            ->assertOk()
            ->assertJsonPath('total', 7577.18)
            ->assertJsonPath('currency', 'COP');

        Http::assertSent(fn ($r) => str_contains($r->url(), 'month=2026-09')
            && str_contains($r->url(), 'business_id='.$this->business->id));
    }

    public function test_recepcion_no_ve_el_gasto(): void
    {
        Http::fake();
        Sanctum::actingAs($this->persona(PermissionCatalog::ROLE_RECEPTION));

        $this->getJson('/api/v1/whatsapp/gasto')->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_si_meta_falla_se_dice(): void
    {
        Http::fake(['connect.test/*' => Http::response(['detail' => 'sin permiso'], 502)]);
        Sanctum::actingAs($this->persona(PermissionCatalog::ROLE_ADMIN));

        $this->getJson('/api/v1/whatsapp/gasto')->assertStatus(503);
    }
}
