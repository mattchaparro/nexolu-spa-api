<?php

namespace Tests\Feature\Ai;

use App\Ai\Toques;
use App\Models\Appointment;
use App\Models\Business;
use App\Models\Client;
use App\Models\ServiceCategory;
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
 * Lo que la clienta toca no pasa por el modelo.
 *
 * Después de arreglar la memoria del pedido, cinco de ocho clientas
 * simuladas seguían cayéndose al tocar una hora: el modelo no sabía qué
 * hacer con "6:15 pm" y volvía a mandar la misma lista, o preguntaba el
 * servicio otra vez, o decía que ya no había. Un botón tocado es un dato
 * que ya conocemos; el flujo lo atiende en código y el modelo solo entra
 * cuando hay texto libre de verdad.
 */
class ToquesTest extends TestCase
{
    use RefreshDatabase, SchedulingScenario;

    private const KEY = 'llave-de-prueba-del-core';

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
        config()->set('services.ia_core.api_key', self::KEY);
        config()->set('services.comms_core.api_key', 'llave-comms');
        config()->set('services.comms_core.base_url', 'http://comms.test');
        Http::fake([
            'comms.test/*' => Http::response(['results' => [['channel' => 'whatsapp', 'status' => 'sent']]]),
        ]);

        $this->business = $this->makeBusiness(['min_booking_notice_min' => 0]);
        // Dos profesionales con jornadas que no se cruzan, como en el salon
        // de verdad: Maria en la manana, Lucia en la tarde.
        $maria = $this->makeResource($this->business, 'Maria', '09:00:00', '13:00:00');
        $lucia = $this->makeResource($this->business, 'Lucia', '14:00:00', '18:00:00');

        $manicure = ServiceCategory::create(['business_id' => $this->business->id, 'name' => 'Manicure', 'is_active' => true]);

        foreach (['Semipermanente', 'Semi + Rubber', 'Tradicional'] as $nombre) {
            $this->makeService($this->business, 60, [$maria, $lucia], name: $nombre)
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
    }

    /** @param array<string, mixed> $arguments */
    private function invoke(string $tool, array $arguments): TestResponse
    {
        return $this->withHeader('Authorization', 'Bearer '.self::KEY)
            ->postJson('/api/ai/tools/invoke', [
                'tool' => $tool,
                'arguments' => $arguments,
                'context' => [
                    'business_id' => (string) $this->business->id,
                    'user_id' => self::PHONE,
                    'channel' => 'whatsapp',
                ],
            ]);
    }

    private function manana(): string
    {
        return CarbonImmutable::now('America/Bogota')->addDay()->format('Y-m-d');
    }

    /** @return list<array<string, mixed>> */
    private function ultimaLista(): array
    {
        $filas = [];
        Http::assertSent(function ($request) use (&$filas) {
            $opciones = $request->data()['whatsapp_options']['options'] ?? null;
            if ($opciones !== null) {
                $filas = $opciones;
            }

            return true;
        });

        return $filas;
    }

    private function toques(): Toques
    {
        return app(Toques::class);
    }

    public function test_un_texto_cualquiera_le_toca_al_modelo(): void
    {
        $this->assertNull($this->toques()->atender($this->conversacion, 'hola, quiero cita'));
    }

    public function test_tocar_una_hora_pide_confirmacion_con_botones(): void
    {
        $horas = $this->invoke('disponibilidad', ['servicio' => 'Semipermanente', 'fecha' => $this->manana()])
            ->assertOk()->json('data.ofrecidas');

        $respuesta = $this->toques()->atender($this->conversacion, $horas[0]['hora']);

        // Lo atendió el código: sin texto del modelo, con botones.
        $this->assertSame('', $respuesta['text']);
        $this->assertSame([Toques::SI, Toques::OTRA_HORA], array_column($this->ultimaLista(), 'title'));

        Http::assertSent(fn ($r) => str_contains($r->data()['text'] ?? '', 'Te confirmo: *Semipermanente*')
            && str_contains($r->data()['text'] ?? '', $horas[0]['hora']));
    }

    public function test_tocar_si_agenda_exactamente_lo_confirmado(): void
    {
        $horas = $this->invoke('disponibilidad', ['servicio' => 'Semipermanente', 'fecha' => $this->manana()])
            ->json('data.ofrecidas');
        $this->toques()->atender($this->conversacion, $horas[0]['hora']);

        $respuesta = $this->toques()->atender($this->conversacion, Toques::SI);

        $this->assertStringContainsString('Quedó agendada', $respuesta['text']);

        $cita = Appointment::withoutGlobalScopes()->with('items.service')->sole();
        $this->assertSame('Semipermanente', $cita->items->first()->service->name);
        $this->assertSame($horas[0]['hora_24'], $cita->starts_at->timezone('America/Bogota')->format('H:i'));
    }

