<?php

namespace Tests\Feature\Ai;

use App\Models\Business;
use App\Models\Client;
use App\Models\Message;
use App\Models\WhatsappConversation;
use App\Support\ChannelPhone;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Scheduling\SchedulingScenario;
use Tests\TestCase;

/**
 * Lo que llega por WhatsApp y quien lo reclama.
 *
 * El riesgo que se defiende aca es de mezcla: con un numero compartido entre
 * negocios, poner una conversacion en el local equivocado le muestra a una
 * clienta los precios, la agenda y las citas de otro spa.
 */
class WhatsappWebhookTest extends TestCase
{
    use RefreshDatabase, SchedulingScenario;

    private const SECRET = 'secreto-de-comms';

    private Business $luxury;

    private Business $otro;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::now('America/Bogota')->startOfDay()->setTime(10, 0));

        config()->set('services.comms_core.webhook_secret', self::SECRET);
        config()->set('services.ia_core.api_key', 'llave-ia');
        config()->set('services.ia_core.base_url', 'http://ia-core.test');

        /*
         * forceFill y no update: ni el código ni el número de WhatsApp son
         * fillable, igual que el token del portal. De quién es un número de
         * WhatsApp no puede quedar al alcance de un formulario -- se asigna a
         * propósito, desde superadmin.
         */
        $this->luxury = $this->makeBusiness();
        $this->luxury->update(['name' => 'Luxury Nails']);
        $this->luxury->forceFill([
            'whatsapp_code' => 'NX-LUX1',
            // Número PROPIO: es el caso de producción de este negocio.
            'whatsapp_phone_number_id' => '111222333',
        ])->save();

        $this->otro = $this->makeBusiness();
        $this->otro->update(['name' => 'Otro Spa']);
        $this->otro->forceFill(['whatsapp_code' => 'NX-OTR2'])->save();
    }

    /** El cuerpo crudo que Meta manda y Communications reenvia tal cual. */
    private function metaPayload(string $from, string $texto, ?string $phoneNumberId): array
    {
        return [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'changes' => [[
                    'value' => [
                        'metadata' => ['phone_number_id' => $phoneNumberId],
                        'messages' => [[
                            'from' => $from,
                            'type' => 'text',
                            'text' => ['body' => $texto],
                        ]],
                    ],
                ]],
            ]],
        ];
    }

    private function entra(string $from, string $texto, ?string $phoneNumberId = null, ?string $secret = null): TestResponse
    {
        $body = json_encode($this->metaPayload($from, $texto, $phoneNumberId));
        $timestamp = (string) now()->timestamp;
        $firma = hash_hmac('sha256', $timestamp.'.'.$body, $secret ?? self::SECRET);

        return $this->call(
            'POST',
            '/api/webhooks/nexolu-comms/whatsapp',
            [], [], [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_X_NEXOLU_TIMESTAMP' => $timestamp,
                'HTTP_X_NEXOLU_SIGNATURE' => $firma,
            ],
            $body,
        );
    }

    /** El IA Core contesta siempre lo mismo, sin salir a la red. */
    private function ncoreResponde(string $texto = 'Con gusto, ¿para qué día?'): void
    {
        Http::fake([
            'ia-core.test/*' => Http::response([
                'conversation_id' => 'conv-123',
                'text' => $texto,
                'tools_used' => [],
            ]),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | La firma
    |--------------------------------------------------------------------------
    */

    public function test_sin_firma_no_entra(): void
    {
        $this->postJson('/api/webhooks/nexolu-comms/whatsapp', ['entry' => []])
            ->assertStatus(401);
    }

    public function test_una_firma_de_otro_secreto_no_entra(): void
    {
        $this->entra('573001112233', 'Hola', '111222333', 'secreto-equivocado')
            ->assertStatus(401);
    }

    public function test_sin_secreto_configurado_el_webhook_esta_cerrado(): void
    {
        // Un webhook abierto es una vía para escribirle a las clientas a
        // nombre del negocio.
        config()->set('services.comms_core.webhook_secret', '');

        $this->entra('573001112233', 'Hola', '111222333')->assertStatus(401);
    }

    public function test_una_firma_vieja_no_se_puede_reproducir(): void
    {
        $body = json_encode($this->metaPayload('573001112233', 'Hola', '111222333'));
        $viejo = (string) now()->subHour()->timestamp;

        $this->call(
            'POST',
            '/api/webhooks/nexolu-comms/whatsapp',
            [], [], [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_X_NEXOLU_TIMESTAMP' => $viejo,
                'HTTP_X_NEXOLU_SIGNATURE' => hash_hmac('sha256', $viejo.'.'.$body, self::SECRET),
            ],
            $body,
        )->assertStatus(401);
    }

    /*
    |--------------------------------------------------------------------------
    | De quien es la conversacion
    |--------------------------------------------------------------------------
    */

    public function test_el_numero_propio_identifica_al_negocio_sin_codigo(): void
    {
        $this->ncoreResponde();

        $this->entra('573001112233', 'Hola, quiero una cita', '111222333')
            ->assertOk()->assertJsonPath('handled', true);

        $conv = WhatsappConversation::withoutGlobalScopes()->first();
        $this->assertSame($this->luxury->id, $conv->business_id);
    }

    public function test_el_texto_no_puede_cambiar_el_negocio_de_un_numero_propio(): void
    {
        /*
         * La clienta escribe el código de OTRO spa a un número que es de
         * Luxury. El número manda: nada de lo que se escriba puede mover la
         * conversación a otro negocio.
         */
        $this->ncoreResponde();

        $this->entra('573001112233', 'Hola NX-OTR2 quiero cita', '111222333')->assertOk();

        $conv = WhatsappConversation::withoutGlobalScopes()->first();
        $this->assertSame($this->luxury->id, $conv->business_id);
    }

    public function test_por_el_numero_compartido_el_codigo_elige_el_negocio(): void
    {
        $this->ncoreResponde();

        // phone_number_id que no es de nadie: el numero compartido de Nexolu.
        $this->entra('573001112233', 'Hola, quiero agendar en Otro Spa (NX-OTR2)', '999888777')
            ->assertOk()->assertJsonPath('handled', true);

        $conv = WhatsappConversation::withoutGlobalScopes()->first();
        $this->assertSame($this->otro->id, $conv->business_id);
    }

    public function test_sin_codigo_y_sin_conversacion_previa_no_se_contesta(): void
    {
        /*
         * No se sabe de qué negocio habla. Contestar "¿en qué te ayudo?" abre
         * una charla que no puede llegar a ninguna parte.
         */
        $this->ncoreResponde();

        $this->entra('573001112233', 'Hola', '999888777')
            ->assertOk()->assertJsonPath('handled', false);

        $this->assertSame(0, WhatsappConversation::withoutGlobalScopes()->count());
        $this->assertSame(0, Message::withoutGlobalScopes()->count());
    }

    public function test_el_segundo_mensaje_sigue_en_el_mismo_negocio_sin_repetir_el_codigo(): void
    {
        $this->ncoreResponde();

        $this->entra('573001112233', 'Hola (NX-OTR2)', '999888777')->assertOk();
        // Ya sin codigo: el codigo viaja una sola vez.
        $this->entra('573001112233', '¿Tienen para el sábado?', '999888777')
            ->assertOk()->assertJsonPath('handled', true);

        $convs = WhatsappConversation::withoutGlobalScopes()->get();
        $this->assertCount(1, $convs);
        $this->assertSame($this->otro->id, $convs->first()->business_id);
    }

    public function test_tocar_el_enlace_de_otro_spa_cambia_de_conversacion(): void
    {
        $this->ncoreResponde();

        $this->entra('573001112233', 'Hola (NX-OTR2)', '999888777')->assertOk();
        $this->entra('573001112233', 'Hola (NX-LUX1)', '999888777')->assertOk();

        // Dos conversaciones distintas para el mismo telefono, una por negocio.
        $this->assertCount(2, WhatsappConversation::withoutGlobalScopes()->get());
    }

    /*
    |--------------------------------------------------------------------------
    | La respuesta
    |--------------------------------------------------------------------------
    */

    public function test_la_respuesta_del_agente_queda_en_el_outbox(): void
    {
        $this->ncoreResponde('Claro, tenemos a las 10 y a las 11.');

        $this->entra('573001112233', 'Hola', '111222333')->assertOk();

        $mensaje = Message::withoutGlobalScopes()->first();
        $this->assertSame(Message::KIND_AGENT, $mensaje->kind);
        $this->assertSame('Claro, tenemos a las 10 y a las 11.', $mensaje->body);
        $this->assertSame($this->luxury->id, $mensaje->business_id);
    }

    public function test_la_conversacion_del_core_se_recuerda(): void
    {
        $this->ncoreResponde();

        $this->entra('573001112233', 'Hola', '111222333')->assertOk();

        $this->assertSame(
            'conv-123',
            WhatsappConversation::withoutGlobalScopes()->first()->ia_conversation_id,
        );
    }

    public function test_si_el_core_no_responde_no_se_manda_nada(): void
    {
        Http::fake(['ia-core.test/*' => Http::response([], 500)]);

        $this->entra('573001112233', 'Hola', '111222333')
            ->assertOk()->assertJsonPath('handled', false);

        $this->assertSame(0, Message::withoutGlobalScopes()->count());
    }

    public function test_la_ficha_existente_se_engancha_a_la_conversacion(): void
    {
        $carolina = Client::create([
            'business_id' => $this->luxury->id,
            'name' => 'Carolina',
            'phone' => ChannelPhone::normalize('573001112233'),
            'is_active' => true,
        ]);

        $this->ncoreResponde();
        $this->entra('573001112233', 'Hola', '111222333')->assertOk();

        $this->assertSame(
            $carolina->id,
            WhatsappConversation::withoutGlobalScopes()->first()->client_id,
        );
    }

    public function test_lo_que_no_es_texto_se_ignora_sin_error(): void
    {
        /*
         * Meta manda estados de entrega y adjuntos en el mismo sobre.
         * Responder error haría que Communications reintente el evento, y
         * reintentar aquí es volver a contestarle a la clienta.
         */
        $body = json_encode([
            'entry' => [['changes' => [['value' => [
                'metadata' => ['phone_number_id' => '111222333'],
                'statuses' => [['status' => 'delivered']],
            ]]]]],
        ]);
        $timestamp = (string) now()->timestamp;

        $this->call(
            'POST',
            '/api/webhooks/nexolu-comms/whatsapp',
            [], [], [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_X_NEXOLU_TIMESTAMP' => $timestamp,
                'HTTP_X_NEXOLU_SIGNATURE' => hash_hmac('sha256', $timestamp.'.'.$body, self::SECRET),
            ],
            $body,
        )->assertOk()->assertJsonPath('handled', false);

        $this->assertSame(0, Message::withoutGlobalScopes()->count());
    }
}
