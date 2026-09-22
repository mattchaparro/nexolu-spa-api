<?php

namespace Tests\Feature\Ai;

use App\Ai\DateInText;
use App\Ai\GuidedEntry;
use App\Ai\Toques;
use App\Ai\UltimoPedido;
use App\Models\Business;
use App\Models\Client;
use App\Models\Message;
use App\Models\ServiceCategory;
use App\Models\WhatsappConversation;
use App\Support\ChannelPhone;
use App\Support\PermissionCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Scheduling\SchedulingScenario;
use Tests\TestCase;

/**
 * El primer "quiero una cita" empieza tocando, no chateando.
 *
 * Pasarle la apertura al modelo compraba dos turnos seguros -- "¿qué
 * servicio te gustaría?" y la clienta deletreando un nombre de catálogo.
 * El menú de los más pedidos al primer mensaje es el pedazo de flujo
 * clásico que le faltaba a la puerta; la conversación sigue para todo
 * lo demás.
 */
class GuidedEntryTest extends TestCase
{
    use RefreshDatabase, SchedulingScenario;

    private const PHONE = '573001112233';

    private Business $business;

    private WhatsappConversation $conversacion;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(
            CarbonImmutable::now('America/Bogota')->startOfDay()
                ->previous(CarbonImmutable::WEDNESDAY)->setTime(8, 0),
        );

        PermissionCatalog::sync();
        config()->set('services.comms_core.api_key', 'llave-comms');
        config()->set('services.comms_core.base_url', 'http://comms.test');
        Http::fake([
            'comms.test/*' => Http::response(['results' => [['channel' => 'whatsapp', 'status' => 'sent']]]),
        ]);

        $this->business = $this->makeBusiness(['min_booking_notice_min' => 0]);
        $maria = $this->makeResource($this->business, 'Maria', '09:00:00', '18:00:00');
        $manicure = ServiceCategory::create(['business_id' => $this->business->id, 'name' => 'Manicure', 'is_active' => true]);

        foreach (['Semipermanente', 'Semi + Rubber', 'Tradicional'] as $nombre) {
            $this->makeService($this->business, 60, [$maria], name: $nombre)
                ->update(['service_category_id' => $manicure->id]);
        }

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

        UltimoPedido::olvidar((string) ChannelPhone::normalize(self::PHONE));
    }

    private function entry(): GuidedEntry
    {
        return app(GuidedEntry::class);
    }

    public function test_quiero_una_cita_recibe_el_menu_sin_modelo(): void
    {
        $respuesta = $this->entry()->attend($this->conversacion, 'Hola! Quiero una cita');

        $this->assertSame('', $respuesta['text']);
        $this->assertSame(['menu_inicial'], $respuesta['tools_used']);
        Http::assertSent(fn ($r) => str_contains($r->data()['text'] ?? '', 'los servicios que más nos piden'));

        // El toque siguiente calza contra lo ofrecido.
        $pedido = UltimoPedido::ver((string) ChannelPhone::normalize(self::PHONE));
        $this->assertContains('Semipermanente', $pedido['opciones']);
    }

    public function test_el_menu_mas_la_fecha_dicha_llevan_directo_a_las_horas(): void
    {
        // El flujo completo del arranque: "cita para mañana en la tarde"
        // → menú → toca un servicio → horas de MAÑANA en la TARDE, sin
        // que el modelo participe ni nadie repita nada.
        $phone = (string) ChannelPhone::normalize(self::PHONE);

        DateInText::remember($phone, 'Hola, quiero una cita para mañana en la tarde');
        $this->entry()->attend($this->conversacion, 'Hola, quiero una cita para mañana en la tarde');

        $respuesta = app(Toques::class)->atender($this->conversacion, 'Semipermanente');

        $this->assertSame('', $respuesta['text']);
        $manana = CarbonImmutable::now('America/Bogota')->addDay()->locale('es')->isoFormat('dddd D [de] MMMM');
        Http::assertSent(fn ($r) => str_contains($r->data()['text'] ?? '', 'Para *Semipermanente*')
            && str_contains($r->data()['text'] ?? '', $manana));
    }

    public function test_un_hola_a_secas_es_del_modelo(): void
    {
        $this->assertNull($this->entry()->attend($this->conversacion, 'Hola, buenos días'));
    }

    public function test_si_ya_dijo_el_servicio_va_por_el_camino_normal(): void
    {
        $this->assertNull($this->entry()->attend($this->conversacion, 'Quiero una cita de manicure'));
        $this->assertNull($this->entry()->attend($this->conversacion, 'cita para semipermanente mañana'));
    }

    public function test_quien_pide_el_link_no_recibe_un_menu(): void
    {
        $this->assertNull($this->entry()->attend($this->conversacion, 'Quiero agendar una cita, ¿me mandas el link de la página?'));
    }

    public function test_a_mitad_de_conversacion_no_interrumpe(): void
    {
        Message::create([
            'business_id' => $this->business->id,
            'conversation_id' => $this->conversacion->id,
            'kind' => Message::KIND_AGENT,
            'direction' => Message::DIRECTION_OUT,
            'to' => $this->conversacion->phone,
            'body' => '¡Hola Carolina! ¿En qué te ayudo?',
            'status' => Message::STATUS_SENT,
            'sent_at' => now(),
        ]);

        $this->assertNull($this->entry()->attend($this->conversacion, 'quiero una cita'));
    }

    public function test_horas_despues_vuelve_a_ser_un_arranque(): void
    {
        $mensaje = Message::create([
            'business_id' => $this->business->id,
            'conversation_id' => $this->conversacion->id,
            'kind' => Message::KIND_AGENT,
            'direction' => Message::DIRECTION_OUT,
            'to' => $this->conversacion->phone,
            'body' => 'Tu cita quedó agendada ✅',
            'status' => Message::STATUS_SENT,
            'sent_at' => now(),
        ]);
        $mensaje->forceFill(['created_at' => now()->subHours(7)])->save();

        $this->assertNotNull($this->entry()->attend($this->conversacion, 'hola, quiero otra cita'));
    }
}
