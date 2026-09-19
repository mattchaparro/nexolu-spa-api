<?php

namespace Tests\Feature\Ai;

use App\Jobs\AnswerWhatsappMessageJob;
use App\Models\Appointment;
use App\Models\Business;
use App\Models\Message;
use App\Support\PermissionCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Scheduling\SchedulingScenario;
use Tests\TestCase;

/**
 * Mensajes REALES de clientas de Luxury Nails.
 *
 * No son ejemplos inventados: son los que llegan de verdad, con sus
 * comas, sus "peor" por "pero" y sus cuatro mensajes seguidos. Existen
 * como prueba porque probar a mano por WhatsApp no escala -- y porque
 * cada vez que tocamos el prompt hay que saber si algo se rompió ANTES
 * de que lo descubra una clienta.
 *
 * Lo que se verifica acá es lo DETERMINISTA: que el sistema no pierda
 * mensajes, que junte los pedazos, que no conteste dos veces, que no
 * agende sin confirmar. Lo que el modelo *dice* no se puede afirmar en
 * un test, y por eso no se intenta: eso se mira en la evaluación con el
 * modelo real (`ia:evaluar`).
 */
class MensajesRealesTest extends TestCase
{
    use RefreshDatabase, SchedulingScenario;

    private const SECRET = 'secreto-de-comms';

    private const PHONE = '573001112233';

    private Business $business;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-09-21 14:00', 'America/Bogota'));

        PermissionCatalog::sync();
        config()->set('services.comms_core.webhook_secret', self::SECRET);
        config()->set('services.ia_core.api_key', 'llave-ia');
        config()->set('services.ia_core.base_url', 'http://ia-core.test');

