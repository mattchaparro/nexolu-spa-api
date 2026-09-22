<?php

namespace Tests\Feature\Ai;

use App\Models\Business;
use App\Models\User;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Scheduling\SchedulingScenario;
use Tests\TestCase;

/**
 * "Enséñale al bot": el panel del spa escribe las preguntas frecuentes, que
 * viven en el IA Core.
 *
 * Lo que se defiende: el negocio lo pone el servidor (nunca el navegador),
 * solo quien tiene `ia.conocimiento` edita, y si el Core no responde se
 * dice claro.
 */
class BotKnowledgeTest extends TestCase
{
    use RefreshDatabase, SchedulingScenario;

    private Business $business;

    protected function setUp(): void
    {
        parent::setUp();

        PermissionCatalog::sync();
        config()->set('services.ia_core.api_key', 'llave-spa');
        config()->set('services.ia_core.base_url', 'http://ia-core.test');

        $this->business = $this->makeBusiness();
    }

    private function usuario(string $rol): User
    {
        $user = User::create([
            'business_id' => $this->business->id, 'name' => 'Alguien',
            'email' => $rol.'@prueba.test', 'password' => Hash::make('password123'), 'is_active' => true,
        ]);
        PermissionCatalog::applyRole($user, $rol);

        return $user->fresh();
    }

    public function test_la_duena_crea_una_entrada_y_el_negocio_lo_pone_el_servidor(): void
    {
        Http::fake(['ia-core.test/*' => Http::response([
            'id' => 'k1', 'topic' => 'Garantías', 'answer' => '5 días.', 'is_active' => true, 'updated_at' => '2026-09-22T10:00:00',
        ], 201)]);
        Sanctum::actingAs($this->usuario(PermissionCatalog::ROLE_ADMIN));

        // Aunque el navegador mande otro negocio, viaja el del usuario.
        $this->postJson('/api/v1/bot/knowledge', [
            'topic' => 'Garantías', 'answer' => '5 días.', 'business_id' => '999',
        ])->assertCreated()->assertJsonPath('data.id', 'k1');

        Http::assertSent(fn ($r) => $r->url() === 'http://ia-core.test/v1/knowledge'
            && $r->data()['business_id'] === (string) $this->business->id
            && $r->hasHeader('Authorization', 'Bearer llave-spa'));
    }

    public function test_lista_las_del_negocio(): void
    {
        Http::fake(['ia-core.test/*' => Http::response([
            ['id' => 'k1', 'topic' => 'Parqueadero', 'answer' => 'Sí.', 'is_active' => true, 'updated_at' => '2026-09-22T10:00:00'],
        ])]);
        Sanctum::actingAs($this->usuario(PermissionCatalog::ROLE_ADMIN));

        $this->getJson('/api/v1/bot/knowledge')->assertOk()->assertJsonPath('data.0.topic', 'Parqueadero');

        Http::assertSent(fn ($r) => str_contains($r->url(), 'business_id='.$this->business->id));
    }

    public function test_sin_el_permiso_no_se_edita(): void
    {
        Http::fake();
        Sanctum::actingAs($this->usuario(PermissionCatalog::ROLE_STAFF));

        $this->postJson('/api/v1/bot/knowledge', ['topic' => 'X', 'answer' => 'Y'])->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_una_entrada_ajena_es_404(): void
    {
        Http::fake(['ia-core.test/*' => Http::response(['detail' => 'No existe esa entrada.'], 404)]);
        Sanctum::actingAs($this->usuario(PermissionCatalog::ROLE_ADMIN));

        $this->patchJson('/api/v1/bot/knowledge/ajena', ['answer' => 'hackeo'])->assertNotFound();
    }

    public function test_si_el_core_no_responde_se_dice_claro(): void
    {
        Http::fake(['ia-core.test/*' => Http::response('caido', 500)]);
        Sanctum::actingAs($this->usuario(PermissionCatalog::ROLE_ADMIN));

        $this->getJson('/api/v1/bot/knowledge')
            ->assertStatus(503)
            ->assertJsonPath('message', 'No pude hablar con el asistente de IA. Intenta de nuevo.');
    }
}
