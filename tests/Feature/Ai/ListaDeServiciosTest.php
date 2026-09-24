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
 * décima fila dice «Muéstrame más servicios» y trae la siguiente
 * tanda.
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
        $this->assertSame('Muéstrame más servicios', $filas[9]['title']);
        // Y dice cuántos son, para que tocar no sea a ciegas.
        $this->assertSame('Quedan 5 más', $filas[9]['description']);
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

    public function test_tocar_muestrame_mas_manda_los_que_faltaban(): void
    {
        $this->invoke('disponibilidad', ['servicio' => 'las manitos', 'fecha' => $this->manana()]);
        $primeros = collect($this->ultimaLista())->pluck('title')->slice(0, 9);

        $respuesta = $this->invoke('disponibilidad', [
            'servicio' => 'Muéstrame más servicios',
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
        $this->assertFalse($filas->contains('Muéstrame más servicios'));
    }

    public function test_pedir_los_demas_sin_una_lista_antes_no_rompe_nada(): void
    {
        // Alguien que escribe "muéstrame más" de la nada: no hay nada
        // guardado, y eso NO es el nombre de un servicio -- se le vuelve a
        // preguntar qué quiere, en vez de buscarlo en el catálogo.
        $respuesta = $this->invoke('disponibilidad', [
            'servicio' => 'Muéstrame más servicios',
            'fecha' => $this->manana(),
        ])->assertOk();

        $this->assertSame([], $respuesta->json('data.horas'));
        $this->assertNotEmpty($respuesta->json('data.falta_informacion'));
    }

    public function test_pasar_la_hoja_no_es_haber_elegido_servicio(): void
    {
        /*
         * El fallo que dejó a seis de nueve clientas simuladas sin cita.
         *
         * Llega al menú SIN día -- que es como llega casi todo el mundo:
         * "hola, quiero una cita" -- y toca «Muéstrame más servicios».
         * Esto respondía «¿Para qué día?»: la pregunta del día estaba
         * antes en el camino, así que se comía el toque. La clienta
         * elegía día para un servicio que todavía no había escogido, y
         * los servicios que pidió le llegaban tres turnos después.
         */
        $this->invoke('disponibilidad', ['servicio' => 'las manitos'])->assertOk();
        $primeros = collect($this->ultimaLista())->pluck('title')->slice(0, 9);

        $respuesta = $this->invoke('disponibilidad', ['servicio' => 'Muéstrame más servicios'])->assertOk();

        // Lo que llega es la segunda tanda, no la pregunta del día.
        $segundos = collect($this->ultimaLista())->pluck('title');

        $this->assertCount(5, $segundos);
        $this->assertTrue($segundos->intersect($primeros)->isEmpty());
        $this->assertNull($respuesta->json('data.eligiendo_fecha'));
        $this->assertNotEmpty($respuesta->json('data.eligiendo_servicio'));
    }

    public function test_al_tocar_un_servicio_no_hay_que_repetir_el_dia(): void
    {
        /*
         * La lista se pidio "para manana". Cuando toca "Servicio 3", lo
         * unico que vuelve es ese nombre: el dia lo tiene que recordar el
         * sistema, no la clienta ni el modelo. Al modelo se le olvidaba y
         * volvia a preguntar el dia a quien ya habia dicho "hoy".
         */
        $this->invoke('disponibilidad', ['servicio' => 'las manitos', 'fecha' => $this->manana()])->assertOk();

        $respuesta = $this->invoke('disponibilidad', ['servicio' => 'Servicio 3'])->assertOk();

        $this->assertNotEmpty($respuesta->json('data.horas'));
        $this->assertSame($this->manana(), $respuesta->json('data.fecha'));
    }

    public function test_sin_lista_previa_el_dia_se_pregunta_con_botones(): void
    {
        // Antes esto devolvia "falta el dia" para que el modelo preguntara;
        // ahora el dia tambien se toca (Hoy / Mañana / Otro dia).
        $respuesta = $this->invoke('disponibilidad', ['servicio' => 'Servicio 3'])->assertOk();

        $this->assertSame([], $respuesta->json('data.horas'));
        $this->assertTrue((bool) $respuesta->json('data.eligiendo_fecha'));
    }

    public function test_al_tocar_un_servicio_lo_guardado_pisa_el_dia_que_invente_el_modelo(): void
    {
        /*
         * Lo que derrumbo a las ocho clientas simuladas: pedian "manana en
         * la manana", tocaban un servicio de la lista, y el modelo llamaba
         * con fecha "hoy". La clienta leia "hoy no tengo horas" para un dia
         * que no habia pedido. En el turno del toque, lo que ella dijo vale
         * mas que lo que el modelo supone.
         */
        $this->invoke('disponibilidad', ['servicio' => 'las manitos', 'fecha' => $this->manana(), 'franja' => 'manana']);

        $respuesta = $this->invoke('disponibilidad', ['servicio' => 'Servicio 3', 'fecha' => 'hoy'])->assertOk();

        $this->assertSame($this->manana(), $respuesta->json('data.fecha'));
        $this->assertNotEmpty($respuesta->json('data.horas'));
    }

    public function test_tocar_una_hora_alcanza_para_agendar_sin_repetir_nada(): void
    {
        /*
         * Despues de ver las horas, la clienta toca "6 pm" y el modelo
         * llama con la hora y poco mas. Antes eso era un error de
         * validacion -- "el servicio es obligatorio" -- que le llegaba como
         * "no pude consultar la agenda". Nadie agendo asi.
         */
        $horas = $this->invoke('disponibilidad', ['servicio' => 'Servicio 3', 'fecha' => $this->manana()])
            ->assertOk()->json('data.horas');

        $this->invoke('crear_cita', ['hora' => $horas[0]['hora_24']])
            ->assertOk()
            ->assertJsonPath('data.agendada', true)
            ->assertJsonPath('data.servicio', 'Servicio 3');
    }

    public function test_preguntar_por_otra_franja_sin_repetir_el_servicio_funciona(): void
    {
        // "¿Y en la tarde?" despues de ver las horas de la manana: el servicio
        // ya se dijo; la herramienta lo completa en vez de rechazar la llamada.
        $this->invoke('disponibilidad', ['servicio' => 'Servicio 3', 'fecha' => $this->manana(), 'franja' => 'manana']);

        $respuesta = $this->invoke('disponibilidad', ['fecha' => $this->manana(), 'franja' => 'tarde'])->assertOk();

        $this->assertSame(['Servicio 3'], $respuesta->json('data.servicios'));
        $this->assertNull($respuesta->json('data.falta_informacion'));
    }

    public function test_sin_nada_dicho_antes_pide_el_servicio_en_vez_de_fallar(): void
    {
        $respuesta = $this->invoke('disponibilidad', ['fecha' => $this->manana(), 'franja' => 'tarde'])->assertOk();

        $this->assertSame('No sé qué servicio quiere.', $respuesta->json('data.falta_informacion'));
    }
}
