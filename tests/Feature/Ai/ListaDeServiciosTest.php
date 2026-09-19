<?php

namespace Tests\Feature\Ai;

use App\Models\Business;
use App\Models\Client;
use App\Models\Resource;
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
 * La lista de servicios: elegir tocando, y con qué decidir.
 *
 * Una lista de WhatsApp aguanta diez filas y la categoría Manicure de
 * Luxury tiene veintitrés. Antes se mandaban diez y se le decía «hay 13
 * más, si no ves el tuyo escríbelo»: escribirlo es deletrear un nombre
 * de catálogo, que es justo lo que esta pantalla vino a evitar. Ahora la
 * décima fila dice «No veo el mío» y trae la siguiente tanda.
 *
 * Y cada fila lleva la duración y el precio, que es lo que se pregunta
 * antes de elegir. Un nombre suelto -- «Capping» -- no le dice a nadie
 * cuánto cuesta ni si alcanza a hacérselo hoy.
 */
class ListaDeServiciosTest extends TestCase
{
    use RefreshDatabase, SchedulingScenario;

    private const KEY = 'llave-de-prueba-del-core';

    private const PHONE = '573001112233';

    private Business $business;

    private Resource $persona;

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
        $this->persona = $this->makeResource($this->business, 'Maria');

        $manicure = ServiceCategory::create([
            'business_id' => $this->business->id,
            'name' => 'Manicure',
            'is_active' => true,
        ]);

        // Catorce servicios de manos: no caben en una lista.
        foreach (range(1, 14) as $i) {
            $this->makeService($this->business, 60, [$this->persona], name: 'Servicio '.$i)
                ->update(['service_category_id' => $manicure->id, 'price' => 1000 * $i]);
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

    /** @return list<array<string, mixed>> Las filas de la última lista enviada. */
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

    public function test_cuando_no_caben_la_ultima_fila_trae_los_demas(): void
    {
        $this->invoke('disponibilidad', [
            'servicio' => 'las manitos',
            'fecha' => $this->manana(),
        ])->assertOk();

        $filas = $this->ultimaLista();

        $this->assertCount(10, $filas);
        $this->assertSame('No veo el mío', $filas[9]['title']);
        // Y dice cuántos son, para que tocar no sea a ciegas.
        $this->assertSame('Te muestro los otros 5', $filas[9]['description']);
    }

    public function test_cada_servicio_dice_cuanto_dura_y_cuanto_vale(): void
    {
        // Es lo que se pregunta antes de elegir: si alcanza hoy y cuánto
        // cuesta. Un nombre suelto no responde ninguna de las dos.
        $this->invoke('disponibilidad', [
            'servicio' => 'las manitos',
            'fecha' => $this->manana(),
        ])->assertOk();

        $this->assertSame('60 min · 1.000 COP', $this->ultimaLista()[0]['description']);
    }

    public function test_tocar_no_veo_el_mio_manda_los_que_faltaban(): void
    {
        $this->invoke('disponibilidad', ['servicio' => 'las manitos', 'fecha' => $this->manana()]);
        $primeros = collect($this->ultimaLista())->pluck('title')->slice(0, 9);

        $respuesta = $this->invoke('disponibilidad', [
            'servicio' => 'No veo el mío',
            'fecha' => $this->manana(),
        ])->assertOk();

        $segundos = collect($this->ultimaLista())->pluck('title');

        $this->assertCount(5, $segundos);
        // Los de la segunda tanda no se repiten con los de la primera:
        // volver a ver lo mismo se lee como que el bot no entendió.
        $this->assertTrue($segundos->intersect($primeros)->isEmpty());
        $this->assertSame(0, $respuesta->json('data.faltan_por_mostrar'));
    }

    public function test_cuando_todos_caben_no_se_gasta_una_fila_en_nada(): void
    {
        $cejas = ServiceCategory::create([
            'business_id' => $this->business->id, 'name' => 'Cejas', 'is_active' => true,
        ]);

        foreach (['Hilo', 'Cera', 'Henna'] as $nombre) {
            $this->makeService($this->business, 30, [$this->persona], name: $nombre)
                ->update(['service_category_id' => $cejas->id]);
        }

        $this->invoke('disponibilidad', ['servicio' => 'cejas', 'fecha' => $this->manana()])->assertOk();

        $filas = collect($this->ultimaLista())->pluck('title');

        $this->assertCount(3, $filas);
        $this->assertFalse($filas->contains('No veo el mío'));
    }

    public function test_pedir_los_demas_sin_una_lista_antes_no_rompe_nada(): void
    {
        // Alguien que escribe "no veo el mío" de la nada: no hay nada
        // guardado, así que se trata como cualquier otra palabra suya.
        $this->invoke('disponibilidad', [
            'servicio' => 'No veo el mío',
            'fecha' => $this->manana(),
        ])->assertOk();

        $this->assertTrue(true);
    }
}
