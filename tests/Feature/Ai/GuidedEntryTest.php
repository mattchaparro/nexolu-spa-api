<?php

namespace Tests\Feature\Ai;

use App\Ai\DateInText;
use App\Ai\GuidedEntry;
use App\Ai\Toques;
use App\Ai\UltimoPedido;
use App\Models\Appointment;
use App\Models\Business;
use App\Models\Client;
use App\Models\Message;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\WhatsappConversation;
use App\Services\Scheduling\BookingService;
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

    /** @return list<string> */
    private function ultimosBotones(): array
    {
        $titulos = [];
        Http::assertSent(function ($request) use (&$titulos) {
            $opciones = $request->data()['whatsapp_options']['options'] ?? null;
            if ($opciones !== null) {
                $titulos = array_column($opciones, 'title');
            }

            return true;
        });

        return $titulos;
    }

    public function test_quiero_una_cita_recibe_el_iniciador_y_por_aqui_el_menu(): void
    {
        $respuesta = $this->entry()->attend($this->conversacion, 'Hola! Quiero una cita');

        // La puerta que conocen de ManyChat: tres botones.
        $this->assertSame('', $respuesta['text']);
        $this->assertSame([GuidedEntry::HERE, GuidedEntry::WEB, GuidedEntry::OTHER], $this->ultimosBotones());
        Http::assertSent(fn ($r) => str_contains($r->data()['text'] ?? '', '¡Hola, Carolina! 💅 ¿Cómo prefieres agendar?'));

        // «Agendar por aquí» → los más pedidos, tocables.
        $respuesta = $this->entry()->attend($this->conversacion, GuidedEntry::HERE);

        $this->assertSame(['menu_inicial'], $respuesta['tools_used']);
        Http::assertSent(fn ($r) => str_contains($r->data()['text'] ?? '', 'los servicios que más nos piden'));
        $pedido = UltimoPedido::ver((string) ChannelPhone::normalize(self::PHONE));
        $this->assertContains('Semipermanente', $pedido['opciones']);
    }

    public function test_agendar_en_la_web_manda_el_link(): void
    {
        config()->set('spa.public_booking_url', 'https://agenda.test');
        $this->business->forceFill(['slug' => 'luxury'])->save();
        $this->conversacion->refresh();

        $this->entry()->attend($this->conversacion, 'quiero agendar');
        $respuesta = $this->entry()->attend($this->conversacion, GuidedEntry::WEB);

        $this->assertStringContainsString('https://agenda.test/reservar/luxury', $respuesta['text']);
    }

    public function test_otra_consulta_le_abre_la_puerta_a_la_conversacion(): void
    {
        $this->entry()->attend($this->conversacion, 'Hola, quiero una cita');
        $respuesta = $this->entry()->attend($this->conversacion, GuidedEntry::OTHER);

        $this->assertStringContainsString('¿en qué te ayudo?', $respuesta['text']);

        // Lo siguiente que escriba es del modelo.
        $this->assertNull($this->entry()->attend($this->conversacion, '¿tienen parqueadero?'));
    }

    public function test_un_saludo_frio_tambien_recibe_el_iniciador(): void
    {
        $this->entry()->attend($this->conversacion, 'Buenas tardes');

        $this->assertSame([GuidedEntry::HERE, GuidedEntry::WEB, GuidedEntry::OTHER], $this->ultimosBotones());
        Http::assertSent(fn ($r) => str_contains($r->data()['text'] ?? '', '¿En qué te puedo ayudar?'));
    }

    public function test_el_nombre_raro_no_se_usa_en_el_saludo(): void
    {
        $this->conversacion->client->forceFill(['name' => '🦋 Yess 🦋'])->save();
        $this->conversacion->refresh();

        $this->entry()->attend($this->conversacion, 'quiero una cita');

        Http::assertSent(fn ($r) => str_contains($r->data()['text'] ?? '', '¡Hola! 💅 ¿Cómo prefieres agendar?'));
    }

    public function test_el_menu_mas_la_fecha_dicha_llevan_directo_a_las_horas(): void
    {
        // El flujo completo del arranque: "cita para mañana en la tarde"
        // → iniciador → por aquí → menú → toca un servicio → horas de
        // MAÑANA en la TARDE, sin modelo ni nadie repitiendo nada.
        $phone = (string) ChannelPhone::normalize(self::PHONE);

        DateInText::remember($phone, 'Hola, quiero una cita para mañana en la tarde');
        $this->entry()->attend($this->conversacion, 'Hola, quiero una cita para mañana en la tarde');
        $this->entry()->attend($this->conversacion, GuidedEntry::HERE);

        $respuesta = app(Toques::class)->atender($this->conversacion, 'Semipermanente');

        $this->assertSame('', $respuesta['text']);
        $manana = CarbonImmutable::now('America/Bogota')->addDay()->locale('es')->isoFormat('dddd D [de] MMMM');
        Http::assertSent(fn ($r) => str_contains($r->data()['text'] ?? '', 'Para *Semipermanente*')
            && str_contains($r->data()['text'] ?? '', $manana));
    }

    public function test_cambiar_mi_cita_va_directo_a_horas_y_el_si_la_mueve(): void
    {
        /*
         * Laura, tres corridas seguidas: "quiero cambiar mi cita" y el
         * modelo respondía "¿a qué hora te gustaría?" en vez de mirar la
         * agenda. El riel: pedir mover + UNA cita próxima → horas del día
         * que dijo → toque → Sí → reagendar_cita (no crear_cita).
         */
        $phone = (string) ChannelPhone::normalize(self::PHONE);

        // Su cita en pie, mañana a la primera hora del día.
        $cita = app(BookingService::class)->book(
            $this->business,
            [[
                'service_id' => Service::withoutGlobalScope('business')->where('name', 'Semipermanente')->sole()->id,
                'resource_id' => \App\Models\Resource::withoutGlobalScope('business')->where('name', 'Maria')->sole()->id,
                'starts_at' => CarbonImmutable::now('America/Bogota')->addDay()->setTime(9, 0),
            ]],
            $this->conversacion->client,
            'Carolina',
            self::PHONE,
            Appointment::SOURCE_WHATSAPP_AGENT,
            null,
        );
        UltimoPedido::olvidar($phone);

        // Como el job: primero la fecha escrita, después el riel.
        DateInText::remember($phone, 'Hola! Necesito cambiar mi cita de mañana');
        $respuesta = $this->entry()->attend($this->conversacion, 'Hola! Necesito cambiar mi cita de mañana');

        $this->assertSame(['mover_cita'], $respuesta['tools_used']);
        // El encabezado DICE que es la mudanza: sin eso, Laura creyó que
        // el bot no la había entendido y soltó los botones.
        Http::assertSent(fn ($r) => str_contains($r->data()['text'] ?? '', 'movemos tu cita de *Semipermanente*'));

        // Toca una hora nueva y confirma.
        $pedido = UltimoPedido::ver($phone);
        $nueva = array_values($pedido['horas'])[0];
        app(Toques::class)->atender($this->conversacion, $nueva['hora']);
        $respuesta = app(Toques::class)->atender($this->conversacion, Toques::SI);

        // UNA sola cita, movida; el aviso del cambio salió por el canal.
        $this->assertSame('', $respuesta['text']);
        $this->assertSame(['reagendar_cita'], $respuesta['tools_used']);
        $quedo = Appointment::withoutGlobalScopes()->sole();
        $this->assertSame($cita->id, $quedo->id);
        $this->assertSame($nueva['hora_24'], $quedo->starts_at->timezone('America/Bogota')->format('H:i'));
        Http::assertSent(fn ($r) => str_contains($r->data()['text'] ?? '', 'quedó para el'));
    }

    public function test_un_hola_a_secas_es_del_modelo(): void
    {
        // Aun en plena charla, un saludo a secas abre el iniciador: el
        // modelo contestaba dos veces el mismo genérico y eso parecía bucle.
        $this->elAgenteAcabaDeHablar();

        $this->assertNotNull($this->entry()->attend($this->conversacion, 'Hola, buenos días'));
        $this->assertSame([GuidedEntry::HERE, GuidedEntry::WEB, GuidedEntry::OTHER], $this->ultimosBotones());
    }

    private function elAgenteAcabaDeHablar(): void
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

    public function test_pedir_cita_en_plena_charla_si_muestra_el_iniciador(): void
    {
        /*
         * Antes solo salía con seis horas de silencio del bot, y Alejandro,
         * que conversaba seguido, nunca lo vio. Pedir cita de nuevo ES
         * empezar de nuevo: lo que quedaba de la gestión anterior se borra.
         */
        $this->elAgenteAcabaDeHablar();
        $phone = (string) ChannelPhone::normalize(self::PHONE);
        UltimoPedido::guardar($phone, ['servicios' => ['Tradicional'], 'opciones' => ['Tradicional']]);

        $this->assertNotNull($this->entry()->attend($this->conversacion, 'quiero agendar una cita'));

        $pedido = UltimoPedido::ver($phone);
        $this->assertArrayNotHasKey('servicios', $pedido);
        $this->assertTrue($pedido['eligiendo_canal']);
    }

    public function test_con_una_confirmacion_esperando_no_interrumpe(): void
    {
        UltimoPedido::guardar((string) ChannelPhone::normalize(self::PHONE), [
            'servicios' => ['Semipermanente'],
            'confirmar' => ['hora_24' => '10:00', 'hora' => '10 am'],
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
