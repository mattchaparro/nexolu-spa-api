<?php

namespace Tests\Feature\Ai;

use App\Models\Appointment;
use App\Models\Client;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Support\ChannelPhone;
use App\Support\PermissionCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Scheduling\SchedulingScenario;
use Tests\TestCase;

/**
 * Lo que el local esconde, WhatsApp no lo ofrece.
 *
 * Desde el panel del spa se esconde una familia entera de un tirón
 * ("esconder Pestañas"), y hasta hoy eso significaba "no sale en la
 * página pública". Pero el bot es otra puerta al mismo catálogo: si no
 * respeta el mismo interruptor, el local apaga un servicio y WhatsApp lo
 * sigue vendiendo. Alguien llega al salón por algo que ya no se presta.
 *
 * Se prueban las CUATRO puertas por las que un servicio puede asomarse:
 * el catálogo que lee el agente, la traducción de "las pestañas" a
 * servicios, la agenda, y la reserva. Cerrar tres de cuatro no sirve de
 * nada.
 */
class ServiciosEscondidosTest extends TestCase
{
    use RefreshDatabase, SchedulingScenario;

    private const KEY = 'llave-de-prueba-del-core';

    private const PHONE = '573001112233';

    private \App\Models\Business $business;

    private Service $visible;

    private Service $escondido;

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
        $persona = $this->makeResource($this->business, 'Maria');

        $manicure = ServiceCategory::create([
            'business_id' => $this->business->id,
            'name' => 'Manicure',
            'is_active' => true,
        ]);

        $pestanas = ServiceCategory::create([
            'business_id' => $this->business->id,
            'name' => 'Pestañas',
            'is_active' => true,
        ]);

        $this->visible = $this->makeService($this->business, 60, [$persona], name: 'Semipermanente');
        $this->visible->update(['service_category_id' => $manicure->id]);

        // Escondida desde el panel: "esconder Pestañas".
        $this->escondido = $this->makeService($this->business, 60, [$persona], name: 'Wispy');
        $this->escondido->update([
            'service_category_id' => $pestanas->id,
            'is_bookable_online' => false,
        ]);

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

    public function test_el_catalogo_que_lee_el_agente_no_lo_incluye(): void
    {
        $nombres = collect($this->invoke('servicios', [])->assertOk()->json('data.servicios'))
            ->pluck('nombre');

        $this->assertContains('Semipermanente', $nombres);
        $this->assertNotContains('Wispy', $nombres);
    }

    public function test_pedirlo_por_su_nombre_exacto_no_lo_agenda(): void
    {
        // La puerta más directa: la clienta lo conoce y lo pide tal cual.
        $respuesta = $this->invoke('disponibilidad', [
            'servicio' => 'Wispy',
            'fecha' => $this->manana(),
        ]);

        $this->assertSame([], $respuesta->json('data.horas') ?? []);
        $this->assertSame(0, Appointment::withoutGlobalScopes()->count());
    }

    public function test_pedir_la_familia_escondida_no_la_saca_por_la_traduccion(): void
    {
        /*
         * La puerta nueva: "quiero pestañas" ya no se resuelve por el
         * nombre sino por la categoría, y esa traducción tiene que leer
         * el mismo catálogo recortado. Si leyera la tabla entera,
         * resucitaría justo lo que el local apagó.
         */
        $respuesta = $this->invoke('disponibilidad', [
            'servicio' => 'quiero pestañas',
            'fecha' => $this->manana(),
        ]);

        $this->assertSame([], $respuesta->json('data.horas') ?? []);
        $this->assertStringNotContainsString('Wispy', json_encode($respuesta->json()) ?: '');
    }

    public function test_reservarlo_directamente_tampoco(): void
    {
        // Por si el modelo se lo inventa de una conversación vieja.
        $this->invoke('crear_cita', [
            'servicio' => 'Wispy',
            'fecha' => $this->manana(),
            'hora' => '10:00',
        ]);

        $this->assertSame(0, Appointment::withoutGlobalScopes()->count());
    }

    public function test_lo_que_sigue_visible_se_sigue_agendando(): void
    {
        // El otro lado del filtro: esconder pestañas no puede dejar el
        // salón sin poder vender manicure.
        $horas = $this->invoke('disponibilidad', [
            'servicio' => 'las manitos',
            'fecha' => $this->manana(),
        ])->assertOk()->json('data.horas');

        $this->assertNotEmpty($horas);
    }
}
