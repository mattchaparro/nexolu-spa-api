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

    public function test_una_ficha_borrada_deja_de_existir_para_el_agente(): void
    {
        /*
         * El negocio borró esa clienta -- puede haber sido a petición de ella
         * misma, que es un derecho. Si el agente la sigue reconociendo por su
         * teléfono, la saluda por su nombre, le lista sus citas y agenda bajo
         * un registro que el negocio dio por eliminado.
         *
         * Pasaba porque `withoutGlobalScopes()` quita TODOS los scopes,
         * incluido el del borrado lógico. El correcto es quitar solo el de
         * negocio, por su nombre.
         */
        $carolina = $this->clienta('Carolina', '+573001112233');

        $cita = $this->booking()->book(
            $this->business,
            [['service_id' => $this->manicure->id, 'resource_id' => $this->maria->id, 'starts_at' => $this->manana()->setTime(10, 0)]],
            $carolina,
        );

        $carolina->delete();

        // Ya no es nadie conocido: sus citas no salen...
        $this->assertSame([], $this->invoke('mis_citas')->assertOk()->json('data.citas'));

        // ...y su cita tampoco se puede tocar desde el chat.
        $r = $this->invoke('cancelar_cita', ['cita_id' => $cita->id])->assertOk();
        $this->assertFalse($r->json('data.cancelada'));
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
        $this->assertEquals(45000, $servicios[0]['precio_valor']);
    }

    public function test_las_horas_libres_vienen_legibles_y_tambien_para_reusar(): void
    {
        /*
         * «tengo a las 10:00, 11:00, 12:00, 13:00…» no es como habla nadie
         * acá. El modelo escribe `hora` y vuelve a llamar con `hora_24`.
         */
        $horas = $this->invoke('disponibilidad', [
            'servicio' => 'Manicure clasico',
            'fecha' => $this->manana()->format('Y-m-d'),
        ])->assertOk()->json('data.horas');

        $this->assertSame('9 am', $horas[0]['hora']);
        $this->assertSame('09:00', $horas[0]['hora_24']);
    }

    public function test_un_servicio_escrito_con_espacios_de_mas_se_reconoce(): void
    {
        // Pasó en la primera conversación real: la clienta escribió
        // "Semi permanente" y el catálogo dice "Semipermanente".
        $this->manicure->update(['name' => 'Semipermanente']);

        $this->invoke('disponibilidad', [
            'servicio' => 'semi permanente',
            'fecha' => $this->manana()->format('Y-m-d'),
        ])->assertOk()->assertJsonPath('data.servicios.0', 'Semipermanente');
    }

    public function test_varios_servicios_son_UNA_cita_encadenada(): void
    {
        /*
         * Pasó en una conversación real: la clienta pidió manos y pies, y
         * el agente contestó "el sistema requiere que se agenden por
         * separado" -- cuando el sistema SÍ sabe encadenarlos. La
         * herramienta solo aceptaba un servicio.
         */
        $this->clienta('Carolina', '+573001112233');
        $pedicure = $this->makeService($this->business, 30, [$this->maria], name: 'Pedicure express');

        $horas = $this->invoke('disponibilidad', [
            'servicios' => ['Manicure clasico', 'Pedicure express'],
            'fecha' => $this->manana()->format('Y-m-d'),
        ])->assertOk();

        $horas->assertJsonPath('data.servicios', ['Manicure clasico', 'Pedicure express']);
        $primera = $horas->json('data.horas.0');

        $cita = $this->invoke('crear_cita', [
            'servicios' => ['Manicure clasico', 'Pedicure express'],
            'fecha' => $this->manana()->format('Y-m-d'),
            'hora' => $primera['hora_24'],
        ])->assertOk();

        $cita->assertJsonPath('data.agendada', true);
        // UNA cita con dos servicios, no dos citas.
        $this->assertSame(1, Appointment::withoutGlobalScopes()->count());
        $creada = Appointment::withoutGlobalScopes()->with('items')->first();
        $this->assertCount(2, $creada->items);
        // Y el segundo empieza cuando termina el primero, no a la misma hora.
        $inicios = $creada->items->pluck('starts_at')->map->timestamp->all();
        $this->assertNotEquals($inicios[0], $inicios[1]);
        // El precio es la suma.
        $this->assertEquals(
            (float) $this->manicure->price + (float) $pedicure->price,
            $cita->json('data.precio'),
        );
    }

    public function test_con_una_sola_sede_no_se_nombra(): void
    {
        // «en la sede Principal» en cada mensaje, con un solo local, suena
        // a sistema y no a la recepción del salón.
        $this->assertNull(
            $this->invoke('disponibilidad', [
                'servicio' => 'Manicure clasico',
                'fecha' => $this->manana()->format('Y-m-d'),
            ])->assertOk()->json('data.sede'),
        );
    }

    public function test_la_disponibilidad_MANDA_las_horas_como_botones(): void
    {
        /*
         * Pedírselo al modelo no alcanzó: seguía escribiendo "tengo a las
         * 10:00, 10:15, 10:30..." y la clienta tenía que transcribir una.
         * Ofrecer horas por WhatsApp ES mostrar botones, así que los manda
         * la herramienta y no queda al azar de que el modelo obedezca.
         */
        config()->set('services.comms_core.api_key', 'llave-comms');
        config()->set('services.comms_core.base_url', 'http://comms.test');
        \Illuminate\Support\Facades\Http::fake([
            'comms.test/*' => \Illuminate\Support\Facades\Http::response(
                ['results' => [['channel' => 'whatsapp', 'status' => 'sent']]]
            ),
        ]);

        $r = $this->invoke('disponibilidad', [
            'servicio' => 'Manicure clasico',
            'fecha' => $this->manana()->format('Y-m-d'),
        ])->assertOk();

        \Illuminate\Support\Facades\Http::assertSent(function ($request) {
            $body = $request->data();
            $titulos = array_column($body['whatsapp_options']['options'] ?? [], 'title');

            // Repartidas, no cuatro cuartos de hora seguidos: quien no puede
            // a las diez tampoco puede a las diez y cuarto.
            return count($titulos) === 4 && $titulos[0] !== $titulos[1];
        });

        $this->assertStringContainsString('YA le llegaron', $r->json('data.instruccion'));
        $this->assertCount(4, $r->json('data.ofrecidas'));
    }

    public function test_si_no_hay_canal_el_modelo_las_escribe(): void
    {
        // Sin Connect configurado no se pierde la respuesta: se le dice al
        // modelo que las escriba él.
        $this->assertStringContainsString(
            'Ofrécele',
            $this->invoke('disponibilidad', [
                'servicio' => 'Manicure clasico',
                'fecha' => $this->manana()->format('Y-m-d'),
            ])->assertOk()->json('data.instruccion'),
        );
    }

    public function test_la_franja_la_filtra_la_herramienta(): void
    {
        // "en la tarde" lo resuelve el código: si lo filtra el modelo,
        // ofrece horas que no pidió o descarta las que sí servían.
        $horas = $this->invoke('disponibilidad', [
            'servicio' => 'Manicure clasico',
            'fecha' => $this->manana()->format('Y-m-d'),
            'franja' => 'tarde',
        ])->assertOk()->json('data.horas');

        foreach ($horas as $h) {
            $this->assertGreaterThanOrEqual(12, (int) explode(':', $h['hora_24'])[0]);
        }
    }

    public function test_el_precio_va_escrito_con_su_moneda(): void
    {
        /*
         * Pasó de verdad en una prueba: con `precio => 180000.0` el modelo
         * escribió "$180.00" en el chat -- leyó los miles como decimales y
         * cotizó mil veces menos. Un número suelto invita a reformatearlo;
         * el texto ya formateado, no.
         */
        $servicios = $this->invoke('servicios')->assertOk()->json('data.servicios');

        $this->assertSame('45.000 COP', $servicios[0]['precio']);
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
        /*
         * Y las devuelve con 200, no con un error.
         *
         * Un 4xx el agente lo lee como "falló el sistema" y termina
         * disculpándose ante algo que tenía solución: preguntar cuál. La
         * ambigüedad es un resultado, no una avería.
         */
        $r = $this->invoke('disponibilidad', [
            'servicio' => 'masaje tailandés',
            'fecha' => $this->manana()->toDateString(),
        ])->assertOk();

        $this->assertStringContainsString('Manicure clasico', $r->json('data.falta_informacion'));
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
        ])->assertOk();

        $this->assertStringContainsString('sede', $r->json('data.falta_informacion'));
    }

    public function test_el_catalogo_de_herramientas_dice_que_exige_cada_una(): void
    {
        $tools = $this->withHeader('Authorization', 'Bearer '.self::KEY)
            ->getJson('/api/ai/tools/catalog')->assertOk()->json('tools');

        $this->assertTrue($tools['disponibilidad']['allows_customers']);
        $this->assertSame('online_booking', $tools['disponibilidad']['required_feature']);
        $this->assertArrayNotHasKey('clientes', $tools);

        /*
         * Lo abierto al publico se anuncia SIN permiso.
         *
         * El Core esconde del modelo las herramientas cuyo permiso no tiene
         * quien pregunta, y una clienta de WhatsApp no tiene ninguno. Con
         * "citas.crear" anunciado, el agente contestaba "esa herramienta no
         * esta disponible" justo despues de que la clienta dijo "si,
         * confirmo". El permiso se sigue exigiendo al invocar, para quien si
         * es empleada.
         */
        $this->assertNull($tools['crear_cita']['required_permission']);
        $this->assertTrue($tools['crear_cita']['allows_customers']);
    }

    /*
    |--------------------------------------------------------------------------
    | La ficha: el activo del negocio
    |--------------------------------------------------------------------------
    */

    public function test_guardar_contacto_crea_la_ficha_aunque_no_agende(): void
    {
        /*
         * Hasta ahora la ficha solo nacia al AGENDAR, asi que todo el que
         * escribia y no cerraba cita se perdia. Sin ficha no hay a quien
         * mandarle una promocion despues.
         */
        $r = $this->invoke('guardar_contacto', ['nombre' => 'Valentina Ospina'])->assertOk();

        $this->assertTrue($r->json('data.guardado'));

        $ficha = Client::withoutGlobalScopes()->where('phone', '573001112233')->first();
        $this->assertNotNull($ficha);
        $this->assertSame('Valentina', $ficha->name);
    }

    public function test_guardar_contacto_no_pisa_el_nombre_que_ya_tenia_el_negocio(): void
    {
        /*
         * El nombre del sistema lo escribio el negocio, con la ortografia de
         * su agenda; el de aca lo dedujo un modelo de una frase suelta. Ante
         * la duda gana el del negocio.
         */
        $this->clienta('Carolina', '+573001112233');

        $r = $this->invoke('guardar_contacto', ['nombre' => 'karo'])->assertOk();

        $this->assertFalse($r->json('data.guardado'));
        $this->assertSame('Carolina', $r->json('data.ya_lo_teniamos'));
        $this->assertSame('Carolina', Client::withoutGlobalScopes()->first()->name);
    }

    public function test_agendar_para_otra_persona_queda_anotado(): void
    {
        /*
         * La cita sigue siendo del telefono -- ahi llegan los recordatorios --
         * pero el local necesita saber a quien va a atender. Sin esto, la
         * hija que agenda para su mama aparece en la agenda como la mama.
         */
        $this->clienta('Carolina', '+573001112233');

        $r = $this->invoke('crear_cita', [
            'servicio' => 'Manicure clasico',
            'fecha' => $this->manana()->toDateString(),
            'hora' => '10:00',
            'para_quien' => 'Su mamá Rosa',
        ])->assertOk();

        $cita = Appointment::withoutGlobalScopes()->find($r->json('data.id'));

        $this->assertStringContainsString('Rosa', (string) $cita->notes);
        // Y sigue siendo de quien escribió: por ahí se cancela y ahí avisan.
        $this->assertSame('Carolina', $cita->client->name);
    }

    private function booking(): BookingService
    {
        return $this->app->make(BookingService::class);
    }
}
