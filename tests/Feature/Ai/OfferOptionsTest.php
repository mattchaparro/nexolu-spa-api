<?php

namespace Tests\Feature\Ai;

use App\Ai\OpcionesEnviadas;
use App\Models\Business;
use App\Models\Client;
use App\Models\Message;
use App\Models\WhatsappConversation;
use App\Support\ChannelPhone;
use App\Support\PermissionCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Scheduling\SchedulingScenario;
use Tests\TestCase;

/**
 * Opciones que se TOCAN.
 *
 * El agente escribía "tengo a las 10, a la 1 y a las 5" y esperaba que
 * alguien transcribiera una. A la gente le da pereza leer y más pereza
 * escribir: ahí es donde se pierde la cita.
 */
class OfferOptionsTest extends TestCase
{
    use RefreshDatabase, SchedulingScenario;

    private const KEY = 'llave-de-prueba-del-core';

    private const PHONE = '573001112233';

    private Business $business;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-09-19 10:00', 'America/Bogota'));

        PermissionCatalog::sync();
        config()->set('services.ia_core.api_key', self::KEY);
        config()->set('services.comms_core.api_key', 'llave-comms');
        config()->set('services.comms_core.base_url', 'http://comms.test');

        $this->business = $this->makeBusiness();
    }

    /** @param array<string, mixed> $arguments */
    private function invoke(array $arguments): TestResponse
    {
        return $this->withHeader('Authorization', 'Bearer '.self::KEY)
            ->postJson('/api/ai/tools/invoke', [
                'tool' => 'ofrecer_opciones',
                'arguments' => $arguments,
                'context' => [
                    'business_id' => (string) $this->business->id,
                    'user_id' => self::PHONE,
                    'channel' => 'whatsapp',
                ],
            ]);
    }

    private function commsResponde(): void
    {
        Http::fake([
            'comms.test/*' => Http::response(['results' => [['channel' => 'whatsapp', 'status' => 'sent']]]),
        ]);
    }

    public function test_las_opciones_salen_como_botones_tocables(): void
    {
        $this->commsResponde();

        $this->invoke([
            'mensaje' => '¿Cuál hora te sirve?',
            'opciones' => ['10 am', '1 pm', '5 pm'],
        ])->assertOk()->assertJsonPath('data.mostrado', true);

        Http::assertSent(function ($request) {
            $body = $request->data();
            $titulos = array_column($body['whatsapp_options']['options'], 'title');

            return $body['text'] === '¿Cuál hora te sirve?'
                && $titulos === ['10 am', '1 pm', '5 pm']
                // Es una respuesta dentro de la conversación, no una plantilla.
                && $body['category'] === 'service';
        });
    }

    public function test_al_agente_se_le_dice_que_no_repita_el_mensaje(): void
    {
        /*
         * Sin esto la clienta recibe la lista y, debajo, las mismas horas
         * escritas: el mensaje duplicado es peor que no tener botones.
         */
        $this->commsResponde();

        $instruccion = $this->invoke([
            'mensaje' => '¿Cuál te sirve?',
            'opciones' => ['10 am'],
        ])->json('data.instruccion');

        $this->assertStringContainsString('cadena vacía', $instruccion);
    }

    public function test_lo_ofrecido_queda_en_el_hilo_del_spa(): void
    {
        // Si no, quien abre la bandeja ve la respuesta "3 pm" de la clienta
        // sin entender de dónde salió.
        $this->commsResponde();

        $cliente = Client::create([
            'business_id' => $this->business->id,
            'name' => 'Carolina',
            'phone' => ChannelPhone::normalize(self::PHONE),
            'is_active' => true,
        ]);
        WhatsappConversation::withoutGlobalScope('business')->create([
            'business_id' => $this->business->id,
            'phone' => ChannelPhone::normalize(self::PHONE),
            'client_id' => $cliente->id,
            'last_message_at' => now(),
            'status' => WhatsappConversation::STATUS_OPEN,
        ]);

        $this->invoke(['mensaje' => '¿Cuál hora?', 'opciones' => ['10 am', '3 pm']])->assertOk();

        $mensaje = Message::withoutGlobalScopes()->where('direction', Message::DIRECTION_OUT)->first();
        $this->assertStringContainsString('¿Cuál hora?', $mensaje->body);
        $this->assertStringContainsString('▸ 3 pm', $mensaje->body);
        // Ya salió por Connect: si naciera pendiente, el outbox lo repetiría.
        $this->assertSame(Message::STATUS_SENT, $mensaje->status);
    }

    public function test_un_titulo_demasiado_largo_se_rechaza_con_un_error_entendible(): void
    {
        // Meta corta en 24 caracteres; mejor un 422 claro que un rechazo
        // opaco de la Cloud API a mitad de conversación.
        $this->commsResponde();

        $this->invoke([
            'mensaje' => '¿Cuál?',
            'opciones' => ['Recubrimiento de acrílico con semipermanente y decoración'],
        ])->assertStatus(422);
    }

    public function test_si_connect_no_las_entrega_el_agente_las_escribe(): void
    {
        Http::fake(['comms.test/*' => Http::response([], 500)]);

        $this->invoke(['mensaje' => '¿Cuál?', 'opciones' => ['10 am']])
            ->assertOk()
            ->assertJsonPath('data.mostrado', false)
            ->assertJsonPath('data.instruccion', 'No pude mostrar opciones. Escríbelas en el texto de tu respuesta.');
    }

    public function test_no_manda_una_segunda_lista_en_el_mismo_turno(): void
    {
        /*
         * Pasó en producción: `disponibilidad` mostró las horas como
         * botones y el modelo, además, llamó acá con las mismas. La
         * clienta recibió la misma lista dos veces seguidas. Que no se
         * repita no puede depender de que el modelo lea la instrucción.
         */
        $this->commsResponde();
        OpcionesEnviadas::marcar(self::PHONE);

        $this->invoke(['mensaje' => '¿Cuál hora?', 'opciones' => ['10 am', '3 pm']])
            ->assertOk()
            ->assertJsonPath('data.mostrado', false);

        Http::assertNothingSent();
    }
}
