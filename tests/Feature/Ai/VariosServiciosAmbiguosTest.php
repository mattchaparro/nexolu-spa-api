<?php

namespace Tests\Feature\Ai;

use App\Models\Appointment;
use App\Models\Business;
use App\Models\Client;
use App\Models\ServiceCategory;
use App\Support\ChannelPhone;
use App\Support\PermissionCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Scheduling\SchedulingScenario;
use Tests\TestCase;

/**
 * "Manos y pies en semi": dos servicios, y los dos se buscan.
 *
 * La conversación que lo destapó (Alejandro, 21 de septiembre): pidió
 * manos y pies semipermanente. El modelo entendió bien y mandó los dos
 * servicios, pero el código solo sabía resolver UNO ambiguo a la vez: le
 * mostró la lista de manos y se tragó los pies. Ella contestó "No está el
 * servicio. Es manos y pies en semi", el bot se inventó que no había
 * disponibilidad, y la conversación terminó en "No sirves".
 *
 * Con varios servicios no se puede preguntar -- una lista de WhatsApp
 * pregunta una cosa, no dos --, así que en cada parte ambigua se toma el
 * más probable y se deja a la vista para que lo corrija. Con UNO solo se
 * sigue preguntando: elegir entre Semi y Semi + Rubber es de ella.
 */
class VariosServiciosAmbiguosTest extends TestCase
{
    use RefreshDatabase, SchedulingScenario;

    private const KEY = 'llave-de-prueba-del-core';

    private const PHONE = '573001112233';

    private Business $business;

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
        $persona = $this->makeResource($this->business, 'Maria');

        // Como en Luxury: los nombres no dicen de qué parte del cuerpo son.
        $manos = ServiceCategory::create(['business_id' => $this->business->id, 'name' => 'Manicure', 'is_active' => true]);
        $pies = ServiceCategory::create(['business_id' => $this->business->id, 'name' => 'Pedicure', 'is_active' => true]);

        foreach (['Semipermanente', 'Semi + Rubber', 'Retiro Semipermanente'] as $nombre) {
            $this->makeService($this->business, 60, [$persona], name: $nombre)
                ->update(['service_category_id' => $manos->id]);
        }

        $pediSemi = null;
        foreach (['Pedi + Jelly Spa + Semi', 'Pedi - Hombre - Semi'] as $nombre) {
            $servicio = $this->makeService($this->business, 60, [$persona], name: $nombre);
            $servicio->update(['service_category_id' => $pies->id]);
            $pediSemi ??= $servicio;
        }

        $cliente = Client::create([
            'business_id' => $this->business->id,
            'name' => 'Historia',
            'phone' => '573009990000',
            'is_active' => true,
        ]);

        /*
         * Historia: Jelly Spa se pide mucho más que el de hombre, como en
         * Luxury (152 contra un puñado). "El más probable" sale de ahí, no
         * del alfabeto.
         */
        foreach (range(1, 3) as $i) {
            $cuando = CarbonImmutable::now()->subDays(10 + $i);
            $cita = Appointment::create([
                'business_id' => $this->business->id,
                'location_id' => $this->business->primaryLocation()?->id,
                'client_id' => $cliente->id,
                'client_name' => $cliente->name,
                'client_phone' => $cliente->phone,
                'starts_at' => $cuando,
                'ends_at' => $cuando->addHour(),
                'status' => Appointment::STATUS_COMPLETED,
            ]);
            $cita->items()->create([
                'business_id' => $this->business->id,
                'service_id' => $pediSemi->id,
                'resource_id' => $persona->id,
                'starts_at' => $cuando,
                'ends_at' => $cuando->addHour(),
                'service_starts_at' => $cuando,
                'service_ends_at' => $cuando->addHour(),
                'price' => 50000,
                'sort_order' => 0,
            ]);
        }

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

    public function test_manos_y_pies_en_semi_trae_horas_para_los_dos(): void
    {
        // Exactamente lo que mandó el modelo en la conversación real.
        $respuesta = $this->invoke('disponibilidad', [
            'servicios' => ['manos semipermanente', 'pies semipermanente'],
            'fecha' => $this->manana(),
        ])->assertOk();

        // Horas, no una lista de servicios de manos.
        $this->assertNotEmpty($respuesta->json('data.horas'));
        $this->assertNull($respuesta->json('data.eligiendo_servicio'));

        // "Semi" en manos es Semipermanente -- el que se llama así --, no
        // Retiro Semipermanente ni Semi + Rubber.
        $this->assertSame(
            ['Semipermanente', 'Pedi + Jelly Spa + Semi'],
            $respuesta->json('data.servicios'),
        );
    }

    public function test_lo_que_se_eligio_por_ella_queda_a_la_vista(): void
    {
        /*
         * En pies no hay uno que se llame "Semipermanente": hay dos con
         * "Semi". Se toma el más pedido, pero se dice, para que lo pueda
         * cambiar antes de agendar.
         */
        $respuesta = $this->invoke('disponibilidad', [
            'servicios' => ['manos semipermanente', 'pies semipermanente'],
            'fecha' => $this->manana(),
        ])->assertOk();

        $this->assertContains('«pies semipermanente» → Pedi + Jelly Spa + Semi', $respuesta->json('data.supuse'));
        $this->assertStringContainsString('ANTES de agendar', $respuesta->json('data.instruccion'));

        // Y la cabecera de las horas nombra los dos servicios reales.
        Http::assertSent(fn ($r) => str_contains($r->data()['text'] ?? '', 'Semipermanente y Pedi + Jelly Spa + Semi'));
    }

    public function test_al_agendar_se_reserva_lo_mismo_que_se_mostro(): void
    {
        /*
         * Si "ver horas" y "agendar" resolvieran distinto, la clienta
         * vería las horas de unos servicios y quedaría agendada en otros.
         */
        $horas = $this->invoke('disponibilidad', [
            'servicios' => ['manos semipermanente', 'pies semipermanente'],
            'fecha' => $this->manana(),
        ])->json('data.horas');

        $this->invoke('crear_cita', [
            'servicios' => ['manos semipermanente', 'pies semipermanente'],
            'fecha' => $this->manana(),
            'hora' => $horas[0]['hora_24'],
        ])->assertOk()->assertJsonPath('data.agendada', true);

        // La de Carolina: las demás son la historia que ordena el catálogo.
        $cita = Appointment::withoutGlobalScopes()->with('items.service')
            ->where('client_phone', ChannelPhone::normalize(self::PHONE))
            ->sole();

        $this->assertSame(
            ['Semipermanente', 'Pedi + Jelly Spa + Semi'],
            $cita->items->sortBy('starts_at')->map(fn ($i) => $i->service->name)->values()->all(),
        );
    }

    public function test_con_un_solo_servicio_se_le_sigue_preguntando(): void
    {
        // Elegir entre Semi y Semi + Rubber es de ella: aquí no se supone
        // nada, se le muestran para que toque.
        $respuesta = $this->invoke('disponibilidad', [
            'servicio' => 'pies semipermanente',
            'fecha' => $this->manana(),
        ])->assertOk();

        $this->assertSame(
            ['Pedi + Jelly Spa + Semi', 'Pedi - Hombre - Semi'],
            $respuesta->json('data.eligiendo_servicio'),
        );
    }
}
