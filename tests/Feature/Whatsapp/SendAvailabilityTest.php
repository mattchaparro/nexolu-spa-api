<?php

namespace Tests\Feature\Whatsapp;

use App\Ai\Toques;
use App\Ai\UltimoPedido;
use App\Models\Business;
use App\Models\Client;
use App\Models\Resource;
use App\Models\Service;
use App\Models\User;
use App\Models\WhatsappConversation;
use App\Support\ChannelPhone;
use App\Support\PermissionCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Scheduling\SchedulingScenario;
use Tests\TestCase;

/**
 * Mandarle los horarios a una clienta por WhatsApp (lo que Legacy tenía).
 *
 * Quien administra elige clienta, servicio, día y persona; a ella le llegan
 * las horas libres como botones y, al tocar una, el bot sigue solo.
 */
class SendAvailabilityTest extends TestCase
{
    use RefreshDatabase, SchedulingScenario;

    private const PHONE = '573001112233';

    private Business $business;

    private Resource $marcela;

    private Resource $lucia;

    private Service $semi;

    private Client $carolina;

    private WhatsappConversation $conversacion;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-09-16 08:00', 'America/Bogota'));

        PermissionCatalog::sync();
        config()->set('services.comms_core.api_key', 'llave-comms');
        config()->set('services.comms_core.base_url', 'http://comms.test');
        Http::fake([
            'comms.test/*' => Http::response(['results' => [['channel' => 'whatsapp', 'status' => 'sent']]]),
        ]);

        $this->business = $this->makeBusiness(['min_booking_notice_min' => 0]);
        $this->marcela = $this->makeResource($this->business, 'Marcela', '09:00:00', '13:00:00');
        $this->lucia = $this->makeResource($this->business, 'Lucia', '14:00:00', '18:00:00');
        $this->semi = $this->makeService($this->business, 60, [$this->marcela, $this->lucia], name: 'Semipermanente');

        $this->carolina = Client::create([
            'business_id' => $this->business->id, 'name' => 'Carolina',
            'phone' => ChannelPhone::normalize(self::PHONE), 'is_active' => true,
        ]);
        $this->carolina->forceFill(['name_confirmed_at' => now()])->save();

        $this->conversacion = WhatsappConversation::withoutGlobalScope('business')->create([
            'business_id' => $this->business->id,
            'phone' => ChannelPhone::normalize(self::PHONE),
            'client_id' => $this->carolina->id,
            'last_message_at' => now(),
            'last_inbound_at' => now()->subHours(2),
            'status' => WhatsappConversation::STATUS_OPEN,
        ]);

