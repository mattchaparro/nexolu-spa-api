<?php

namespace Tests\Feature\Whatsapp;

use App\Jobs\RevokeConnectChatAccessJob;
use App\Models\Business;
use App\Models\User;
use App\Services\WhatsApp\ConnectChat;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Scheduling\SchedulingScenario;
use Tests\TestCase;

/**
 * El menu "WhatsApp" abre el chat de Connect, con la persona adentro.
 *
 * Lo que cuidan estas pruebas es lo que no se ve: que la API key del Spa
 * no salga nunca hacia el navegador, que el salon lo ponga la sesion y no
 * el request, y que a quien le quitan el chat aca se lo quiten tambien
 * alla -- si no, le seguirian llegando al celular los mensajes de las
 * clientas.
 */
class ConnectChatTest extends TestCase
{
    use RefreshDatabase, SchedulingScenario;

    private Business $business;

    private User $recepcion;

    protected function setUp(): void
    {
        parent::setUp();

        PermissionCatalog::sync();
        config([
            'services.comms_core.base_url' => 'https://connect.test',
            'services.comms_core.api_key' => 'llave-del-spa',
        ]);

        $this->business = $this->makeBusiness();
        $this->recepcion = $this->persona('recepcion@prueba.test', PermissionCatalog::ROLE_RECEPTION, 'Ana');
    }

    private function persona(string $email, string $role, string $name = 'Persona'): User
    {
        $user = User::create([
            'business_id' => $this->business->id, 'name' => $name, 'last_name' => 'Pérez',
            'email' => $email, 'password' => Hash::make('password123'), 'is_active' => true,
        ]);
        PermissionCatalog::applyRole($user, $role);

        return $user->fresh();
    }

    public function test_entra_a_la_persona_al_chat_de_su_salon(): void
    {
        Http::fake(['connect.test/v1/app-users/login-ticket' => Http::response([
            'url' => 'https://connect.test/entrar#ticket=abc&next=%2Fchat',
            'expires_at' => now()->addMinutes(2)->toIso8601String(),
        ])]);
        Sanctum::actingAs($this->recepcion);

        $respuesta = $this->postJson('/api/v1/whatsapp/connect-link', ['business_id' => 999])
            ->assertOk()
            ->json();

        // Al navegador solo le llega la URL: ni la llave ni nada mas.
        $this->assertSame(['url' => 'https://connect.test/entrar#ticket=abc&next=%2Fchat'], $respuesta);

        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer llave-del-spa')
            && $request['user_ref'] === (string) $this->recepcion->id
            // El salon sale de la sesion, no de lo que mande el navegador.
            && $request['business_id'] === (string) $this->business->id
            && $request['full_name'] === 'Ana Pérez');
    }

    public function test_una_manicurista_no_abre_el_chat(): void
    {
        Http::fake();
        Sanctum::actingAs($this->persona('mani@prueba.test', PermissionCatalog::ROLE_STAFF));

        $this->postJson('/api/v1/whatsapp/connect-link')->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_si_connect_esta_caido_se_dice_y_el_panel_sigue(): void
    {
        Http::fake(['connect.test/*' => Http::response('caido', 502)]);
        Sanctum::actingAs($this->recepcion);

        $this->postJson('/api/v1/whatsapp/connect-link')->assertStatus(503);
    }

    public function test_sin_configurar_no_se_intenta(): void
    {
        config(['services.comms_core.api_key' => '']);
        Http::fake();
        Sanctum::actingAs($this->recepcion);

        $this->postJson('/api/v1/whatsapp/connect-link')->assertStatus(503);
        Http::assertNothingSent();
    }

    public function test_desactivarla_le_quita_el_chat_de_connect(): void
    {
        Queue::fake();

        $this->recepcion->update(['is_active' => false]);

        Queue::assertPushed(RevokeConnectChatAccessJob::class, fn ($job) => $job->userId === $this->recepcion->id);
    }

    public function test_cambiarle_otra_cosa_no_la_toca_en_connect(): void
    {
        Queue::fake();

        $this->recepcion->update(['phone' => '573001112233']);

        Queue::assertNotPushed(RevokeConnectChatAccessJob::class);
    }

    public function test_quitarle_clientes_ver_le_quita_el_chat_de_connect(): void
    {
        Queue::fake();
        $admin = $this->persona('admin@prueba.test', PermissionCatalog::ROLE_ADMIN);
        Sanctum::actingAs($admin);

        $this->putJson("/api/v1/permissions/{$this->recepcion->id}", [
            'permissions' => ['citas.ver'],
        ])->assertOk();

        Queue::assertPushed(RevokeConnectChatAccessJob::class, fn ($job) => $job->userId === $this->recepcion->id);
    }

    public function test_el_job_le_avisa_a_connect(): void
    {
        Http::fake(['connect.test/v1/app-users/*' => Http::response(null, 204)]);

        (new RevokeConnectChatAccessJob($this->recepcion->id))->handle(app(ConnectChat::class));

        Http::assertSent(fn ($request) => $request->method() === 'DELETE'
            && $request->url() === "https://connect.test/v1/app-users/{$this->recepcion->id}");
    }

    public function test_si_connect_falla_el_job_reintenta(): void
    {
        Http::fake(['connect.test/*' => Http::response('caido', 502)]);

        $this->expectException(RequestException::class);

        (new RevokeConnectChatAccessJob($this->recepcion->id))->handle(app(ConnectChat::class));
    }
}
