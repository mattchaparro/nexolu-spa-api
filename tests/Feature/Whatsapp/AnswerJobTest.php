<?php

namespace Tests\Feature\Whatsapp;

use App\Ai\GuidedEntry;
use App\Ai\OpcionesEnviadas;
use App\Ai\UltimoPedido;
use App\Jobs\AnswerWhatsappMessageJob;
use App\Models\Client;
use App\Models\Message;
use App\Models\WhatsappConversation;
use App\Support\ChannelPhone;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Scheduling\SchedulingScenario;
use Tests\TestCase;

/**
 * El job que contesta, de punta a punta, turno tras turno.
 *
 * Las pruebas de Toques y GuidedEntry llamaban a esas clases directo y
 * nunca pasaban por el job -- y el hueco estaba en el job: la marca de
 * "ya salieron botones" quedaba viva del turno anterior y se tragaba la
 * respuesta siguiente. Alejandro tocó «Agendar en la web» y no recibió
 * el link. Aquí cada turno entra como entra en producción.
 */
class AnswerJobTest extends TestCase
{
    use RefreshDatabase, SchedulingScenario;

    private const PHONE = '573001112233';

    private WhatsappConversation $conversacion;

    protected function setUp(): void
    {
        parent::setUp();

        PermissionCatalog::sync();
        config()->set('services.comms_core.api_key', 'llave-comms');
        config()->set('services.comms_core.base_url', 'http://comms.test');
        config()->set('services.ia_core.api_key', 'llave-ia');
        config()->set('services.ia_core.base_url', 'http://ia-core.test');
        config()->set('spa.public_booking_url', 'https://agenda.test');

        Http::fake([
            'comms.test/*' => Http::response(['results' => [['channel' => 'whatsapp', 'status' => 'sent']]]),
            // Si el modelo llegara a hablar, se nota: no debería en estos turnos.
            'ia-core.test/*' => Http::response(['conversation_id' => 'c1', 'text' => 'RESPUESTA DEL MODELO', 'tools_used' => []]),
        ]);

        $business = $this->makeBusiness();
        $business->forceFill(['slug' => 'luxury', 'messaging_mode' => 'auto', 'whatsapp_phone_number_id' => '111222333'])->save();
        $maria = $this->makeResource($business, 'Maria', '09:00:00', '18:00:00');
        $this->makeService($business, 60, [$maria], name: 'Semipermanente');

        $cliente = Client::create([
            'business_id' => $business->id,
            'name' => 'Mateo',
            'phone' => ChannelPhone::normalize(self::PHONE),
            'is_active' => true,
        ]);

        $this->conversacion = WhatsappConversation::withoutGlobalScope('business')->create([
            'business_id' => $business->id,
            'phone' => ChannelPhone::normalize(self::PHONE),
            'client_id' => $cliente->id,
            'last_message_at' => now(),
            'last_inbound_at' => now(),
            'status' => WhatsappConversation::STATUS_OPEN,
        ]);

        UltimoPedido::olvidar((string) ChannelPhone::normalize(self::PHONE));
    }

