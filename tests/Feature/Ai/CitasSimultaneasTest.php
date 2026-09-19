<?php

namespace Tests\Feature\Ai;

use App\Models\Appointment;
use App\Models\Business;
use App\Models\Client;
use App\Models\Resource;
use App\Models\Service;
use App\Support\ChannelPhone;
use App\Support\PermissionCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Scheduling\SchedulingScenario;
use Tests\TestCase;

/**
 * Dos personas a la MISMA hora: la señora y su hija, ella y su esposo.
 *
 * Es de los pedidos que más plata mueven y el bot no podía con ellos:
 * agendaba una sola cita y dejaba a alguien sin puesto, o decía que no se
 * podía cuando sí.
 *
 * Distinto de una cadena ("manos y pies"), que es UNA persona pasando de
 * un servicio al siguiente. Acá hay dos clientas sentadas al tiempo, y
 * por eso hacen falta dos profesionales libres a la vez.
 */
class CitasSimultaneasTest extends TestCase
{
    use RefreshDatabase, SchedulingScenario;

    private const KEY = 'llave-de-prueba-del-core';

    private const PHONE = '573001112233';

    private Business $business;

    private Resource $maria;

    private Resource $lucia;

    private Service $manicure;

    private Service $pedicure;

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
        $this->lucia = $this->makeResource($this->business, 'Lucia');

        // Las dos hacen los dos servicios: el caso normal de un salón.
        $this->manicure = $this->makeService($this->business, 60, [$this->maria, $this->lucia], name: 'Manicure');
        $this->pedicure = $this->makeService($this->business, 60, [$this->maria, $this->lucia], name: 'Pedicure');

        Client::create([
            'business_id' => $this->business->id,
            'name' => 'Carolina',
            'phone' => ChannelPhone::normalize(self::PHONE),
            'is_active' => true,
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

    public function test_ofrece_solo_las_horas_donde_caben_las_dos(): void
    {
        $horas = $this->invoke('disponibilidad', [
            'servicios' => ['Manicure', 'Pedicure'],
            'fecha' => $this->manana(),
            'juntas' => true,
        ])->assertOk()->json('data.horas');

        $this->assertNotEmpty($horas);
        // Con dos profesionales, la hora sirve y se dice con quiénes.
        $this->assertStringContainsString(' y ', $horas[0]['con']);
    }

    public function test_con_una_sola_profesional_no_hay_horas_para_dos(): void
    {
        /*
         * Dos personas no caben en una silla. Antes el bot ofrecía la hora
         * igual -- estaba libre "para una" -- y al llegar solo cabía una.
         */
        $this->lucia->update(['is_active' => false]);

        $respuesta = $this->invoke('disponibilidad', [
            'servicios' => ['Manicure', 'Pedicure'],
            'fecha' => $this->manana(),
            'juntas' => true,
        ])->assertOk();

        $this->assertSame([], $respuesta->json('data.horas'));
        $this->assertStringContainsString(
            'profesionales libres al tiempo',
            $respuesta->json('data.instruccion'),
        );
    }

    public function test_agenda_DOS_citas_a_la_misma_hora_con_personas_distintas(): void
    {
        $horas = $this->invoke('disponibilidad', [
            'servicios' => ['Manicure', 'Pedicure'],
            'fecha' => $this->manana(),
            'juntas' => true,
        ])->json('data.horas');

        $respuesta = $this->invoke('crear_cita', [
            'servicios' => ['Manicure', 'Pedicure'],
            'fecha' => $this->manana(),
            'hora' => $horas[0]['hora_24'],
            'juntas' => true,
            'nombres' => ['Carolina', 'Sofía'],
        ])->assertOk();

        $respuesta->assertJsonPath('data.agendada', true)->assertJsonPath('data.personas', 2);

        $citas = Appointment::withoutGlobalScopes()->with('items.resource')->get();
        $this->assertCount(2, $citas);
        // A la misma hora...
        $this->assertSame(
            $citas[0]->starts_at->timestamp,
            $citas[1]->starts_at->timestamp,
        );
        // ...y con profesionales DISTINTAS: dos personas, dos sillas.
        $this->assertNotSame(
            $citas[0]->items->first()->resource_id,
            $citas[1]->items->first()->resource_id,
        );
        // El local necesita saber a quién atiende en cada silla.
        $this->assertStringContainsString('Sofía', $citas[1]->notes ?? $citas[0]->notes ?? '');
    }

    public function test_si_la_segunda_no_cabe_no_queda_ninguna(): void
    {
        /*
         * Todo o nada: dejar a la mamá agendada y a la hija afuera es peor
         * que no agendar nada -- llegan las dos y solo cabe una.
         */
        $this->lucia->update(['is_active' => false]);

        $this->invoke('crear_cita', [
            'servicios' => ['Manicure', 'Pedicure'],
            'fecha' => $this->manana(),
            'hora' => '10:00',
            'juntas' => true,
            'nombres' => ['Carolina', 'Sofía'],
        ])->assertOk()->assertJsonPath('data.agendada', false);

        $this->assertSame(0, Appointment::withoutGlobalScopes()->count());
    }

    public function test_sin_juntas_los_servicios_siguen_siendo_una_cadena(): void
    {
        // "Manos y pies" para UNA persona: una sola cita, uno después del
        // otro. La distinción es lo que evita agendar dos sillas a quien
        // viene sola.
        $horas = $this->invoke('disponibilidad', [
            'servicios' => ['Manicure', 'Pedicure'],
            'fecha' => $this->manana(),
        ])->json('data.horas');

        $this->invoke('crear_cita', [
            'servicios' => ['Manicure', 'Pedicure'],
            'fecha' => $this->manana(),
            'hora' => $horas[0]['hora_24'],
        ])->assertOk()->assertJsonPath('data.agendada', true);

        $citas = Appointment::withoutGlobalScopes()->with('items')->get();
        $this->assertCount(1, $citas);
        $this->assertCount(2, $citas[0]->items);
    }

    public function test_dos_veces_el_mismo_servicio_se_nombra_una_vez(): void
    {
        // "Semipermanente y Semipermanente" se lee como un error.
        config()->set('services.comms_core.api_key', 'llave-comms');
        config()->set('services.comms_core.base_url', 'http://comms.test');
        \Illuminate\Support\Facades\Http::fake([
            'comms.test/*' => \Illuminate\Support\Facades\Http::response(
                ['results' => [['channel' => 'whatsapp', 'status' => 'sent']]]
            ),
        ]);

        $this->invoke('disponibilidad', [
            'servicios' => ['Manicure', 'Manicure'],
            'fecha' => $this->manana(),
            'juntas' => true,
        ])->assertOk();

        \Illuminate\Support\Facades\Http::assertSent(function ($request) {
            $texto = $request->data()['text'] ?? '';

            return str_contains($texto, 'Manicure (para 2 personas)')
                && ! str_contains($texto, 'Manicure y Manicure');
        });
    }
}
