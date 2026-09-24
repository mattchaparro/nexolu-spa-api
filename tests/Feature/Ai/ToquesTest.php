<?php

namespace Tests\Feature\Ai;

use App\Ai\Capabilities\AvailabilityCapability;
use App\Ai\Toques;
use App\Ai\UltimoPedido;
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

    /**
     * Si las pruebas que piden horas CON fecha arrancan como quien ya
     * respondió «¿con quién?».
     *
     * Desde que la pregunta sale también cuando la clienta ya dijo el día,
     * pedir «semipermanente mañana» con dos manicuristas devuelve la
     * pregunta y no las horas. Casi todas las pruebas de este archivo son
     * sobre lo que viene DESPUÉS --tocar una hora, confirmar, agendar--, así
     * que arrancan con la pregunta ya respondida. Las que prueban la pregunta
     * misma lo apagan.
     */
    private bool $yaEligioPersona = true;

    /** @param array<string, mixed> $arguments */
    private function invoke(string $tool, array $arguments, ?string $phone = null): TestResponse
    {
        if ($this->yaEligioPersona && $tool === 'disponibilidad' && isset($arguments['fecha'])) {
            $tel = (string) ChannelPhone::normalize($phone ?? self::PHONE);
            UltimoPedido::guardar($tel, [...UltimoPedido::ver($tel), 'empleado_preguntado' => true]);
        }

        return $this->withHeader('Authorization', 'Bearer '.self::KEY)
            ->postJson('/api/ai/tools/invoke', [
                'tool' => $tool,
                'arguments' => $arguments,
                'context' => [
                    'business_id' => (string) $this->business->id,
                    'user_id' => $phone ?? self::PHONE,
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

        /*
         * El texto del manejador va vacio: la confirmacion la manda la
         * propia herramienta de reserva por el canal (demasiado importante
         * para depender de que alguien la redacte).
         */
        $this->assertSame('', $respuesta['text']);
        Http::assertSent(fn ($r) => str_contains($r->data()['text'] ?? '', '¡Tu cita quedó confirmada!'));

        $cita = Appointment::withoutGlobalScopes()->with('items.service')->sole();
        $this->assertSame('Semipermanente', $cita->items->first()->service->name);
        $this->assertSame($horas[0]['hora_24'], $cita->starts_at->timezone('America/Bogota')->format('H:i'));
    }

    public function test_la_confirmacion_trae_el_detalle_de_la_cita(): void
    {
        /*
         * El formato que Luxury ya usa en ManyChat y sus clientas
         * reconocen: día, hora, servicio, precio y quién atiende, para que
         * nadie tenga que volver a preguntar.
         */
        $this->business->forceFill(['public_profile' => ['instagram' => '@luxurynails']])->save();
        $horas = $this->invoke('disponibilidad', ['servicio' => 'Semipermanente', 'fecha' => $this->manana()])
            ->json('data.ofrecidas');
        $this->toques()->atender($this->conversacion, $horas[0]['hora']);

        $this->toques()->atender($this->conversacion, Toques::SI);

        Http::assertSent(function ($r) {
            $texto = $r->data()['text'] ?? '';

            return str_contains($texto, '¡Tu cita quedó confirmada!')
                && str_contains($texto, '💅 Servicio: *Semipermanente*')
                && str_contains($texto, '⏰ Hora:')
                && str_contains($texto, '💵 Precio: *$')
                && str_contains($texto, '🙋‍♀️ Te atiende:')
                && str_contains($texto, 'Gracias por agendar en *Spa de prueba*')
                && str_contains($texto, 'https://instagram.com/luxurynails');
        });
    }

    public function test_tras_la_cita_se_ofrece_lo_que_el_negocio_tenga_escrito(): void
    {
        // Las respuestas salen de la base de conocimiento (IA Core), no de
        // un flujo aparte que haya que mantener al día en dos sitios.
        config()->set('services.ia_core.base_url', 'http://ia-core.test');
        Http::fake([
            'comms.test/*' => Http::response(['results' => [['channel' => 'whatsapp', 'status' => 'sent']]]),
            'ia-core.test/*' => Http::response([
                ['id' => 'k1', 'topic' => 'Garantías', 'answer' => 'Cubrimos 5 días el semipermanente.', 'is_active' => true, 'updated_at' => '2026-09-22T10:00:00'],
                ['id' => 'k2', 'topic' => 'Recomendaciones', 'answer' => 'Llega 10 minutos antes.', 'is_active' => true, 'updated_at' => '2026-09-22T10:00:00'],
                ['id' => 'k3', 'topic' => 'Promo vieja', 'answer' => 'No debe ofrecerse.', 'is_active' => false, 'updated_at' => '2026-09-22T10:00:00'],
            ]),
        ]);

        $horas = $this->invoke('disponibilidad', ['servicio' => 'Semipermanente', 'fecha' => $this->manana()])
            ->json('data.ofrecidas');
        $this->toques()->atender($this->conversacion, $horas[0]['hora']);
        $this->toques()->atender($this->conversacion, Toques::SI);

        // Solo los temas escritos y activos; «Cancelaciones» no está.
        $this->assertSame(['Info de garantías', 'Recomendaciones'], array_column($this->ultimaLista(), 'title'));

        $respuesta = $this->toques()->atender($this->conversacion, 'Info de garantías');

        $this->assertSame('Cubrimos 5 días el semipermanente.', $respuesta['text']);
    }

    public function test_sin_conocimiento_escrito_no_se_ofrece_nada(): void
    {
        config()->set('services.ia_core.base_url', 'http://ia-core.test');
        Http::fake([
            'comms.test/*' => Http::response(['results' => [['channel' => 'whatsapp', 'status' => 'sent']]]),
            'ia-core.test/*' => Http::response([]),
        ]);

        $horas = $this->invoke('disponibilidad', ['servicio' => 'Semipermanente', 'fecha' => $this->manana()])
            ->json('data.ofrecidas');
        $this->toques()->atender($this->conversacion, $horas[0]['hora']);
        $this->toques()->atender($this->conversacion, Toques::SI);

        // Un botón que contesta "no tengo esa información" es peor que no estar.
        Http::assertNotSent(fn ($r) => str_contains($r->data()['text'] ?? '', 'información adicional'));
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

        $this->assertSame('', $respuesta['text']);
        Http::assertSent(fn ($r) => str_contains($r->data()['text'] ?? '', '¡Tu cita quedó confirmada!'));
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

        // Alguien mas (OTRO telefono: si fuera el mismo, seria "esa cita ya
        // la tienes") se lleva esa hora antes de que confirme.
        $this->invoke(
            'crear_cita',
            ['servicio' => 'Semipermanente', 'fecha' => $this->manana(), 'hora' => $horas[0]['hora_24'], 'cliente' => 'Otra Persona'],
            '573009998877',
        )->assertJsonPath('data.agendada', true);

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

    public function test_antes_del_dia_se_pregunta_con_quien_con_su_horario(): void
    {
        /*
         * Quien viene por su manicurista de siempre no quiere elegir un día
         * y descubrir después que ella no trabaja. Se pregunta antes, con
         * «Cualquiera» de primera para quien no tiene preferencia, y cada
         * fila con el horario para que elegir no sea adivinar.
         */
        $respuesta = $this->invoke('disponibilidad', ['servicio' => 'Semipermanente'])->assertOk();

        $this->assertTrue((bool) $respuesta->json('data.eligiendo_empleado'));
        $filas = $this->ultimaLista();
        // En el orden del catálogo, con «Cualquiera» de primera.
        $this->assertSame([AvailabilityCapability::CUALQUIERA, 'Lucia', 'Maria'], array_column($filas, 'title'));
        // Lucia trabaja de lunes a sábado, de 2:00 pm a 6:00 pm.
        $this->assertSame('Lun a Sáb · 2:00 pm a 6:00 pm', $filas[1]['description']);
    }

    public function test_tocar_una_profesional_sigue_con_el_dia_y_filtra_sus_horas(): void
    {
        $this->invoke('disponibilidad', ['servicio' => 'Semipermanente']);

        // Toca a Lucia, que solo trabaja en la tarde.
        $respuesta = $this->toques()->atender($this->conversacion, 'Lucia');

        $this->assertSame('', $respuesta['text']);
        $this->assertSame(['Hoy', 'Mañana', 'Otro día'], array_column($this->ultimaLista(), 'title'));

        $this->toques()->atender($this->conversacion, 'Mañana');
        $horas = array_column($this->ultimaLista(), 'description');

        $this->assertNotEmpty($horas);
        foreach ($horas as $con) {
            $this->assertStringContainsString('Lucia', (string) $con);
        }
    }

    public function test_cualquiera_no_fija_profesional_y_no_se_vuelve_a_preguntar(): void
    {
        $this->invoke('disponibilidad', ['servicio' => 'Semipermanente']);

        $this->toques()->atender($this->conversacion, AvailabilityCapability::CUALQUIERA);

        $this->assertSame(['Hoy', 'Mañana', 'Otro día'], array_column($this->ultimaLista(), 'title'));
        $pedido = UltimoPedido::ver((string) ChannelPhone::normalize(self::PHONE));
        $this->assertArrayNotHasKey('empleado', $pedido);
        $this->assertTrue($pedido['empleado_preguntado']);
    }

    public function test_sin_fecha_el_dia_se_pregunta_con_botones(): void
    {
        /*
         * El único paso del agendamiento que obligaba a escribir era la
         * fecha -- y ahí el modelo a veces entendía otra cosa. Ahora es
         * un botón más: quien viene de un flujo tipo ManyChat agenda de
         * punta a punta sin teclear una letra.
         */
        $this->invoke('disponibilidad', ['servicio' => 'Semipermanente']);
        $this->toques()->atender($this->conversacion, AvailabilityCapability::CUALQUIERA);

        $this->assertSame(['Hoy', 'Mañana', 'Otro día'], array_column($this->ultimaLista(), 'title'));
    }

    public function test_tocar_manana_trae_las_horas_de_manana(): void
    {
        $this->invoke('disponibilidad', ['servicio' => 'Semipermanente']);
        $this->toques()->atender($this->conversacion, AvailabilityCapability::CUALQUIERA);

        $respuesta = $this->toques()->atender($this->conversacion, 'Mañana');

        $this->assertSame('', $respuesta['text']);
        $dia = CarbonImmutable::now('America/Bogota')->addDay()->locale('es')->isoFormat('dddd D [de] MMMM');
        Http::assertSent(fn ($r) => str_contains($r->data()['text'] ?? '', 'Para *Semipermanente*')
            && str_contains($r->data()['text'] ?? '', $dia));
    }

    public function test_otro_dia_lista_los_siguientes_y_el_toque_busca_ese_dia(): void
    {
        $this->invoke('disponibilidad', ['servicio' => 'Semipermanente']);
        $this->toques()->atender($this->conversacion, AvailabilityCapability::CUALQUIERA);

        $respuesta = $this->toques()->atender($this->conversacion, 'Otro día');

        // Siete días desde pasado mañana, tocables.
        $this->assertSame('', $respuesta['text']);
        $dias = array_column($this->ultimaLista(), 'title');
        $this->assertCount(7, $dias);

        $respuesta = $this->toques()->atender($this->conversacion, $dias[0]);

        $this->assertSame('', $respuesta['text']);
        $pasadoManana = CarbonImmutable::now('America/Bogota')->addDays(2)->locale('es')->isoFormat('dddd D [de] MMMM');
        Http::assertSent(fn ($r) => str_contains($r->data()['text'] ?? '', 'Para *Semipermanente*')
            && str_contains($r->data()['text'] ?? '', $pasadoManana));
    }

    public function test_un_toque_recortado_por_whatsapp_sigue_siendo_el_servicio_completo(): void
    {
        /*
         * WhatsApp corta los títulos de lista a 24 caracteres: la fila de
         * «Recubrimiento Rubber sin esmaltado» se ve (y VUELVE, al tocarla)
         * como «Recubrimiento Rubber si…». Con el catálogo real de nombres
         * largos, el toque no calzaba con nada, caía al modelo y el modelo
         * volvía a preguntar lo que la clienta acababa de tocar.
         */
        $manicure = ServiceCategory::withoutGlobalScope('business')
            ->where('business_id', $this->business->id)->where('name', 'Manicure')->sole();
        $maria = \App\Models\Resource::withoutGlobalScope('business')
            ->where('business_id', $this->business->id)->where('name', 'Maria')->sole();
        $this->makeService($this->business, 60, [$maria], name: 'Recubrimiento Rubber sin esmaltado')
            ->update(['service_category_id' => $manicure->id]);

        $this->invoke('disponibilidad', ['servicio' => 'las manitos', 'fecha' => $this->manana()])->assertOk();

        $respuesta = $this->toques()->atender($this->conversacion, 'Recubrimiento Rubber si…');

        $this->assertNotNull($respuesta, 'el toque recortado tiene que atenderse en código');
        $this->assertSame('', $respuesta['text']);
        Http::assertSent(fn ($r) => str_contains($r->data()['text'] ?? '', 'Para *Recubrimiento Rubber sin esmaltado*'));
    }

    public function test_tocar_un_servicio_de_la_lista_trae_las_horas_del_dia_que_dijo(): void
    {
        // "las manitos" da para tres: se le manda la lista de servicios.
        $this->invoke('disponibilidad', ['servicio' => 'las manitos', 'fecha' => $this->manana()])->assertOk();
        $this->assertContains('Tradicional', array_column($this->ultimaLista(), 'title'));

        $this->toques()->atender($this->conversacion, 'Tradicional');

        // Con dos manicuristas se pregunta con quién, aunque ya dijo el día...
        $this->assertContains(AvailabilityCapability::CUALQUIERA, array_column($this->ultimaLista(), 'title'));

        $respuesta = $this->toques()->atender($this->conversacion, AvailabilityCapability::CUALQUIERA);

        // ...y después van las horas de ESE día, sin que nadie lo repita.
        $this->assertSame('', $respuesta['text']);
        Http::assertSent(fn ($r) => str_contains($r->data()['text'] ?? '', 'Para *Tradicional*'));
    }

    public function test_quien_dice_servicio_y_dia_tambien_elige_con_quien(): void
    {
        /*
         * «semi para mañana», escrito de una. Antes iban directo las horas
         * de las dos mezcladas, y la clienta se enteraba al confirmar de a
         * quién le había tocado. Con los botones sí se preguntaba: escribir
         * no puede dar una experiencia peor que tocar.
         */
        $this->yaEligioPersona = false;

        $respuesta = $this->invoke('disponibilidad', ['servicio' => 'Semipermanente', 'fecha' => $this->manana()])
            ->assertOk();

        $this->assertTrue((bool) $respuesta->json('data.eligiendo_empleado'));
        $this->assertContains(AvailabilityCapability::CUALQUIERA, array_column($this->ultimaLista(), 'title'));
    }

    /**
     * El bug de Laura: pidió mover su cita y el modelo, en vez de moverla,
     * creó una nueva. Quedó con dos citas y el salón esperándola dos veces.
     * Ahora la reserva detecta que ya tiene una del mismo servicio y el
     * flujo pregunta con botones qué hacer.
     */
    public function test_pedir_lo_mismo_teniendo_cita_pregunta_si_la_mueve(): void
    {
        // Su cita en pie, y luego pide horas otra vez y confirma otra hora.
        $horas = $this->invoke('disponibilidad', ['servicio' => 'Semipermanente', 'fecha' => $this->manana()])
            ->json('data.ofrecidas');
        $this->invoke('crear_cita', ['servicio' => 'Semipermanente', 'fecha' => $this->manana(), 'hora' => $horas[0]['hora_24']])
            ->assertJsonPath('data.agendada', true);

        $this->toques()->atender($this->conversacion, $horas[1]['hora']);
        $respuesta = $this->toques()->atender($this->conversacion, Toques::SI);

        // No agendó ni movió: preguntó, con la cita existente a la vista.
        $this->assertSame('', $respuesta['text']);
        $this->assertSame(1, Appointment::withoutGlobalScopes()->count());
        $this->assertSame([Toques::MOVER, Toques::OTRA_CITA], array_column($this->ultimaLista(), 'title'));
        Http::assertSent(fn ($r) => str_contains($r->data()['text'] ?? '', 'Ya tienes una cita'));
    }

    public function test_tocar_mover_mi_cita_la_mueve_sin_duplicar(): void
    {
        $horas = $this->invoke('disponibilidad', ['servicio' => 'Semipermanente', 'fecha' => $this->manana()])
            ->json('data.ofrecidas');
        $this->invoke('crear_cita', ['servicio' => 'Semipermanente', 'fecha' => $this->manana(), 'hora' => $horas[0]['hora_24']]);
        $this->toques()->atender($this->conversacion, $horas[1]['hora']);
        $this->toques()->atender($this->conversacion, Toques::SI);

        $respuesta = $this->toques()->atender($this->conversacion, Toques::MOVER);

        // UNA cita, a la hora nueva; el aviso del cambio salió por el canal.
        $this->assertSame('', $respuesta['text']);
        $cita = Appointment::withoutGlobalScopes()->sole();
        $this->assertSame($horas[1]['hora_24'], $cita->starts_at->timezone('America/Bogota')->format('H:i'));
        Http::assertSent(fn ($r) => str_contains($r->data()['text'] ?? '', 'quedó para el'));
    }

    public function test_tocar_agendar_otra_si_deja_las_dos_citas(): void
    {
        // El espejo de Laura: quien SÍ quiere dos citas (esta semana y la
        // otra) no puede terminar con la primera movida.
        $horas = $this->invoke('disponibilidad', ['servicio' => 'Semipermanente', 'fecha' => $this->manana()])
            ->json('data.ofrecidas');
        $this->invoke('crear_cita', ['servicio' => 'Semipermanente', 'fecha' => $this->manana(), 'hora' => $horas[0]['hora_24']]);
        $this->toques()->atender($this->conversacion, $horas[1]['hora']);
        $this->toques()->atender($this->conversacion, Toques::SI);

        $respuesta = $this->toques()->atender($this->conversacion, Toques::OTRA_CITA);

        $this->assertSame('', $respuesta['text']);
        $this->assertSame(2, Appointment::withoutGlobalScopes()->count());
        Http::assertSent(fn ($r) => str_contains($r->data()['text'] ?? '', '¡Tu cita quedó confirmada!'));
    }

    public function test_otro_servicio_el_mismo_dia_tambien_pregunta(): void
    {
        /*
         * La segunda forma del bug de Laura: pidió mover su cita, el modelo
         * consultó horas de OTRO servicio, y como el nombre no coincidía la
         * guarda no saltaba -- quedó con las dos citas el mismo martes. Dos
         * visitas el mismo día casi siempre son una mudanza a medio hacer.
         */
        $horas = $this->invoke('disponibilidad', ['servicio' => 'Semipermanente', 'fecha' => $this->manana()])
            ->json('data.ofrecidas');
        $this->invoke('crear_cita', ['servicio' => 'Semipermanente', 'fecha' => $this->manana(), 'hora' => $horas[0]['hora_24']])
            ->assertJsonPath('data.agendada', true);

        $repetida = $this->invoke('crear_cita', ['servicio' => 'Tradicional', 'fecha' => $this->manana(), 'hora' => $horas[1]['hora_24']]);

        $repetida->assertJsonPath('data.agendada', false);
        $this->assertNotNull($repetida->json('data.ya_tiene_cita'));
        $this->assertSame(1, Appointment::withoutGlobalScopes()->count());
    }

    public function test_la_cita_exacta_que_ya_existe_no_se_duplica(): void
    {
        $horas = $this->invoke('disponibilidad', ['servicio' => 'Semipermanente', 'fecha' => $this->manana()])
            ->json('data.ofrecidas');
        $this->invoke('crear_cita', ['servicio' => 'Semipermanente', 'fecha' => $this->manana(), 'hora' => $horas[0]['hora_24']])
            ->assertJsonPath('data.agendada', true);

        // El modelo (o un doble toque) repite la misma llamada, tal cual.
        $repetida = $this->invoke('crear_cita', ['servicio' => 'Semipermanente', 'fecha' => $this->manana(), 'hora' => $horas[0]['hora_24']]);

        $repetida->assertJsonPath('data.agendada', false)
            ->assertJsonPath('data.ya_existia', true);
        $this->assertSame(1, Appointment::withoutGlobalScopes()->count());
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
