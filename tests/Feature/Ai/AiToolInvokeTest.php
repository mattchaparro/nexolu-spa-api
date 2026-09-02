<?php

namespace Tests\Feature\Ai;

use App\Models\Appointment;
use App\Models\Business;
use App\Models\Client;
use App\Models\Location;
use App\Models\Resource;
use App\Models\Service;
use App\Models\User;
use App\Services\Scheduling\BookingService;
use App\Support\ChannelPhone;
use App\Support\PermissionCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Scheduling\SchedulingScenario;
use Tests\TestCase;

/**
 * El despachador de herramientas del agente de WhatsApp.
 *
 * Lo que se defiende aca, en orden de gravedad:
 *
 *  1. Que una CLIENTA no pueda sacar datos de otras clientas ni del negocio.
 *     Es el miedo explicito del dueno -- "son mis clientes y podría robarse
 *     los datos" -- y con un numero de WhatsApp compartido entre negocios,
 *     la unica barrera es esta.
 *  2. Que el `context` del cuerpo se revalide siempre: viene firmado por la
 *     API key del Core, lo que prueba de donde viene, no que sea cierto.
 *  3. Que agendar pase por BookingService y respete el anti-solape.
 */
class AiToolInvokeTest extends TestCase
{
    use RefreshDatabase, SchedulingScenario;

    private const KEY = 'llave-de-prueba-del-core';

    private Business $business;

    private Resource $maria;

    private Service $manicure;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(
            CarbonImmutable::now('America/Bogota')->startOfDay()
                ->previous(CarbonImmutable::WEDNESDAY)->setTime(8, 0),
        );

        PermissionCatalog::sync();
        config()->set('services.ia_core.api_key', self::KEY);

