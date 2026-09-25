<?php

namespace Tests\Feature\Ai;

use App\Models\Appointment;
use App\Models\Business;
use App\Models\Client;
use App\Models\Message;
use App\Models\PaymentMethod;
use App\Models\Resource;
use App\Models\ResourceBreak;
use App\Models\Service;
use App\Models\User;
use App\Models\WhatsappConversation;
use App\Services\Scheduling\BookingService;
use App\Services\Scheduling\CheckoutService;
use App\Support\PermissionCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Scheduling\SchedulingScenario;
use Tests\TestCase;

/**
 * El asistente del panel: quien administra le pregunta en palabras.
 *
 * «¿Cuánto vendí hoy?», «¿qué servicios se hicieron más esta semana?»,
 * «¿quiénes escriben y no agendan?», «bloquéale a Alejandra el viernes de 5
 * a 6». Las herramientas son del equipo, nunca de la clienta de WhatsApp.
 */
class PanelAssistantTest extends TestCase
{
    use RefreshDatabase, SchedulingScenario;

    private const KEY = 'llave-de-prueba-del-core';

    private Business $business;

    private Resource $maria;

    private Service $semi;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-09-16 18:00', 'America/Bogota'));

        PermissionCatalog::sync();
        config()->set('services.ia_core.api_key', self::KEY);
        config()->set('services.ia_core.base_url', 'http://ia-core.test');

        $this->business = $this->makeBusiness(['min_booking_notice_min' => 0]);
        $this->maria = $this->makeResource($this->business, 'Maria', '08:00:00', '20:00:00');
        $this->semi = $this->makeService($this->business, 60, [$this->maria], name: 'Semipermanente');
        $this->semi->update(['price' => 50000]);