    public function test_se_agenda_con_quien_de_verdad_esta_libre_a_esa_hora(): void
    {
        /*
         * El bug de produccion que destapo el simulador: la agenda ofrecia
         * "2:30 pm con Anyi" y la reserva, sin que nadie dijera con quien,
         * tomaba a la primera profesional del servicio -- que a esa hora no
         * trabaja -- y fallaba. La clienta tocaba "Si, agendar" y no
         * quedaba nada.
         */
        $horas = $this->invoke('disponibilidad', ['servicio' => 'Semipermanente', 'fecha' => $this->manana()])
            ->json('data.ofrecidas');
        $deLaTarde = collect($horas)->first(fn ($h) => (int) substr($h['hora_24'], 0, 2) >= 14);
        $this->assertNotNull($deLaTarde, 'la agenda tiene que ofrecer alguna hora de la tarde');

        $this->toques()->atender($this->conversacion, $deLaTarde['hora']);
        $respuesta = $this->toques()->atender($this->conversacion, Toques::SI);

        $this->assertStringContainsString('Quedó agendada', $respuesta['text']);
        $cita = Appointment::withoutGlobalScopes()->with('items.resource')->sole();
        $this->assertSame('Lucia', $cita->items->first()->resource->name);
    }

    public function test_si_no_se_pudo_agendar_se_dice_y_no_se_calla(): void
    {
        // Callarse despues de "Si, agendar" deja a la clienta creyendo que
        // agendo. Se ocupa la hora por otro lado y se toca "Si".
        $horas = $this->invoke('disponibilidad', ['servicio' => 'Semipermanente', 'fecha' => $this->manana()])
            ->json('data.ofrecidas');
        $this->toques()->atender($this->conversacion, $horas[0]['hora']);

        // Alguien mas se lleva esa hora antes de que confirme.
        $this->invoke('crear_cita', ['servicio' => 'Semipermanente', 'fecha' => $this->manana(), 'hora' => $horas[0]['hora_24']])
            ->assertJsonPath('data.agendada', true);

        $respuesta = $this->toques()->atender($this->conversacion, Toques::SI);

        $this->assertStringContainsString('No pude dejar esa hora', $respuesta['text']);
        $this->assertSame(1, Appointment::withoutGlobalScopes()->count());
    }

    public function test_tocar_otra_hora_vuelve_a_mandar_las_horas(): void
    {
        $horas = $this->invoke('disponibilidad', ['servicio' => 'Semipermanente', 'fecha' => $this->manana()])
            ->json('data.ofrecidas');
        $this->toques()->atender($this->conversacion, $horas[0]['hora']);

        $respuesta = $this->toques()->atender($this->conversacion, Toques::OTRA_HORA);

        // Otras de verdad: ninguna de las cuatro que ya vio.
        $this->assertSame('', $respuesta['text']);
        $nuevas = array_column($this->ultimaLista(), 'title');
        $this->assertNotEmpty($nuevas);
        $this->assertEmpty(array_intersect($nuevas, array_column($horas, 'hora')));
        $this->assertSame(0, Appointment::withoutGlobalScopes()->count());
    }

    public function test_tocar_un_servicio_de_la_lista_trae_las_horas_del_dia_que_dijo(): void
    {
        // "las manitos" da para tres: se le manda la lista de servicios.
        $this->invoke('disponibilidad', ['servicio' => 'las manitos', 'fecha' => $this->manana()])->assertOk();
        $this->assertContains('Tradicional', array_column($this->ultimaLista(), 'title'));

        $respuesta = $this->toques()->atender($this->conversacion, 'Tradicional');

        // Las horas, sin que nadie repita el día.
        $this->assertSame('', $respuesta['text']);
        Http::assertSent(fn ($r) => str_contains($r->data()['text'] ?? '', 'Para *Tradicional*'));
    }

    public function test_la_misma_consulta_no_vuelve_a_mandar_la_misma_lista(): void
    {
        /*
         * Cuando la clienta tocaba una hora, el modelo volvía a llamar a la
         * agenda con los mismos datos y ella recibía las mismas cuatro horas
         * otra vez, tres veces seguidas.
         */
        $cuantasListas = fn () => Http::recorded()->filter(fn ($par) => isset($par[0]->data()['whatsapp_options']))->count();

        $this->invoke('disponibilidad', ['servicio' => 'Semipermanente', 'fecha' => $this->manana()])->assertOk();
        $listas = $cuantasListas();

        $respuesta = $this->invoke('disponibilidad', ['servicio' => 'Semipermanente', 'fecha' => $this->manana()])->assertOk();

        $this->assertTrue($respuesta->json('data.ya_las_vio'));
        $this->assertSame($listas, $cuantasListas());
    }
}
