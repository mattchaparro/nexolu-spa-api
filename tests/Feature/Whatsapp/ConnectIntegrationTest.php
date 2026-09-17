<?php

namespace Tests\Feature\Whatsapp;

use App\Models\Business;
use App\Models\Message;
use App\Models\WhatsappConversation;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Scheduling\SchedulingScenario;
use Tests\TestCase;

/**
 * El empalme fino con Nexolu Connect (fase 06): la coordinacion flujo/bot,
 * el relevo a humano que pide un flujo, y la herramienta de disponibilidad
 * que consulta la "Solicitud externa" de un flujo.
 *
 * Lo que se defiende: que NUNCA hablen dos voces a la vez (el flujo de
 * Connect y el agente IA), y que un flujo solo pueda LEER horas, jamas
 * escribir agenda.
 */
class ConnectIntegrationTest extends TestCase
{
    use RefreshDatabase, SchedulingScenario;

    private const SECRET = 'secreto-de-comms';

    private const TOOLS_KEY = 'llave-de-flujos';

    private Business $luxury;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-09-15 10:00', 'America/Bogota'));

        config()->set('services.comms_core.webhook_secret', self::SECRET);
        config()->set('services.comms_core.tools_key', self::TOOLS_KEY);
        config()->set('services.ia_core.api_key', 'llave-ia');
        config()->set('services.ia_core.base_url', 'http://ia-core.test');