        $this->business = $this->makeBusiness(['min_booking_notice_min' => 0]);
        $this->business->forceFill(['whatsapp_phone_number_id' => '111222333'])->save();
    }

    private function entra(string $texto, ?string $wamid = null): TestResponse
    {
        $body = json_encode([
            'entry' => [['changes' => [['value' => [
                'metadata' => ['phone_number_id' => '111222333'],
                'messages' => [[
                    'from' => self::PHONE,
                    'id' => $wamid ?? 'wamid.'.uniqid(),
                    'type' => 'text',
                    'text' => ['body' => $texto],
                ]],
            ]]]]],
        ]);
        $timestamp = (string) now()->timestamp;

        return $this->call('POST', '/api/webhooks/nexolu-comms/whatsapp', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_NEXOLU_TIMESTAMP' => $timestamp,
            'HTTP_X_NEXOLU_SIGNATURE' => hash_hmac('sha256', $timestamp.'.'.$body, self::SECRET),
        ], $body);
    }

    private function ncoreResponde(string $texto = 'Claro, ¿para qué día?'): void
    {
        Http::fake(['ia-core.test/*' => Http::response([
            'conversation_id' => 'conv-1', 'text' => $texto, 'tools_used' => [],
        ])]);
    }

    /*
    |--------------------------------------------------------------------------
    | "Un favor es que requiero una cita para hombre" (y tres mensajes más)
    |--------------------------------------------------------------------------
    */

    public function test_cuatro_mensajes_seguidos_son_un_a_respuesta(): void
    {
        /*
         * El caso real, textual:
         *   "Un favor es que requiero una cita para hombre"
         *   "Peor a las 10. Mañana no me da"
         *   "Mientras me desplazo de la escuela acá al pueblo"
         *   "No sé si se pueda con Angy a las 10 y 30"
         *
         * Cuatro mensajes, UNA idea. Contestar cada pedazo son cuatro
         * respuestas incompletas -- y cuatro llamadas al modelo pagadas.
         */
        Queue::fake();

        $this->entra('Un favor es que requiero una cita para hombre');
        $this->entra('Peor a las 10. Mañana no me da');
        $this->entra('Mientras me desplazo de la escuela acá al pueblo');
        $this->entra('No sé si se pueda con Angy a las 10 y 30');

        // Se programan cuatro trabajos, pero solo el último tiene la marca
        // de tiempo vigente: los otros se retiran al correr.
        Queue::assertPushed(AnswerWhatsappMessageJob::class, 4);

        // Y los cuatro mensajes quedaron en el hilo, ninguno se perdió.
        $this->assertSame(4, Message::withoutGlobalScopes()
            ->where('direction', Message::DIRECTION_IN)->count());
    }

    public function test_solo_el_ultimo_pedazo_contesta_y_lee_todos(): void
    {
        /*
         * En pruebas la cola corre en el acto, así que el retraso no se
         * puede esperar: los trabajos se capturan y se corren a mano, que
         * es exactamente lo que hará el worker cuando venza el retraso.
         */
        $this->ncoreResponde('Con Angy a las 10:30 te queda perfecto.');
        Queue::fake();

        $this->entra('Un favor es que requiero una cita para hombre');
        $this->entra('Peor a las 10. Mañana no me da');
        $this->entra('No sé si se pueda con Angy a las 10 y 30');

        foreach (Queue::pushed(AnswerWhatsappMessageJob::class) as $job) {
            app()->call([$job, 'handle']);
        }

        // UNA sola respuesta, no tres: los tres primeros trabajos se
        // retiran porque entró algo después de ellos.
        $this->assertSame(1, Message::withoutGlobalScopes()
            ->where('kind', Message::KIND_AGENT)->count());

        // Y el modelo recibió los tres pedazos juntos, no el último suelto:
        // sin el primero no sabría que es "para hombre".
        Http::assertSent(function ($request) {
            $mensaje = $request->data()['message'] ?? '';

            return str_contains($mensaje, 'para hombre')
                && str_contains($mensaje, 'Angy a las 10 y 30');
        });
    }

    public function test_lo_que_llega_despues_de_contestar_es_otro_turno(): void
    {
        // Juntar pedazos no puede volverse "le repito todo el historial":
        // lo ya contestado no vuelve a viajar.
        $this->ncoreResponde();
        $this->entra('Buenas tardes');

        $this->entra('¿Tienen para hoy a las 5?');

        $ultima = collect(Http::recorded())->last()[0]->data()['message'] ?? '';
        $this->assertStringContainsString('a las 5', $ultima);
        $this->assertStringNotContainsString('Buenas tardes', $ultima);
    }

    /*
    |--------------------------------------------------------------------------
    | Lo que no puede pasar nunca, venga como venga el mensaje
    |--------------------------------------------------------------------------
    */

    public function test_una_pregunta_de_precios_no_agenda_nada(): void
    {
        /*
         * "Que precio el arreglo de uñas para caballero, arreglo
         * tradicional y semi-permanente". Es una consulta, no una cita:
         * que el bot agende algo acá sería ocupar un cupo que nadie pidió.
         */
        $this->ncoreResponde('El de caballero está en 25.000 COP.');

        $this->entra('Que precio el arreglo de uñas para caballero, tradicional y semi-permanente');

        $this->assertSame(0, Appointment::withoutGlobalScopes()->count());
    }

    public function test_si_el_core_se_cae_la_clienta_no_queda_sin_rastro(): void
    {
        // Lo que escribió queda guardado igual, y alguien del local puede
        // responder desde la bandeja. Es justo cuando la bandeja se gana
        // el sueldo.
        Http::fake(['ia-core.test/*' => Http::response([], 500)]);

        $this->entra('Buenas tardes es posible que me agenden para hoy a las 5 de la tarde o 4:40 pm');

        $this->assertSame(1, Message::withoutGlobalScopes()
            ->where('direction', Message::DIRECTION_IN)->count());
        $this->assertSame(0, Message::withoutGlobalScopes()
            ->where('direction', Message::DIRECTION_OUT)->count());
    }

    public function test_el_mismo_webhook_dos_veces_no_contesta_dos_veces(): void
    {
        /*
         * Communications reintenta si algo tarda. Reintentar acá es volver
         * a escribirle a la clienta -- el peor bug posible en un chat.
         */
        $this->ncoreResponde();

        $this->entra('Hola, buenas tardes', 'wamid.repetido');
        $this->entra('Hola, buenas tardes', 'wamid.repetido');

        $this->assertSame(1, Message::withoutGlobalScopes()
            ->where('kind', Message::KIND_AGENT)->count());
    }
}
