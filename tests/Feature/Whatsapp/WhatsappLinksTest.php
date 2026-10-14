<?php

namespace Tests\Feature\Whatsapp;

use App\Models\Business;
use App\Models\User;
use App\Models\WhatsappLink;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Scheduling\SchedulingScenario;
use Tests\TestCase;

/**
 * Enlaces wa.me con mensaje propio, para historias de Instagram y anuncios.
 */
class WhatsappLinksTest extends TestCase
{
    use RefreshDatabase, SchedulingScenario;

    private Business $business;

    protected function setUp(): void
    {
        parent::setUp();

        PermissionCatalog::sync();
        $this->business = $this->makeBusiness();
        $this->actuarComo($this->business, PermissionCatalog::ROLE_ADMIN);
    }

    private function actuarComo(Business $business, string $rol): User
    {
        $user = User::create([
            'business_id' => $business->id,
            'name' => 'Persona',
            'email' => uniqid().'@prueba.test',
            'password' => Hash::make('password123'),
            'is_active' => true,
        ]);
        PermissionCatalog::applyRole($user, $rol);
        Sanctum::actingAs($user->fresh());

        return $user;
    }

    public function test_guarda_lista_edita_y_borra(): void
    {
        $id = $this->postJson('/api/v1/whatsapp/links', [
            'name' => 'Historia del sábado',
            'message' => 'Hola, quiero el 2x1 del sábado 💅',
        ])->assertCreated()->json('id');

        $this->getJson('/api/v1/whatsapp/links')
            ->assertOk()
            ->assertJsonPath('links.0.name', 'Historia del sábado')
            ->assertJsonPath('links.0.message', 'Hola, quiero el 2x1 del sábado 💅');

        $this->patchJson("/api/v1/whatsapp/links/{$id}", ['name' => 'Historia', 'message' => 'Hola 👋'])
            ->assertOk()
            ->assertJsonPath('message', 'Hola 👋');

        $this->deleteJson("/api/v1/whatsapp/links/{$id}")->assertNoContent();
        $this->assertSame(0, WhatsappLink::count());
    }

    public function test_devuelve_el_numero_del_negocio_con_indicativo(): void
    {
        $this->business->update(['public_profile' => ['whatsapp' => '301 948 9912']]);

        $this->getJson('/api/v1/whatsapp/links')->assertOk()->assertJsonPath('number', '573019489912');
    }

    public function test_sin_numero_cargado_el_numero_es_nulo(): void
    {
        $this->business->update(['phone' => null, 'public_profile' => null]);

        $this->getJson('/api/v1/whatsapp/links')->assertOk()->assertJsonPath('number', null);
    }

    public function test_nombre_y_mensaje_son_obligatorios(): void
    {
        $this->postJson('/api/v1/whatsapp/links', ['name' => '', 'message' => ''])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'message']);
    }

    public function test_los_de_otro_negocio_no_se_ven_ni_se_tocan(): void
    {
        $otro = $this->makeBusiness();
        // Por DB: con alguien autenticado, create() fuerza SU negocio.
        $idAjeno = DB::table('whatsapp_links')->insertGetId([
            'business_id' => $otro->id, 'name' => 'Ajeno', 'message' => 'Hola',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->getJson('/api/v1/whatsapp/links')->assertOk()->assertJsonCount(0, 'links');
        $this->deleteJson("/api/v1/whatsapp/links/{$idAjeno}")->assertNotFound();
    }

    public function test_solo_quien_administra_el_negocio(): void
    {
        $this->actuarComo($this->business, PermissionCatalog::ROLE_RECEPTION);

        $this->getJson('/api/v1/whatsapp/links')->assertForbidden();
        $this->postJson('/api/v1/whatsapp/links', ['name' => 'x', 'message' => 'y'])->assertForbidden();
    }
}