        $this->luxury = $this->makeBusiness();
        $this->luxury->forceFill(['whatsapp_phone_number_id' => '111222333'])->save();
    }

    private function firmado(array $payload, array $headers = []): TestResponse
    {
        $body = json_encode($payload);
        $timestamp = (string) now()->timestamp;

        return $this->call(
            'POST',
            '/api/webhooks/nexolu-comms/whatsapp',
            [], [], [],
            array_merge([
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_X_NEXOLU_TIMESTAMP' => $timestamp,
                'HTTP_X_NEXOLU_SIGNATURE' => hash_hmac('sha256', $timestamp.'.'.$body, self::SECRET),
            ], $headers),
            $body,
        );
    }

    private function sobreDeMeta(string $texto): array
    {
        return [
            'entry' => [['changes' => [['value' => [
                'metadata' => ['phone_number_id' => '111222333'],
                'messages' => [[
                    'from' => '573001112233',
                    'type' => 'text',
                    'text' => ['body' => $texto],
                ]],
            ]]]]],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Coordinacion flujo/bot (X-Nexolu-Flow-Handled)
    |--------------------------------------------------------------------------
    */

    public function test_si_un_flujo_ya_atendio_el_agente_se_calla(): void
    {
        // Si el agente llegara a correr, respondería esto -- no debe salir.
        Http::fake(['ia-core.test/*' => Http::response([
            'conversation_id' => 'conv-1', 'text' => 'Respuesta duplicada', 'tools_used' => [],
        ])]);

        $this->firmado($this->sobreDeMeta('hola'), ['HTTP_X_NEXOLU_FLOW_HANDLED' => '1'])
            ->assertOk()->assertJsonPath('agent', 'flow');

        // Lo que escribio la clienta SI queda en el hilo (la bandeja lo
        // muestra aunque el flujo haya contestado por WhatsApp)...
        $this->assertSame(1, Message::withoutGlobalScopes()
            ->where('direction', Message::DIRECTION_IN)->count());

        // ...pero el agente no dijo nada: una sola voz por mensaje.
        $this->assertSame(0, Message::withoutGlobalScopes()
            ->where('direction', Message::DIRECTION_OUT)->count());
        Http::assertNothingSent();
    }

    public function test_si_ningun_flujo_lo_reclamo_el_agente_contesta(): void
    {
        Http::fake(['ia-core.test/*' => Http::response([
            'conversation_id' => 'conv-1', 'text' => 'Claro, ¿para qué día?', 'tools_used' => [],
        ])]);

        $this->firmado($this->sobreDeMeta('quiero una cita'), ['HTTP_X_NEXOLU_FLOW_HANDLED' => '0'])
            ->assertOk()->assertJsonPath('handled', true);

        $this->assertSame(1, Message::withoutGlobalScopes()
            ->where('direction', Message::DIRECTION_OUT)
            ->where('kind', Message::KIND_AGENT)->count());
    }

    /*
    |--------------------------------------------------------------------------
    | flow_notify: el relevo a humano
    |--------------------------------------------------------------------------
    */

    private function notifyPayload(?string $businessId): array
    {
        return [
            'object' => 'nexolu-comms',
            'event' => 'flow_notify',
            'flow' => 'menu_principal',
            'message' => 'La clienta pidió hablar con una persona.',
            'business_id' => $businessId ?? '',
            'contact' => [
                'name' => 'Valentina',
                'phone' => '573001112233',
                'tags' => [],
                'fields' => [],
            ],
        ];
    }

    public function test_flow_notify_calla_al_bot_y_deja_la_nota_en_el_hilo(): void
    {
        $this->firmado($this->notifyPayload((string) $this->luxury->id))
            ->assertOk()->assertJsonPath('event', 'flow_notify');

        $conv = WhatsappConversation::withoutGlobalScopes()->first();
        $this->assertSame($this->luxury->id, $conv->business_id);
        $this->assertTrue($conv->agentIsPaused());
        $this->assertNull($conv->read_at);

        $nota = Message::withoutGlobalScopes()->where('kind', Message::KIND_STAFF)->first();
        $this->assertStringContainsString('pidió hablar con una persona', $nota->body);
        $this->assertStringContainsString('menu_principal', $nota->body);
        // Nota interna: nace enviada para que el outbox jamas la despache.
        $this->assertSame(Message::STATUS_SENT, $nota->status);
    }

    public function test_flow_notify_sin_negocio_cae_en_la_conversacion_viva_del_telefono(): void
    {
        $existente = WhatsappConversation::create([
            'business_id' => $this->luxury->id,
            'phone' => '573001112233',
            'last_message_at' => now()->subMinutes(5),
            'status' => WhatsappConversation::STATUS_OPEN,
        ]);

        $this->firmado($this->notifyPayload(null))->assertOk()->assertJsonPath('handled', true);

        $this->assertTrue($existente->fresh()->agentIsPaused());
    }

    public function test_flow_notify_sin_conversacion_ni_negocio_no_inventa_nada(): void
    {
        $this->firmado($this->notifyPayload(null))->assertOk()->assertJsonPath('handled', false);

        $this->assertSame(0, WhatsappConversation::withoutGlobalScopes()->count());
    }

    public function test_despues_del_relevo_el_agente_sigue_callado_ante_lo_que_entre(): void
    {
        Http::fake(['ia-core.test/*' => Http::response([
            'conversation_id' => 'conv-1', 'text' => 'No deberia salir', 'tools_used' => [],
        ])]);

        $this->firmado($this->notifyPayload((string) $this->luxury->id))->assertOk();
        $this->firmado($this->sobreDeMeta('¿me pueden atender?'))
            ->assertOk()->assertJsonPath('agent', 'paused');

        $this->assertSame(0, Message::withoutGlobalScopes()
            ->where('kind', Message::KIND_AGENT)->count());
    }

    /*
    |--------------------------------------------------------------------------
    | GET /api/connect/disponibilidad (la "Solicitud externa" de un flujo)
    |--------------------------------------------------------------------------
    */

    private function disponibilidad(array $query, ?string $key = self::TOOLS_KEY): TestResponse
    {
        $headers = $key === null ? [] : ['Authorization' => 'Bearer '.$key];

        return $this->getJson('/api/connect/disponibilidad?'.http_build_query($query), $headers);
    }

    public function test_sin_llave_no_hay_disponibilidad(): void
    {
        $this->disponibilidad(['negocio' => '1'], null)->assertStatus(401);
        $this->disponibilidad(['negocio' => '1'], 'llave-equivocada')->assertStatus(401);
    }

    public function test_devuelve_las_horas_reales_y_un_resumen_listo_para_el_mensaje(): void
    {
        $maria = $this->makeResource($this->luxury, 'Maria', '09:00:00', '12:00:00');
        $this->makeService($this->luxury, 60, [$maria], name: 'Manicure semipermanente');

        // Miercoles 2026-09-16: mañana respecto del travelTo del setUp.
        $respuesta = $this->disponibilidad([
            'negocio' => (string) $this->luxury->id,
            'servicio' => 'semipermanente',
            'fecha' => 'mañana',
        ]);

        $respuesta->assertOk()
            ->assertJsonPath('hay', true)
            ->assertJsonPath('servicio', 'Manicure semipermanente')
            ->assertJsonPath('fecha', '2026-09-16');

        $horas = $respuesta->json('horas');
        $this->assertContains('09:00', $horas);
        // La ultima cita de 60 min en una jornada de 9 a 12 entra a las 11.
        $this->assertContains('11:00', $horas);
        $this->assertNotContains('11:15', $horas);

        // El resumen se guarda en un custom field y se muestra tal cual en
        // el mensaje del flujo: lista humana, con "y" antes de la ultima.
        $this->assertMatchesRegularExpression('/09:00.*y \d{2}:\d{2}$/', $respuesta->json('resumen'));
    }

    public function test_un_servicio_que_no_existe_es_contenido_no_un_error(): void
    {
        $maria = $this->makeResource($this->luxury);
        $this->makeService($this->luxury, 60, [$maria], name: 'Manicure');

        // 200 a proposito: un flujo no sabe manejar un 422, pero si sabe
        // mostrar el resumen -- que ademas lista lo que SI hay.
        $this->disponibilidad([
            'negocio' => (string) $this->luxury->id,
            'servicio' => 'masaje tailandes',
            'fecha' => '2026-09-16',
        ])->assertOk()
            ->assertJsonPath('hay', false)
            ->assertJsonPath('resumen', 'No existe el servicio «masaje tailandes». Los que hay: Manicure.');
    }

    public function test_un_dia_sin_agenda_lo_dice_en_espanol(): void
    {
        $maria = $this->makeResource($this->luxury, 'Maria', '09:00:00', '12:00:00', [3]); // solo miercoles
        $this->makeService($this->luxury, 60, [$maria], name: 'Manicure');

        $this->disponibilidad([
            'negocio' => (string) $this->luxury->id,
            'servicio' => 'Manicure',
            'fecha' => '2026-09-17', // jueves: Maria no trabaja
        ])->assertOk()
            ->assertJsonPath('hay', false)
            ->assertJsonPath('resumen', 'No quedan horas libres para Manicure el 2026-09-17.');
    }

    public function test_negocio_o_fecha_invalidos_tambien_responden_en_espanol(): void
    {
        $this->disponibilidad(['negocio' => '9999', 'servicio' => 'Manicure'])
            ->assertOk()->assertJsonPath('hay', false)
            ->assertJsonPath('resumen', 'Negocio no encontrado: revisa el parámetro «negocio» del flujo.');

        $this->disponibilidad([
            'negocio' => (string) $this->luxury->id,
            'servicio' => 'Manicure',
            'fecha' => 'el proximo viernes',
        ])->assertOk()->assertJsonPath('hay', false)
            ->assertJsonPath('resumen', 'Fecha inválida: usa AAAA-MM-DD (o «hoy» / «mañana»).');
    }
}