        $this->admin = User::create([
            'business_id' => $this->business->id, 'name' => 'Ana',
            'email' => 'ana@prueba.test', 'password' => Hash::make('password123'), 'is_active' => true,
        ]);
        PermissionCatalog::applyRole($this->admin, PermissionCatalog::ROLE_ADMIN);
    }

    /** @param array<string, mixed> $arguments */
    private function invoke(string $tool, array $arguments = [], string $channel = 'panel'): TestResponse
    {
        return $this->withHeader('Authorization', 'Bearer '.self::KEY)
            ->postJson('/api/ai/tools/invoke', [
                'tool' => $tool,
                'arguments' => $arguments,
                'context' => [
                    'business_id' => (string) $this->business->id,
                    'user_id' => $channel === 'whatsapp' ? '573001112233' : (string) $this->admin->id,
                    'channel' => $channel,
                ],
            ]);
    }

    private function cobradaHoy(string $hora = '10:00'): Appointment
    {
        $cita = app(BookingService::class)->book(
            $this->business,
            [['service_id' => $this->semi->id, 'resource_id' => $this->maria->id,
                'starts_at' => CarbonImmutable::now('America/Bogota')->setTimeFromTimeString($hora)]],
            null, 'Carolina', null, Appointment::SOURCE_ONLINE, null, false,
        );
        $efectivo = PaymentMethod::withoutGlobalScopes()->firstOrCreate(
            ['business_id' => $this->business->id, 'name' => 'Efectivo'],
            ['counts_as_cash' => true, 'is_active' => true, 'sort_order' => 0],
        );

        return app(CheckoutService::class)->checkout($cita, $efectivo, $this->admin);
    }

    public function test_cuanto_vendi_hoy(): void
    {
        $this->cobradaHoy('10:00');
        $this->cobradaHoy('11:00');

        $r = $this->invoke('resumen_del_dia')->assertOk()->json('data');

        $this->assertEquals(100000, $r['vendido_servicios']);
        $this->assertSame('Maria', $r['por_persona'][0]['nombre']);
        $this->assertSame(2, $r['por_persona'][0]['servicios']);
        $this->assertSame('Página web', $r['citas_agendadas_ese_dia_por_canal'][0]['canal']);
    }

    public function test_los_servicios_mas_hechos_de_la_semana(): void
    {
        $this->cobradaHoy();

        $r = $this->invoke('ventas', ['periodo' => 'esta semana'])->assertOk()->json('data');

        $this->assertSame('Semipermanente', $r['servicios_mas_hechos'][0]['servicio']);
        $this->assertSame(1, $r['servicios_mas_hechos'][0]['veces']);
        $this->assertEquals(50000, $r['totales']['vendido']);
    }

    public function test_la_agenda_de_un_dia(): void
    {
        $this->cobradaHoy('10:00');

        $r = $this->invoke('agenda', ['fecha' => 'hoy', 'persona' => 'Maria'])->assertOk()->json('data');

        $this->assertSame(1, $r['total']);
        $this->assertSame('Carolina', $r['citas'][0]['clienta']);
        $this->assertSame('cobrada', $r['citas'][0]['estado']);
    }

    public function test_las_que_escriben_y_no_agendan(): void
    {
        $escribe = WhatsappConversation::withoutGlobalScope('business')->create([
            'business_id' => $this->business->id, 'phone' => '573005550000',
            'last_message_at' => now(), 'last_inbound_at' => now(), 'status' => WhatsappConversation::STATUS_OPEN,
        ]);
        $agendo = WhatsappConversation::withoutGlobalScope('business')->create([
            'business_id' => $this->business->id, 'phone' => '573006660000',
            'last_message_at' => now(), 'last_inbound_at' => now(), 'status' => WhatsappConversation::STATUS_OPEN,
        ]);

        foreach ([$escribe, $escribe, $escribe, $agendo] as $conv) {
            Message::withoutGlobalScopes()->create([
                'business_id' => $this->business->id, 'conversation_id' => $conv->id,
                'kind' => Message::KIND_INBOUND, 'direction' => Message::DIRECTION_IN,
                'to' => $conv->phone, 'body' => 'Hola, ¿precio del semi?', 'status' => Message::STATUS_SENT,
            ]);
        }

        app(BookingService::class)->book(
            $this->business,
            [['service_id' => $this->semi->id, 'resource_id' => $this->maria->id,
                'starts_at' => CarbonImmutable::now('America/Bogota')->addDay()->setTime(10, 0)]],
            null, 'Ya agendó', '573006660000', Appointment::SOURCE_WHATSAPP_AGENT, null, false,
        );

        $r = $this->invoke('clientas_sin_agendar', ['dias' => 30])->assertOk()->json('data');

        $this->assertSame(['+573005550000'], array_column($r['clientas'], 'telefono'));
        $this->assertSame(3, $r['clientas'][0]['mensajes']);
    }

    public function test_bloquear_un_horario_por_fecha(): void
    {
        $r = $this->invoke('bloquear_horario', [
            'persona' => 'Maria', 'desde' => '2026-09-18', 'hora_inicio' => '17:00', 'hora_fin' => '18:00',
        ])->assertOk()->json('data');

        $this->assertTrue($r['bloqueado']);
        $bloqueo = ResourceBreak::withoutGlobalScopes()->sole();
        $this->assertSame($this->maria->id, $bloqueo->resource_id);
        $this->assertSame('2026-09-18', $bloqueo->effective_from->toDateString());
        $this->assertSame('2026-09-18', $bloqueo->effective_to->toDateString());
        $this->assertNull($bloqueo->weekday);
    }

    public function test_la_clienta_de_whatsapp_no_puede_pedir_reportes(): void
    {
        // Enumeran clientas y plata del negocio: nunca por el bot.
        $this->invoke('resumen_del_dia', [], 'whatsapp')->assertForbidden();
        $this->invoke('clientas_sin_agendar', [], 'whatsapp')->assertForbidden();
    }

    public function test_el_chat_del_panel_le_pregunta_al_agente_administrador(): void
    {
        Http::fake([
            'ia-core.test/v1/chat' => Http::response([
                'conversation_id' => 'conv-1', 'text' => 'Hoy vendiste $100.000.', 'drafts' => [], 'tools_used' => ['resumen_del_dia'],
            ]),
        ]);
        Sanctum::actingAs($this->admin->fresh());

        $this->postJson('/api/v1/assistant/chat', ['message' => '¿Cuánto vendí hoy?'])
            ->assertOk()
            ->assertJsonPath('text', 'Hoy vendiste $100.000.')
            ->assertJsonPath('conversation_id', 'conv-1');

        Http::assertSent(fn ($r) => $r['agent'] === 'administrador'
            && $r['context']['channel'] === 'panel'
            && $r['context']['user_id'] === (string) $this->admin->id
            && in_array('reportes.ver', $r['context']['permissions'], true));
    }

    public function test_el_asistente_es_solo_de_quien_tiene_el_permiso(): void
    {
        $recepcion = User::create([
            'business_id' => $this->business->id, 'name' => 'Sofia',
            'email' => 'sofia@prueba.test', 'password' => Hash::make('password123'), 'is_active' => true,
        ]);
        PermissionCatalog::applyRole($recepcion, PermissionCatalog::ROLE_RECEPTION);
        Sanctum::actingAs($recepcion->fresh());

        $this->postJson('/api/v1/assistant/chat', ['message' => 'hola'])->assertForbidden();
    }
}
