<?php

namespace Tests\Feature\Whatsapp;

use App\Ai\AiCaller;
use App\Ai\Capabilities\AvailabilityCapability;
use App\Ai\UltimoPedido;
use App\Models\Appointment;
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
 * El formulario nativo de WhatsApp se convierte en cita, con las guardas
 * de siempre.
 *
 * La clienta llena el Flow (servicio, fecha con el selector nativo, hora,
 * nombre) y lo envía; Meta lo entrega como `nfm_reply` y Connect lo
 * reenvía intacto. Antes de esto, el envío de un formulario moría en
 * silencio: nadie atendía ese tipo de mensaje.
 */
class BookingFormTest extends TestCase
{
    use RefreshDatabase, SchedulingScenario;

    private const SECRET = 'secreto-de-comms';

    private const PHONE = '573001112233';

    private Business $luxury;

    private WhatsappConversation $conversacion;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(
            CarbonImmutable::now('America/Bogota')->startOfDay()
                ->previous(CarbonImmutable::WEDNESDAY)->setTime(8, 0),
        );

        PermissionCatalog::sync();
        config()->set('services.comms_core.webhook_secret', self::SECRET);
        config()->set('services.comms_core.api_key', 'llave-comms');
        config()->set('services.comms_core.base_url', 'http://comms.test');
        Http::fake([
            'comms.test/*' => Http::response(['results' => [['channel' => 'whatsapp', 'status' => 'sent']]]),
        ]);

        $this->luxury = $this->makeBusiness(['min_booking_notice_min' => 0]);
        $this->luxury->forceFill(['whatsapp_phone_number_id' => '111222333'])->save();

        $maria = $this->makeResource($this->luxury, 'Maria', '09:00:00', '18:00:00');
        $this->makeService($this->luxury, 60, [$maria], name: 'Semipermanente');

        $cliente = Client::create([
            'business_id' => $this->luxury->id,
            'name' => 'Carolina',
            'phone' => ChannelPhone::normalize(self::PHONE),
            'is_active' => true,
        ]);

