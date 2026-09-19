<?php

namespace Tests\Feature\WhatsApp;

use App\Models\Business;
use App\Models\User;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Scheduling\SchedulingScenario;
use Tests\TestCase;

/**
 * La llave para ver la bandeja de Connect dentro de este panel.
 *
 * La bandeja de WhatsApp está escrita dos veces: una en Connect -- con
 * búsqueda, plantillas, adjuntos y ficha del contacto -- y otra acá, más
 * pobre. Cada mejora hay que hacerla dos veces. Así que la pantalla pasa
 * a ser la de Connect, mostrada adentro, y esto es lo que la deja entrar.
 *
 * Lo que se prueba es lo que puede salir mal: que la API key del Spa
 * baje a un navegador, que alguien pida la bandeja de otro salón, y que
 * un Connect caído tumbe el panel entero.
 */
class ChatEmbebidoTest extends TestCase
{
    use RefreshDatabase, SchedulingScenario;

    private Business $business;

    private User $recepcion;

    protected function setUp(): void
    {
        parent::setUp();

        PermissionCatalog::sync();
        config()->set('services.comms_core.api_key', 'llave-del-spa');
        config()->set('services.comms_core.base_url', 'http://comms.test');

        $this->business = $this->makeBusiness();
        // Recepcion tiene `clientes.ver`: es quien contesta.
        $this->recepcion = $this->makeUserConRol(PermissionCatalog::ROLE_RECEPTION);
    }

    private function makeUserConRol(string $rol): User
    {
        $user = User::create([
            'business_id' => $this->business->id,
            'name' => 'Ana',
            'email' => 'ana'.uniqid().'@luxury.test',
            'password' => Hash::make('secreta'),
            'is_active' => true,
        ]);

        PermissionCatalog::applyRole($user, $rol);

        return $user->fresh();
    }

    public function test_devuelve_el_token_y_la_url_que_hay_que_embeber(): void
    {
        Http::fake([
            'comms.test/v1/embed/chat-token' => Http::response([
                'token' => 'token-corto',
                'expires_at' => '2026-09-19T10:00:00Z',
                'url' => 'https://connect.nexolu.co/embebido/chat',
            ]),
        ]);

        $this->actingAs($this->recepcion)
            ->getJson('/api/v1/whatsapp/chat-embebido/token')
            ->assertOk()
            ->assertJsonPath('token', 'token-corto')
            // La URL la dice Connect: si cambia su ruta, no hay que
            // desplegar el Spa.
            ->assertJsonPath('url', 'https://connect.nexolu.co/embebido/chat');
    }

    public function test_el_negocio_sale_de_la_sesion_y_no_del_request(): void
    {
        /*
         * Lo único que impide que alguien vea la bandeja de otro salón.
         * Ni siquiera hay un parámetro que enviar: pedir el token de otro
         * negocio tiene que ser imposible de escribir, no algo que se
         * valide.
         */
        Http::fake(['comms.test/*' => Http::response(['token' => 't', 'expires_at' => null, 'url' => 'u'])]);

        $this->actingAs($this->recepcion)
            ->getJson('/api/v1/whatsapp/chat-embebido/token?business_id=99')
            ->assertOk();

        Http::assertSent(fn ($request) => $request['business_id'] === (string) $this->business->id);
    }

    public function test_la_llave_del_spa_no_baja_al_navegador(): void
    {
        // Esa llave manda WhatsApp a nombre de TODOS los negocios. El
        // token que sale de acá dura minutos y ve un solo salón.
        Http::fake([
            'comms.test/*' => Http::response([
                'token' => 'token-corto', 'expires_at' => null, 'url' => 'u',
            ]),
        ]);

        $cuerpo = $this->actingAs($this->recepcion)
            ->getJson('/api/v1/whatsapp/chat-embebido/token')
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('llave-del-spa', (string) $cuerpo);
    }

    public function test_sin_permiso_de_clientes_no_se_entrega(): void
    {
        // Una manicurista ve su agenda, no la base de clientas.
        $sinPermiso = $this->makeUserConRol(PermissionCatalog::ROLE_STAFF);

        $this->actingAs($sinPermiso)
            ->getJson('/api/v1/whatsapp/chat-embebido/token')
            ->assertForbidden();
    }

    public function test_si_connect_esta_caido_el_panel_no_se_cae(): void
    {
        // El chat es una pantalla del panel, no el panel: que Connect no
        // conteste tiene que ser "el chat no está disponible", no un 500
        // en la cara de quien estaba mirando la agenda.
        Http::fake(['comms.test/*' => Http::response('nope', 502)]);

        $this->actingAs($this->recepcion)
            ->getJson('/api/v1/whatsapp/chat-embebido/token')
            ->assertStatus(503)
            ->assertJsonPath('message', 'El chat no está disponible en este momento.');
    }

    public function test_sin_configurar_lo_dice_en_vez_de_reventar(): void
    {
        config()->set('services.comms_core.api_key', '');

        $this->actingAs($this->recepcion)
            ->getJson('/api/v1/whatsapp/chat-embebido/token')
            ->assertStatus(503);
    }
}