    /**
     * Como llega por el webhook firmado: guarda el mensaje y el controlador
     * decide (pausa, rieles, modelo). Con la cola `sync` el job corre ya.
     */
    private function llegaPorElWebhook(string $texto): TestResponse
    {
        config()->set('services.comms_core.webhook_secret', 'secreto');
        config()->set('queue.default', 'sync');
        config()->set('spa.defaults.whatsapp_agent_debounce_seconds', 0);

        $body = json_encode(['entry' => [['changes' => [['value' => [
            'metadata' => ['phone_number_id' => '111222333'],
            'messages' => [[
                'id' => 'wamid.'.uniqid(),
                'from' => self::PHONE,
                'type' => 'text',
                'text' => ['body' => $texto],
            ]],
        ]]]]]]);
        $timestamp = (string) now()->timestamp;

        return $this->call('POST', '/api/webhooks/nexolu-comms/whatsapp', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_NEXOLU_TIMESTAMP' => $timestamp,
            'HTTP_X_NEXOLU_SIGNATURE' => hash_hmac('sha256', $timestamp.'.'.$body, 'secreto'),
        ], $body);
    }

    public function test_en_pausa_sin_nadie_que_atienda_agendar_si_se_responde(): void
    {
        /*
         * Alejandro pidió una persona (la política de garantías), el bot
         * quedó en pausa dos horas, nadie del equipo llegó, y su "Quiero
         * agendar una cita" murió sin respuesta. Mientras no conteste una
         * persona, lo que se resuelve con botones lo sigue atendiendo el bot.
         */
        $this->conversacion->pauseAgent();

        $this->llegaPorElWebhook('Quiero agendar una cita')
            ->assertOk()->assertJsonPath('agent', 'paused_rails');

        Http::assertSent(fn ($r) => str_contains($r->data()['text'] ?? '', '¿Cómo prefieres agendar?'));
        $this->assertFalse($this->conversacion->refresh()->agentIsPaused());
    }

    public function test_en_pausa_la_charla_libre_espera_a_la_persona(): void
    {
        // Como en el caso real: el bot acababa de decir "ya le avisé".
        Message::create([
            'business_id' => $this->conversacion->business_id,
            'conversation_id' => $this->conversacion->id,
            'kind' => Message::KIND_AGENT,
            'direction' => Message::DIRECTION_OUT,
            'to' => $this->conversacion->phone,
            'body' => 'Ya le avisé a alguien del local. Te escriben enseguida.',
            'status' => Message::STATUS_SENT,
            'sent_at' => now(),
        ]);
        $this->conversacion->pauseAgent();

        $this->llegaPorElWebhook('?')->assertOk();

        // Ni riel ni modelo: la conversación abierta es de quien viene.
        Http::assertNotSent(fn ($r) => str_contains($r->data()['text'] ?? '', 'RESPUESTA DEL MODELO'));
        $this->assertTrue($this->conversacion->refresh()->agentIsPaused());
    }

    public function test_si_una_persona_ya_contesto_el_bot_no_se_mete(): void
    {
        $this->conversacion->pauseAgent();
        Message::create([
            'business_id' => $this->conversacion->business_id,
            'conversation_id' => $this->conversacion->id,
            'kind' => Message::KIND_HUMAN,
            'direction' => Message::DIRECTION_OUT,
            'to' => $this->conversacion->phone,
            'body' => 'Hola Mateo, soy Ana del salón. Te cuento sobre las garantías…',
            'status' => Message::STATUS_SENT,
            'sent_at' => now(),
        ]);

        $this->llegaPorElWebhook('Quiero agendar una cita')
            ->assertOk()->assertJsonPath('agent', 'paused');

        Http::assertNotSent(fn ($r) => str_contains($r->data()['text'] ?? '', '¿Cómo prefieres agendar?'));
    }

    /** Un mensaje de la clienta, contestado por el job como en producción. */
    private function escribe(string $texto): void
    {
        $entrante = Message::create([
            'business_id' => $this->conversacion->business_id,
            'conversation_id' => $this->conversacion->id,
            'client_id' => $this->conversacion->client_id,
            'kind' => Message::KIND_INBOUND,
            'direction' => Message::DIRECTION_IN,
            'to' => $this->conversacion->phone,
            'body' => $texto,
            'status' => Message::STATUS_SENT,
            'sent_at' => now(),
        ]);

        AnswerWhatsappMessageJob::dispatchSync($this->conversacion->id, $entrante->id);
    }

    public function test_agendar_en_la_web_si_manda_el_link(): void
    {
        $this->escribe('Buenas noches, para agendar una cita');
        Http::assertSent(fn ($r) => str_contains($r->data()['text'] ?? '', '¿Cómo prefieres agendar?'));

        // El toque, segundos después: el link TIENE que salir.
        $this->escribe(GuidedEntry::WEB);

        Http::assertSent(fn ($r) => str_contains($r->data()['text'] ?? '', 'https://agenda.test/reservar/luxury'));
        Http::assertNotSent(fn ($r) => str_contains($r->data()['text'] ?? '', 'RESPUESTA DEL MODELO'));
    }

    public function test_un_saludo_nuevo_no_arrastra_lo_que_murio_horas_antes(): void
    {
        /*
         * Alejandro escribió "Buenas" y recibió "¿Cómo prefieres agendar?":
         * el bot juntó su "Quiero agendar una cita" y su "?" de tres horas
         * antes, que habían quedado sin respuesta en una pausa. Solo se
         * juntan los pedazos que llegan cerca del último.
         */
        foreach (['Quiero agendar una cita', '?'] as $viejo) {
            $m = Message::create([
                'business_id' => $this->conversacion->business_id,
                'conversation_id' => $this->conversacion->id,
                'client_id' => $this->conversacion->client_id,
                'kind' => Message::KIND_INBOUND,
                'direction' => Message::DIRECTION_IN,
                'to' => $this->conversacion->phone,
                'body' => $viejo,
                'status' => Message::STATUS_SENT,
                'sent_at' => now()->subHours(3),
            ]);
            $m->forceFill(['created_at' => now()->subHours(3)])->save();
        }

        $this->escribe('Buenas');

        Http::assertSent(fn ($r) => str_contains($r->data()['text'] ?? '', '¿Qué deseas hacer el día de hoy?'));
        Http::assertNotSent(fn ($r) => str_contains($r->data()['text'] ?? '', '¿Cómo prefieres agendar?'));
    }

    public function test_saludar_dos_veces_seguidas_no_pausa_al_bot(): void
    {
        /*
         * La conversación de Alejandro a las 10 pm: "Hola, de nuevo" y
         * luego "Buenas noches". El modelo contestó dos veces lo mismo, el
         * cortador de bucles lo tomó por bucle y lo dejó en pausa. Un
         * saludo abre el iniciador y nunca cuenta como bucle.
         */
        $this->escribe('Hola, de nuevo');
        $this->escribe('Buenas noches');

        $this->assertFalse($this->conversacion->refresh()->agentIsPaused());
        Http::assertNotSent(fn ($r) => str_contains($r->data()['text'] ?? '', 'no te estoy entendiendo'));
        $this->assertSame(2, collect(Http::recorded())
            ->filter(fn ($par) => str_contains($par[0]->data()['text'] ?? '', '¿Qué deseas hacer el día de hoy?'))
            ->count());
    }

    public function test_la_conversacion_inicial_completa_pasa_por_el_job(): void
    {
        // Hola → [Agendar] [Mis citas] [Otra consulta] → Otra consulta →
        // Otra pregunta: cada respuesta del código sale, turno tras turno.
        $this->escribe('Hola');
        $this->escribe(GuidedEntry::OTHER);
        $this->escribe(GuidedEntry::QUESTION);

        Http::assertSent(fn ($r) => str_contains($r->data()['text'] ?? '', '¿Qué deseas hacer el día de hoy?'));
        Http::assertSent(fn ($r) => str_contains($r->data()['text'] ?? '', '¿Qué necesitas?'));
        Http::assertSent(fn ($r) => str_contains($r->data()['text'] ?? '', '¿en qué te ayudo?'));
        Http::assertNotSent(fn ($r) => str_contains($r->data()['text'] ?? '', 'RESPUESTA DEL MODELO'));
    }

    public function test_si_una_herramienta_manda_botones_el_texto_del_modelo_no_se_duplica(): void
    {
        // La guarda original sigue viva para el MODELO: si en su turno una
        // herramienta mandó botones, su texto sobra.
        Http::fake([
            'comms.test/*' => Http::response(['results' => [['channel' => 'whatsapp', 'status' => 'sent']]]),
            'ia-core.test/*' => function () {
                OpcionesEnviadas::marcar((string) ChannelPhone::normalize(self::PHONE));

                return Http::response(['conversation_id' => 'c1', 'text' => 'Te mando las horas', 'tools_used' => ['disponibilidad']]);
            },
        ]);

        $this->escribe('¿tienen parqueadero? y quiero ver horas');

        Http::assertNotSent(fn ($r) => str_contains($r->data()['text'] ?? '', 'Te mando las horas'));
    }
}
