<?php

namespace Tests\Feature\Whatsapp;

use App\Ai\ServiciosPendientes;
use App\Ai\UltimoPedido;
use App\Models\Appointment;
use App\Models\Client;
use App\Models\Message;
use App\Models\WhatsappConversation;
use App\Support\ChannelPhone;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Scheduling\SchedulingScenario;
use Tests\TestCase;

/**
 * El «¿te ayudo?» a quien dejó el agendamiento a medias (Claus: tres
 * páginas de catálogo y se fue sin decir nada).
 */
class NudgeAbandonedBookingsTest extends TestCase
{
    use RefreshDatabase, SchedulingScenario;

    private const PHONE = '3154017414';

    private WhatsappConversation $conversacion;

    protected function setUp(): void
    {
        parent::setUp();

        // Un miércoles a las 10 de la mañana en Bogotá: horario para escribirle.
        $this->travelTo(
            CarbonImmutable::now('America/Bogota')->startOfDay()
                ->previous(CarbonImmutable::WEDNESDAY)->setTime(10, 0),
        );

        config()->set('services.comms_core.api_key', 'llave-comms');
        config()->set('services.comms_core.base_url', 'http://comms.test');
        Http::fake([
            'comms.test/*' => Http::response(['results' => [['channel' => 'whatsapp', 'status' => 'sent']]]),
        ]);
        Cache::flush();

        $business = $this->makeBusiness();
        $cliente = Client::create([
            'business_id' => $business->id,
            'name' => 'Claudia',
            'phone' => $this->phone(),
            'is_active' => true,
        ]);

        $this->conversacion = WhatsappConversation::withoutGlobalScope('business')->create([
            'business_id' => $business->id,
            'phone' => $this->phone(),
            'client_id' => $cliente->id,
            'last_message_at' => now()->subMinutes(20),
            'last_inbound_at' => now()->subMinutes(20),
            'status' => WhatsappConversation::STATUS_OPEN,
        ]);

        $this->mensaje(Message::DIRECTION_IN, 'Muéstrame más servicios');
        $this->mensaje(Message::DIRECTION_OUT, 'Estos son los demás 💅');
        ServiciosPendientes::guardar($this->phone(), ['Press-On + Semipermanente']);
    }

    public function test_quien_se_quedo_en_el_catalogo_recibe_un_te_ayudo(): void
    {
        $this->artisan('bot:nudge-abandoned')->assertSuccessful();

        Http::assertSent(fn ($r) => ($r->data()['text'] ?? '') === 'Claudia, ¿pudiste encontrar el servicio que buscabas? '
            .'Si quieres, cuéntame qué te quieres hacer y te ayudo a encontrarlo 😊');

        // Queda en el hilo: su respuesta la lee el bot con este contexto.
        $this->assertSame(
            Message::DIRECTION_OUT,
            Message::withoutGlobalScopes()->where('conversation_id', $this->conversacion->id)->latest('id')->value('direction'),
        );
    }

    public function test_se_le_pregunta_una_sola_vez(): void
    {
        $this->artisan('bot:nudge-abandoned');
        $this->artisan('bot:nudge-abandoned');

        $this->assertSame(1, $this->enviados());
    }

    public function test_antes_de_15_minutos_no_se_le_escribe(): void
    {
        $this->conversacion->update(['last_inbound_at' => now()->subMinutes(10)]);

        $this->artisan('bot:nudge-abandoned');

        $this->assertSame(0, $this->enviados());
    }

    public function test_si_lo_ultimo_lo_dijo_ella_no_se_le_pregunta_encima(): void
    {
        $this->mensaje(Message::DIRECTION_IN, 'déjame pensarlo');

        $this->artisan('bot:nudge-abandoned');

        $this->assertSame(0, $this->enviados());
    }

    public function test_con_una_persona_atendiendo_no_se_mete_el_bot(): void
    {
        $this->conversacion->pauseAgent(60);

        $this->artisan('bot:nudge-abandoned');

        $this->assertSame(0, $this->enviados());
    }

    public function test_sin_nada_a_medias_no_hay_nada_que_preguntar(): void
    {
        ServiciosPendientes::olvidar($this->phone());
        UltimoPedido::olvidar($this->phone());

        $this->artisan('bot:nudge-abandoned');

        $this->assertSame(0, $this->enviados());
    }

    public function test_si_ya_agendo_no_se_le_pregunta(): void
    {
        // Agendó por la web mientras tanto: no hay nada a medias.
        $business = $this->conversacion->business;
        Appointment::create([
            'business_id' => $business->id,
            'location_id' => $business->primaryLocation()?->id,
            'client_id' => $this->conversacion->client_id,
            'client_name' => 'Claudia',
            'client_phone' => $this->phone(),
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addHour(),
            'status' => Appointment::STATUS_CONFIRMED,
        ]);

        $this->artisan('bot:nudge-abandoned');

        $this->assertSame(0, $this->enviados());
    }

    public function test_la_pregunta_depende_de_donde_quedo(): void
    {
        $cmd = \App\Console\Commands\NudgeAbandonedBookings::class;

        $this->assertSame('hours', $cmd::stage(['horas' => ['3 pm' => []]], []));
        $this->assertSame('day', $cmd::stage(['sin_horas' => ['dia' => 'sábado']], []));
        $this->assertSame('confirm', $cmd::stage(['confirmar' => ['hora' => '4 pm']], []));
        $this->assertNull($cmd::stage([], []));
        $this->assertSame('¿Qué día te quedaría bien? Dime y te busco espacio 😊', $cmd::message('day', null));
    }

    private function phone(): string
    {
        return (string) ChannelPhone::normalize(self::PHONE);
    }

    private function mensaje(string $direccion, string $texto): void
    {
        Message::create([
            'business_id' => $this->conversacion->business_id,
            'conversation_id' => $this->conversacion->id,
            'client_id' => $this->conversacion->client_id,
            'kind' => $direccion === Message::DIRECTION_IN ? Message::KIND_INBOUND : Message::KIND_AGENT,
            'direction' => $direccion,
            'to' => $this->phone(),
            'body' => $texto,
            'status' => Message::STATUS_SENT,
            'sent_at' => now(),
        ]);
    }

    private function enviados(): int
    {
        return Http::recorded(fn ($r) => str_contains((string) ($r->data()['text'] ?? ''), '😊'))->count();
    }
}