        $this->business = $this->makeBusiness(['min_booking_notice_min' => 0]);
        $this->maria = $this->makeResource($this->business, 'Maria');
        $this->manicure = $this->makeService($this->business, 60, [$this->maria]);
        $this->manicure->update(['name' => 'Manicure clasico', 'price' => 45000, 'is_bookable_online' => true]);
    }

    /** @param  array<string, mixed>  $arguments */
    private function invoke(string $tool, array $arguments = [], array $context = []): TestResponse
    {
        return $this->withHeader('Authorization', 'Bearer '.self::KEY)
            ->postJson('/api/ai/tools/invoke', [
                'tool' => $tool,
                'arguments' => $arguments,
                'context' => array_merge([
                    'business_id' => (string) $this->business->id,
                    'user_id' => '+573001112233',
                    'channel' => 'whatsapp',
                ], $context),
            ]);
    }

    /**
     * Una ficha como las que existen de verdad: el telefono guardado en el
     * formato canonico (digitos, sin '+'), que es lo que hacen tanto el panel
     * como la reserva publica al pasar por ChannelPhone::normalize.
     */
    private function clienta(string $nombre, string $telefono): Client
    {
        return Client::create([
            'business_id' => $this->business->id,
            'name' => $nombre,
            'phone' => ChannelPhone::normalize($telefono, $this->business->country_code),
            'is_active' => true,
        ]);
    }

    private function manana(): CarbonImmutable
    {
        return CarbonImmutable::now('America/Bogota')->addDay()->startOfDay();
    }

    /*
    |--------------------------------------------------------------------------
    | La puerta
    |--------------------------------------------------------------------------
    */

    public function test_sin_la_llave_del_core_no_se_entra(): void
    {
        $this->postJson('/api/ai/tools/invoke', ['tool' => 'servicios'])->assertStatus(401);

        $this->withHeader('Authorization', 'Bearer otra-cosa')
            ->postJson('/api/ai/tools/invoke', ['tool' => 'servicios'])
            ->assertStatus(401);
    }

    public function test_sin_llave_configurada_el_endpoint_no_existe(): void
    {
        // Un despliegue a medio configurar no puede quedar abierto.
        config()->set('services.ia_core.api_key', null);

        $this->withHeader('Authorization', 'Bearer '.self::KEY)
            ->postJson('/api/ai/tools/invoke', ['tool' => 'servicios'])
            ->assertStatus(401);
    }

    public function test_una_herramienta_desconocida_es_404(): void
    {
        $this->invoke('borrar_todo')->assertStatus(404);
    }

    /*
    |--------------------------------------------------------------------------
    | Lo que una clienta NO puede
    |--------------------------------------------------------------------------
    */

    public function test_una_clienta_no_puede_enumerar_los_clientes_del_negocio(): void
    {
        /*
         * El Core declara la herramienta `clientes`; el Spa no la implementa
         * a proposito. Que responda 404 es la prueba de que la base de
         * clientas no sale por el chat.
         */
        $this->invoke('clientes', ['query' => 'a'])->assertStatus(404);
    }

    public function test_una_clienta_no_ve_las_citas_de_otra(): void
    {
        $carolina = $this->clienta('Carolina', '+573001112233');
        $lucia = $this->clienta('Lucia', '+573009998877');

        $this->booking()->book(
            $this->business,
            [['service_id' => $this->manicure->id, 'resource_id' => $this->maria->id, 'starts_at' => $this->manana()->setTime(10, 0)]],
            $lucia,
        );

        // Carolina pregunta por "sus" citas: la de Lucia no aparece.
        $citas = $this->invoke('mis_citas', [], ['user_id' => $carolina->phone])
            ->assertOk()->json('data.citas');

        $this->assertSame([], $citas);
    }

    public function test_una_clienta_no_cancela_la_cita_de_otra(): void
    {
        $lucia = $this->clienta('Lucia', '+573009998877');
        $this->clienta('Carolina', '+573001112233');

        $cita = $this->booking()->book(
            $this->business,
            [['service_id' => $this->manicure->id, 'resource_id' => $this->maria->id, 'starts_at' => $this->manana()->setTime(10, 0)]],
            $lucia,
        );

        $r = $this->invoke('cancelar_cita', ['cita_id' => $cita->id], ['user_id' => '+573001112233'])
            ->assertOk();

        $this->assertFalse($r->json('data.cancelada'));
        $this->assertSame(Appointment::STATUS_PENDING, $cita->fresh()->status);
    }

    public function test_una_clienta_no_toca_una_cita_de_otro_negocio(): void
    {
        $otro = $this->makeBusiness();
        $suRecurso = $this->makeResource($otro, 'Ajena');
        $suServicio = $this->makeService($otro, 60, [$suRecurso]);

        $cita = $this->booking()->book(
            $otro,
            [['service_id' => $suServicio->id, 'resource_id' => $suRecurso->id, 'starts_at' => $this->manana()->setTime(10, 0)]],
            null,
            'Ajena',
            '+573001112233',
        );

        // Mismo telefono, otro negocio: la cita no existe para esta conversacion.
        $r = $this->invoke('cancelar_cita', ['cita_id' => $cita->id])->assertOk();

        $this->assertFalse($r->json('data.cancelada'));
        $this->assertSame(Appointment::STATUS_PENDING, $cita->fresh()->status);
    }

    public function test_un_negocio_inexistente_es_contexto_invalido(): void
    {
        $this->invoke('servicios', [], ['business_id' => '999999'])->assertStatus(422);
    }

    public function test_un_usuario_que_no_es_del_negocio_no_pasa(): void
    {
        $otro = $this->makeBusiness();
        $ajeno = User::create([
            'business_id' => $otro->id,
            'name' => 'Ajeno',
            'email' => 'ajeno@prueba.test',
            'password' => Hash::make('password123'),
            'is_active' => true,
        ]);

        $this->invoke('servicios', [], ['channel' => 'web', 'user_id' => (string) $ajeno->id])
            ->assertStatus(422);
    }

    /*
    |--------------------------------------------------------------------------
    | Lo que si puede
    |--------------------------------------------------------------------------
    */

    public function test_el_catalogo_sale_con_precio_y_duracion(): void
    {
        $servicios = $this->invoke('servicios')->assertOk()->json('data.servicios');

        $this->assertSame('Manicure clasico', $servicios[0]['nombre']);
        $this->assertEquals(45000, $servicios[0]['precio']);
    }

    public function test_la_disponibilidad_sale_del_motor_de_verdad(): void
    {
        $r = $this->invoke('disponibilidad', [
            'servicio' => 'manicure clasico',
            'fecha' => $this->manana()->toDateString(),
        ])->assertOk();

        $this->assertNotEmpty($r->json('data.horas'));
        $this->assertSame('Maria', $r->json('data.horas.0.con'));
    }

    public function test_un_servicio_que_no_existe_devuelve_las_opciones(): void
    {
        // Para que el agente pregunte en vez de inventar.
        $r = $this->invoke('disponibilidad', [
            'servicio' => 'masaje tailandés',
            'fecha' => $this->manana()->toDateString(),
        ])->assertStatus(422);

        $this->assertStringContainsString('Manicure clasico', $r->json('error'));
    }

    public function test_agendar_crea_la_cita_a_nombre_del_telefono_que_escribe(): void
    {
        $carolina = $this->clienta('Carolina', '+573001112233');

        $r = $this->invoke('crear_cita', [
            'servicio' => 'Manicure clasico',
            'fecha' => $this->manana()->toDateString(),
            'hora' => '10:00',
            // El modelo dice otro nombre: da igual, la cita es de quien escribe.
            'cliente' => 'Otra Persona',
        ])->assertOk();

        $this->assertTrue($r->json('data.agendada'));

        $cita = Appointment::withoutGlobalScopes()->find($r->json('data.id'));
        $this->assertSame($carolina->id, $cita->client_id);
        $this->assertSame(Appointment::SOURCE_WHATSAPP_AGENT, $cita->source);
    }

    public function test_agendar_a_una_clienta_nueva_le_crea_la_ficha(): void
    {
        $r = $this->invoke('crear_cita', [
            'servicio' => 'Manicure clasico',
            'fecha' => $this->manana()->toDateString(),
            'hora' => '10:00',
            'cliente' => 'Valentina',
        ])->assertOk();

        $this->assertTrue($r->json('data.agendada'));
        // Guardada con el teléfono canónico, no como llegó del canal.
        $this->assertNotNull(
            Client::withoutGlobalScopes()->where('phone', '573001112233')->first(),
        );
    }

    public function test_una_hora_ya_tomada_no_se_agenda_dos_veces(): void
    {
        $hora = $this->manana()->setTime(10, 0);

        $this->booking()->book(
            $this->business,
            [['service_id' => $this->manicure->id, 'resource_id' => $this->maria->id, 'starts_at' => $hora]],
            $this->clienta('Lucia', '+573009998877'),
        );

        $r = $this->invoke('crear_cita', [
            'servicio' => 'Manicure clasico',
            'fecha' => $this->manana()->toDateString(),
            'hora' => '10:00',
            'cliente' => 'Carolina',
        ])->assertOk();

        // No es un error de sistema: es lo que el agente le dice a la clienta.
        $this->assertFalse($r->json('data.agendada'));
        $this->assertStringContainsString('ocupó', $r->json('data.motivo'));
    }

    public function test_su_propia_cita_si_la_cancela(): void
    {
        $carolina = $this->clienta('Carolina', '+573001112233');

        $cita = $this->booking()->book(
            $this->business,
            [['service_id' => $this->manicure->id, 'resource_id' => $this->maria->id, 'starts_at' => $this->manana()->setTime(10, 0)]],
            $carolina,
        );

        $r = $this->invoke('cancelar_cita', ['cita_id' => $cita->id])->assertOk();

        $this->assertTrue($r->json('data.cancelada'));
        $this->assertSame(Appointment::STATUS_CANCELLED, $cita->fresh()->status);
    }

    public function test_con_varias_sedes_pregunta_a_cual(): void
    {
        Location::create([
            'business_id' => $this->business->id,
            'name' => 'Cedritos', 'slug' => 'cedritos',
            'is_primary' => false, 'is_active' => true,
        ]);

        $r = $this->invoke('disponibilidad', [
            'servicio' => 'Manicure clasico',
            'fecha' => $this->manana()->toDateString(),
        ])->assertStatus(422);

        $this->assertStringContainsString('sede', $r->json('error'));
    }

    public function test_el_catalogo_de_herramientas_dice_que_exige_cada_una(): void
    {
        $tools = $this->withHeader('Authorization', 'Bearer '.self::KEY)
            ->getJson('/api/ai/tools/catalog')->assertOk()->json('tools');

        $this->assertTrue($tools['disponibilidad']['allows_customers']);
        $this->assertSame('online_booking', $tools['disponibilidad']['required_feature']);
        $this->assertArrayNotHasKey('clientes', $tools);
    }

    private function booking(): BookingService
    {
        return $this->app->make(BookingService::class);
    }
}