        $admin = User::create([
            'business_id' => $this->business->id, 'name' => 'Ana', 'email' => 'ana@prueba.test',
            'password' => Hash::make('password123'), 'is_active' => true,
        ]);
        PermissionCatalog::applyRole($admin, PermissionCatalog::ROLE_ADMIN);
        Sanctum::actingAs($admin->fresh());
    }

    private function enviar(array $extra = [])
    {
        return $this->postJson('/api/v1/whatsapp/send-availability', [
            'client_id' => $this->carolina->id,
            'service_id' => $this->semi->id,
            'date' => '2026-09-18',
            ...$extra,
        ]);
    }

    /** Los títulos de los botones que salieron hacia la clienta. */
    private function botonesEnviados(): array
    {
        $titulos = [];
        Http::assertSent(function ($request) use (&$titulos) {
            foreach ($request->data()['whatsapp_options']['options'] ?? [] as $o) {
                $titulos[] = $o['title'];
            }

            return true;
        });

        return $titulos;
    }

    public function test_le_llegan_las_horas_libres_como_botones(): void
    {
        $this->enviar(['resource_id' => $this->marcela->id])
            ->assertOk()
            ->assertJsonPath('sent', true);

        $botones = $this->botonesEnviados();
        $this->assertContains('9:00 am', $botones);
        // Solo las de Marcela: Lucia trabaja de tarde.
        $this->assertNotContains('3:00 pm', $botones);
    }

    public function test_sin_persona_salen_las_de_todas(): void
    {
        $this->enviar()->assertOk();

        $this->assertContains('9:00 am', $this->botonesEnviados());

        // Se muestran unas cuantas, repartidas; las de las dos quedan guardadas
        // para «Otra hora».
        $todas = UltimoPedido::ver((string) ChannelPhone::normalize(self::PHONE))['todas'];
        $this->assertArrayHasKey('2:00 pm', $todas);
        $this->assertSame('Lucia', $todas['2:00 pm']['con']);
    }

    public function test_lo_que_toque_despues_lo_atiende_el_bot_con_ese_servicio_y_ese_dia(): void
    {
        $this->enviar(['resource_id' => $this->marcela->id])->assertOk();

        $pedido = UltimoPedido::ver((string) ChannelPhone::normalize(self::PHONE));
        $this->assertSame(['Semipermanente'], $pedido['servicios']);
        $this->assertSame('2026-09-18', $pedido['fecha_iso']);
        $this->assertSame('Marcela', $pedido['empleado']);

        // Toca una hora: el bot le pide confirmar esa cita, sin volver a preguntar nada.
        $respuesta = app(Toques::class)->atender($this->conversacion->fresh(['business', 'client']), '10:00 am');

        $this->assertNotNull($respuesta);
        Http::assertSent(fn ($r) => str_contains(json_encode($r->data()), '10:00'));
    }

    public function test_pisa_lo_que_la_clienta_estuviera_haciendo(): void
    {
        UltimoPedido::guardar((string) ChannelPhone::normalize(self::PHONE), [
            'servicios' => ['Otro servicio'], 'empleado' => 'Lucia', 'descartadas' => ['Marcela'],
        ]);

        $this->enviar(['resource_id' => $this->marcela->id])->assertOk();

        $pedido = UltimoPedido::ver((string) ChannelPhone::normalize(self::PHONE));
        $this->assertSame(['Semipermanente'], $pedido['servicios']);
        $this->assertArrayNotHasKey('descartadas', $pedido);
    }

    public function test_sin_ventana_de_24_horas_no_manda_nada(): void
    {
        $this->conversacion->update(['last_inbound_at' => now()->subHours(30)]);

        $r = $this->enviar()->assertStatus(409);

        $this->assertStringContainsString('24 horas', $r->json('message'));
        Http::assertNothingSent();
    }

    public function test_si_ese_dia_no_hay_horas_se_lo_dice_a_quien_administra_sin_escribirle_a_la_clienta(): void
    {
        // El domingo no trabaja nadie.
        $r = $this->enviar(['date' => '2026-09-20'])->assertStatus(422);

        $this->assertStringContainsString('No hay horas libres', $r->json('message'));
        Http::assertNothingSent();
    }

    public function test_la_persona_tiene_que_hacer_ese_servicio(): void
    {
        $otra = $this->makeResource($this->business, 'Sofia');

        $this->enviar(['resource_id' => $otra->id])->assertStatus(422);
        Http::assertNothingSent();
    }

    public function test_por_telefono_suelto_tambien_sirve(): void
    {
        $this->enviar(['client_id' => null, 'phone' => '300 111 2233', 'resource_id' => $this->marcela->id])
            ->assertOk();

        $this->assertContains('9:00 am', $this->botonesEnviados());
    }

    public function test_falta_la_clienta(): void
    {
        $this->enviar(['client_id' => null])->assertStatus(422);
    }

    public function test_pide_el_permiso_de_agendar(): void
    {
        $maria = User::create([
            'business_id' => $this->business->id, 'name' => 'Maria', 'email' => 'maria@prueba.test',
            'password' => Hash::make('password123'), 'is_active' => true,
        ]);
        PermissionCatalog::applyRole($maria, PermissionCatalog::ROLE_STAFF);
        Sanctum::actingAs($maria->fresh());

        $this->enviar()->assertForbidden();
    }
}