        $this->conversacion = WhatsappConversation::withoutGlobalScope('business')->create([
            'business_id' => $this->luxury->id,
            'phone' => ChannelPhone::normalize(self::PHONE),
            'client_id' => $cliente->id,
            'last_message_at' => now(),
            'last_inbound_at' => now(),
            'status' => WhatsappConversation::STATUS_OPEN,
        ]);
    }

    /** @param array<string, mixed> $respuesta */
    private function formulario(array $respuesta, string $wamid = 'wamid.form-1'): TestResponse
    {
        $body = json_encode([
            'entry' => [['changes' => [['value' => [
                'metadata' => ['phone_number_id' => '111222333'],
                'messages' => [[
                    'id' => $wamid,
                    'from' => self::PHONE,
                    'type' => 'interactive',
                    'interactive' => [
                        'type' => 'nfm_reply',
                        'nfm_reply' => [
                            'name' => 'flow',
                            'response_json' => json_encode($respuesta),
                        ],
                    ],
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

    private function manana(): CarbonImmutable
    {
        return CarbonImmutable::now('America/Bogota')->addDay();
    }

    public function test_con_el_flow_publicado_las_horas_llegan_como_formulario(): void
    {
        config()->set('spa.whatsapp_booking_flow_id', '1624992425831128');

        $caller = AiCaller::customer(
            $this->luxury,
            (string) ChannelPhone::normalize(self::PHONE),
            $this->conversacion->client,
            'whatsapp',
        );

        $resultado = app(AvailabilityCapability::class)->execute($caller, [
            'servicio' => 'Semipermanente',
            'fecha' => $this->manana()->format('Y-m-d'),
        ]);

        $this->assertNotEmpty($resultado['ofrecidas']);
        $primera = $resultado['ofrecidas'][0]['hora_24'];

        // Salió el formulario pre-cargado, no la lista de botones.
        Http::assertSent(function ($request) use ($primera) {
            $flow = $request->data()['whatsapp_flow'] ?? null;

            return $flow !== null
                && $flow['flow_id'] === '1624992425831128'
                && $flow['screen'] === 'CONFIRMAR'
                && str_contains($flow['data']['resumen'], 'Semipermanente')
                && $flow['data']['nombre'] === 'Carolina'
                && collect($flow['data']['horas'])->contains(fn ($h) => $h['id'] === $primera);
        });
        Http::assertNotSent(fn ($r) => isset($r->data()['whatsapp_options']));

        // Y el envío de vuelta agenda (el círculo completo).
        $this->formulario([
            'pedido' => 'cita', 'servicio' => 'Semipermanente',
            'fecha' => $this->manana()->format('Y-m-d'), 'hora' => $primera,
        ])->assertOk();
        $this->assertSame(1, Appointment::withoutGlobalScopes()->count());
    }

    public function test_una_mudanza_no_va_por_el_formulario(): void
    {
        // El nfm_reply termina en crear_cita: mover debe seguir por los
        // botones, cuyo Sí ejecuta reagendar_cita.
        config()->set('spa.whatsapp_booking_flow_id', '1624992425831128');

        $phone = (string) ChannelPhone::normalize(self::PHONE);
        UltimoPedido::guardar($phone, ['mudanza' => ['id' => 99]]);

        $caller = AiCaller::customer($this->luxury, $phone, $this->conversacion->client, 'whatsapp');
        app(AvailabilityCapability::class)->execute($caller, [
            'servicio' => 'Semipermanente',
            'fecha' => $this->manana()->format('Y-m-d'),
        ]);

        Http::assertNotSent(fn ($r) => isset($r->data()['whatsapp_flow']));
        Http::assertSent(fn ($r) => isset($r->data()['whatsapp_options']));

        UltimoPedido::olvidar($phone);
    }

    public function test_el_formulario_enviado_se_vuelve_cita_y_se_confirma(): void
    {
        $respuesta = $this->formulario([
            'pedido' => 'cita',
            'servicio' => 'Semipermanente',
            // El DatePicker nativo entrega epoch en MILISEGUNDOS.
            'fecha' => (string) ($this->manana()->startOfDay()->getTimestamp() * 1000),
            'hora' => '10:00',
            'nombre' => '',
            'para_quien' => '',
        ]);

        $respuesta->assertOk()->assertJsonPath('agent', 'form');

        $cita = Appointment::withoutGlobalScopes()->with('items.service')->sole();
        $this->assertSame('Semipermanente', $cita->items->first()->service->name);
        $this->assertSame(
            $this->manana()->format('Y-m-d').' 10:00',
            $cita->starts_at->timezone('America/Bogota')->format('Y-m-d H:i'),
        );

        // La confirmación salió por el canal y el hilo registró el envío.
        Http::assertSent(fn ($r) => str_contains($r->data()['text'] ?? '', 'Quedó agendada'));
        $this->assertTrue(Message::withoutGlobalScopes()
            ->where('direction', Message::DIRECTION_IN)
            ->where('body', 'like', '%formulario%')->exists());
    }

    public function test_el_nombre_del_formulario_corrige_la_ficha(): void
    {
        // La ficha traía el nombre del perfil de WhatsApp; el formulario
        // es donde ella escribe el suyo de verdad.
        $this->conversacion->client->forceFill(['name' => '🦋 Yess 🦋'])->save();

        $this->formulario([
            'pedido' => 'cita',
            'servicio' => 'Semipermanente',
            'fecha' => $this->manana()->format('Y-m-d'),
            'hora' => '11:00',
            'nombre' => 'Yesica',
            'para_quien' => 'Mi mamá Rosa',
        ])->assertOk();

        $this->assertSame('Yesica', $this->conversacion->client->fresh()->name);
        $cita = Appointment::withoutGlobalScopes()->sole();
        $this->assertStringContainsString('Rosa', (string) $cita->notes);
    }

    public function test_si_la_hora_se_ocupo_se_dice_y_no_se_calla(): void
    {
        // Alguien se lleva las 10 am antes de que el formulario llegue.
        $this->formulario([
            'pedido' => 'cita', 'servicio' => 'Semipermanente',
            'fecha' => $this->manana()->format('Y-m-d'), 'hora' => '10:00',
        ], 'wamid.otro')->assertOk();

        Http::assertSent(fn ($r) => str_contains($r->data()['text'] ?? '', 'Quedó agendada'));

        // El mismo formulario de otra clienta... aquí, la misma con otra
        // hora ocupada: el sistema avisa en vez de callarse.
        $this->formulario([
            'pedido' => 'cita', 'servicio' => 'Semipermanente',
            'fecha' => $this->manana()->format('Y-m-d'), 'hora' => '10:00',
        ], 'wamid.repite')->assertOk();

        // Misma clienta + misma cita exacta = "ya la tienes", no dos citas.
        $this->assertSame(1, Appointment::withoutGlobalScopes()->count());
        Http::assertSent(fn ($r) => str_contains($r->data()['text'] ?? '', 'ya la tienes agendada'));
    }

    public function test_un_formulario_ajeno_no_agenda_nada(): void
    {
        $this->formulario(['encuesta' => '5 estrellas'])->assertOk()->assertJsonPath('form', 'ajeno');

        $this->assertSame(0, Appointment::withoutGlobalScopes()->count());
    }

    public function test_el_mismo_formulario_dos_veces_agenda_una(): void
    {
        $carga = [
            'pedido' => 'cita', 'servicio' => 'Semipermanente',
            'fecha' => $this->manana()->format('Y-m-d'), 'hora' => '14:00',
        ];

        $this->formulario($carga, 'wamid.duplicado')->assertOk();
        $this->formulario($carga, 'wamid.duplicado')->assertOk()->assertJsonPath('duplicado', true);

        $this->assertSame(1, Appointment::withoutGlobalScopes()->count());
    }
}
