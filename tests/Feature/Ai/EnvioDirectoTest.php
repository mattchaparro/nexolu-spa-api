<?php

namespace Tests\Feature\Ai;

use App\Ai\AiCaller;
use App\Ai\EnvioDirecto;
use App\Jobs\SendMessageJob;
use App\Models\Business;
use App\Models\Client;
use App\Models\Message;
use App\Models\WhatsappConversation;
use App\Support\ChannelPhone;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Scheduling\SchedulingScenario;
use Tests\TestCase;

/**
 * El envío directo no puede ser un único disparo sin red.
 *
 * La confirmación de la cita de las 12:34 de la noche falló -- el canal
 * rechazó el envío -- y Alejandro quedó con una cita agendada que nunca
 * supo que tenía: el fallo no dejaba rastro ni segundo intento. Si el
 * directo falla, el mensaje entra al outbox, que reintenta y, si al
 * final no sale, queda FALLIDO a la vista en la bandeja.
 */
class EnvioDirectoTest extends TestCase
{
    use RefreshDatabase, SchedulingScenario;

    private const PHONE = '573001112233';

    private Business $business;

    private WhatsappConversation $conversacion;

    protected function setUp(): void
    {
        parent::setUp();

        PermissionCatalog::sync();
        config()->set('services.comms_core.api_key', 'llave-comms');
        config()->set('services.comms_core.base_url', 'http://comms.test');

        $this->business = $this->makeBusiness();
        // En modo auto y con canal: el outbox manda solo (no "manual").
        $this->business->forceFill([
            'whatsapp_phone_number_id' => '111222333',
            'messaging_mode' => 'auto',
        ])->save();

        $cliente = Client::create([
            'business_id' => $this->business->id,
            'name' => 'Carolina',
            'phone' => ChannelPhone::normalize(self::PHONE),
            'is_active' => true,
        ]);

        $this->conversacion = WhatsappConversation::withoutGlobalScope('business')->create([
            'business_id' => $this->business->id,
            'phone' => ChannelPhone::normalize(self::PHONE),
            'client_id' => $cliente->id,
            'last_message_at' => now(),
            'last_inbound_at' => now(),
            'status' => WhatsappConversation::STATUS_OPEN,
        ]);
    }

    private function caller(): AiCaller
    {
        return AiCaller::customer(
            $this->business,
            (string) ChannelPhone::normalize(self::PHONE),
            $this->conversacion->client,
            'whatsapp',
        );
    }

    public function test_si_el_canal_rechaza_el_texto_entra_al_outbox(): void
    {
        // El canal rechaza el envío directo.
        Http::fake(['comms.test/*' => Http::response(['error' => 'rechazado'], 422)]);
        Queue::fake();

        $enviado = app(EnvioDirecto::class)->texto($this->caller(), '¡Listo! Quedó agendada ✅');

        // Para quien llama SÍ salió: el outbox lo tiene y reintenta solo.
        $this->assertTrue($enviado);
        Queue::assertPushed(SendMessageJob::class);

        $pendiente = Message::withoutGlobalScopes()
            ->where('conversation_id', $this->conversacion->id)
            ->where('direction', Message::DIRECTION_OUT)
            ->sole();
        $this->assertSame('¡Listo! Quedó agendada ✅', $pendiente->body);
        $this->assertNotSame(Message::STATUS_SENT, $pendiente->status);
    }

    public function test_cuando_el_directo_sale_no_se_encola_nada(): void
    {
        Http::fake(['comms.test/*' => Http::response(['results' => [['channel' => 'whatsapp', 'status' => 'sent']]])]);
        Queue::fake();

        $this->assertTrue(app(EnvioDirecto::class)->texto($this->caller(), 'Hola 👋'));

        Queue::assertNothingPushed();
        $this->assertSame(Message::STATUS_SENT, Message::withoutGlobalScopes()
            ->where('conversation_id', $this->conversacion->id)->sole()->status);
    }
}
