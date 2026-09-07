<?php

namespace Tests\Feature\Whatsapp;

use App\Models\Business;
use App\Models\Message;
use App\Models\User;
use App\Models\WhatsappConversation;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
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

    /** Una conversación con lo que haga falta. */
    private function conversacion(array $overrides = []): WhatsappConversation
    {
        return WhatsappConversation::create(array_merge([
            'business_id' => $this->business->id,
            'phone' => '5730011122'.random_int(10, 99),
            'status' => WhatsappConversation::STATUS_OPEN,
            'last_message_at' => now(),
            'last_inbound_at' => now(),
        ], $overrides));
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

    public function test_una_conversacion_sin_leer_cuenta(): void
    {
        $this->conversacion(['read_at' => null]);

        $this->assertSame(1, $this->badges()['inbox_unread']);
    }

    public function test_una_conversacion_leida_no_cuenta(): void
    {
        $this->conversacion(['read_at' => now()->addSecond()]);

        $this->assertSame(0, $this->badges()['inbox_unread']);
    }

    public function test_un_mensaje_nuevo_despues_de_leerla_la_vuelve_a_contar(): void
    {
        /*
         * El caso que un booleano de "leído" no cubre: alguien abrió la
         * conversación en la mañana y la clienta volvió a escribir a mediodía.
         * Por eso se comparan las dos fechas y no se guarda una marca.
         */
        $conv = $this->conversacion(['read_at' => now()]);

        $this->assertSame(0, $this->badges()['inbox_unread']);

        $conv->update(['last_inbound_at' => now()->addHour()]);

        $this->assertSame(1, $this->badges()['inbox_unread']);
    }

    public function test_una_conversacion_cerrada_no_cuenta(): void
    {
        // Cerrarla es decir "esto ya se resolvió". Si siguiera contando, el
        // numerito no bajaría nunca y nadie lo miraría más.
        $this->conversacion(['status' => WhatsappConversation::STATUS_CLOSED, 'read_at' => null]);

        $this->assertSame(0, $this->badges()['inbox_unread']);
    }

    public function test_la_conversacion_de_otro_negocio_no_cuenta(): void
    {
        /*
         * El límite duro del multi-tenant, también acá: un numerito que suma
         * las conversaciones del local de al lado le está contando a un dueño
         * cuánto trabajo tiene el otro.
         */
        $otro = $this->makeBusiness();

        DB::table('whatsapp_conversations')->insert([
            'business_id' => $otro->id, 'phone' => '573009998877',
            'status' => WhatsappConversation::STATUS_OPEN,
            'last_inbound_at' => now(), 'read_at' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertSame(0, $this->badges()['inbox_unread']);
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
