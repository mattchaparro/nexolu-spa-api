<?php

namespace Tests\Feature\Whatsapp;

use App\Models\Business;
use App\Models\Message;
use App\Models\User;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Scheduling\SchedulingScenario;
use Tests\TestCase;

/**
 * Los numeritos del menú.
 *
 * Existen porque la bandeja y el outbox son las dos únicas pantallas donde
 * alguien de AFUERA está esperando: una clienta que escribió y nadie le
 * contestó, un recordatorio que no salió. Sin el numerito, quien atiende
 * tiene que acordarse de entrar a mirar.
 *
 * Lo que estas pruebas cuidan es que el conteo NO cuente de más: un numerito
 * que dice 3 cuando no hay nada enseña a ignorarlo, y a partir de ahí deja de
 * servir para siempre.
 */
class NavBadgesTest extends TestCase
{
    use RefreshDatabase, SchedulingScenario;

    private Business $business;

    private User $admin;

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

        Sanctum::actingAs($this->admin->fresh());
    }

    private function badges(): array
    {
        return $this->getJson('/api/v1/nav-badges')->assertOk()->json();
    }

    public function test_sin_nada_pendiente_los_contadores_van_en_cero(): void
    {
        // Un "0" que aparece siempre se vuelve parte del decorado. La pantalla
        // sólo pinta el numerito cuando este valor es mayor que cero.
        $this->assertSame(0, $this->badges()['inbox_unread']);
        $this->assertSame(0, $this->badges()['outbox_pending']);
    }

    public function test_las_conversaciones_sin_leer_las_cuenta_connect(): void
    {
        /*
         * El chat vive en Connect: leer una conversacion alla es lo unico que
         * la saca del conteo, asi que el numero sale de alla. Y del negocio
         * de la sesion, nunca de otro.
         */
        $this->conConnect();
        Http::fake(['connect.test/v1/app-users/unread*' => Http::response(['unread' => 3])]);

        $this->assertSame(3, $this->badges()['inbox_unread']);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'business_id='.$this->business->id)
            && $request->hasHeader('Authorization', 'Bearer llave-del-spa'));
    }

    public function test_si_connect_no_responde_el_menu_sigue(): void
    {
        // Un contador que falla no puede tumbar el menu de toda la app.
        $this->conConnect();
        Http::fake(['connect.test/*' => Http::response('caido', 502)]);

        $this->assertSame(0, $this->badges()['inbox_unread']);
    }

    public function test_a_quien_no_abre_el_chat_no_se_le_pregunta_a_connect(): void
    {
        $this->conConnect();
        Http::fake();

        $manicurista = User::create([
            'business_id' => $this->business->id, 'name' => 'Manicurista',
            'email' => 'mani@prueba.test', 'password' => Hash::make('password123'), 'is_active' => true,
        ]);
        PermissionCatalog::applyRole($manicurista, PermissionCatalog::ROLE_STAFF);
        Sanctum::actingAs($manicurista->fresh());

        $this->assertSame(0, $this->badges()['inbox_unread']);
        Http::assertNothingSent();
    }

    private function conConnect(): void
    {
        config([
            'services.comms_core.base_url' => 'https://connect.test',
            'services.comms_core.api_key' => 'llave-del-spa',
        ]);
    }

    public function test_solo_cuenta_lo_que_espera_que_alguien_lo_mande(): void
    {
        /*
         * `pendiente` a secas sale solo por la cola: nadie tiene que hacer
         * nada. Contarlo mandaría a una persona a una pantalla donde no hay
         * nada que hacer.
         */
        $comun = [
            'business_id' => $this->business->id,
            'kind' => Message::KIND_REMINDER,
            'to' => '573001112233',
            'body' => 'Te esperamos mañana.',
        ];

        Message::create($comun + ['status' => Message::STATUS_MANUAL]);
        Message::create($comun + ['status' => Message::STATUS_PENDING]);
        Message::create($comun + ['status' => Message::STATUS_SENT]);

        $this->assertSame(1, $this->badges()['outbox_pending']);
    }
}
