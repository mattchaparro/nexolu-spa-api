<?php

namespace Tests\Feature\Ai;

use App\Ai\DateInText;
use App\Ai\GuidedEntry;
use App\Ai\Toques;
use App\Ai\UltimoPedido;
use App\Models\Appointment;
use App\Models\Business;
use App\Models\Client;
use App\Models\ClientPenalty;
use App\Models\LoyaltyProgram;
use App\Models\LoyaltyStamp;
use App\Models\Message;
use App\Models\Resource;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\WhatsappConversation;
use App\Services\Scheduling\BookingService;
use App\Support\ChannelPhone;
use App\Support\Money\LoyaltyCalculator;
use App\Support\PermissionCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Scheduling\SchedulingScenario;
use Tests\TestCase;

/**
 * La conversación inicial obligatoria, como la definió Alejandro:
 *
 *   [Agendar] [Mis citas] [Otra consulta]
 *     Agendar       → [Agendar aquí] [Agendar en la web]
 *     Mis citas     → lista → una cita → [Reagendar] [Cancelar] [Volver]
 *     Otra consulta → [Daño / Garantía] [Hablar con el admin] [Otra pregunta]
 *       Daño / Garantía → [Mi último servicio] [Otro servicio] → al equipo,
 *       con la garantía atribuida a quien hizo el trabajo original.
 */
class GuidedEntryTest extends TestCase
{
    use RefreshDatabase, SchedulingScenario;

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
        config()->set('services.comms_core.api_key', 'llave-comms');
        config()->set('services.comms_core.base_url', 'http://comms.test');
        config()->set('spa.public_booking_url', 'https://agenda.test');
        Http::fake([
            'comms.test/*' => Http::response(['results' => [['channel' => 'whatsapp', 'status' => 'sent']]]),
        ]);

        $this->business = $this->makeBusiness(['min_booking_notice_min' => 0]);
        $this->business->forceFill(['slug' => 'luxury'])->save();
        $maria = $this->makeResource($this->business, 'Maria', '09:00:00', '18:00:00');
        $manicure = ServiceCategory::create(['business_id' => $this->business->id, 'name' => 'Manicure', 'is_active' => true]);

        foreach (['Semipermanente', 'Semi + Rubber', 'Tradicional'] as $nombre) {
            $this->makeService($this->business, 60, [$maria], name: $nombre)
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

        UltimoPedido::olvidar($this->phone());
    }

    private function phone(): string
    {
        return (string) ChannelPhone::normalize(self::PHONE);
    }

    /** @return array{text: string, conversation_id: null, tools_used: list<string>}|null */
    private function escribe(string $texto): ?array
    {
        return app(GuidedEntry::class)->attend($this->conversacion->fresh(['business', 'client']), $texto);
    }

    /** @return list<string> */
    private function ultimosBotones(): array
    {
        $titulos = [];
        Http::assertSent(function ($request) use (&$titulos) {
            $opciones = $request->data()['whatsapp_options']['options'] ?? null;
            if ($opciones !== null) {
                $titulos = array_column($opciones, 'title');
            }

            return true;
        });

        return $titulos;
    }

    private function cita(string $servicio, CarbonImmutable $inicio): Appointment
    {
        return app(BookingService::class)->book(
            $this->business,
            [[
                'service_id' => Service::withoutGlobalScope('business')->where('name', $servicio)->sole()->id,
                'resource_id' => Resource::withoutGlobalScope('business')->where('name', 'Maria')->sole()->id,
                'starts_at' => $inicio,
            ]],
            $this->conversacion->client,
            'Carolina',
            self::PHONE,
            Appointment::SOURCE_WHATSAPP_AGENT,
            null,
        );
    }

    private function manana(int $hora): CarbonImmutable
    {
        return CarbonImmutable::now('America/Bogota')->addDay()->setTime($hora, 0);
    }

    private function elBotAcabaDeHablar(): void
    {
        Message::create([
            'business_id' => $this->business->id,
            'conversation_id' => $this->conversacion->id,
            'kind' => Message::KIND_AGENT,
            'direction' => Message::DIRECTION_OUT,
            'to' => $this->conversacion->phone,
            'body' => '¡Hola Carolina! ¿En qué te ayudo?',
            'status' => Message::STATUS_SENT,
            'sent_at' => now(),
        ]);
    }

    // -- El menú de inicio --------------------------------------------------

    public function test_un_saludo_abre_el_menu_de_inicio(): void
    {
        $respuesta = $this->escribe('Hola, buenas noches');

        $this->assertSame('', $respuesta['text']);
        $this->assertSame([GuidedEntry::BOOK, GuidedEntry::MY_APPOINTMENTS, GuidedEntry::OTHER], $this->ultimosBotones());
        // Como en el mostrador: el nombre, el negocio y la pregunta.
        Http::assertSent(fn ($r) => ($r->data()['text'] ?? '')
            === "¡Hola, Carolina! 👋\nTe damos la bienvenida a *Spa de prueba* 💅\n\n¿Qué deseas hacer el día de hoy?"
                ."\n\n_Escribe *reiniciar* en cualquier momento para volver a este menú._");
    }

    public function test_el_primer_mensaje_de_la_conversacion_abre_el_menu_aunque_no_sea_saludo(): void
    {
        // Obligatorio al empezar: el bot no ha hablado en media hora.
        $this->assertNotNull($this->escribe('tienen parqueadero?'));
        $this->assertSame([GuidedEntry::BOOK, GuidedEntry::MY_APPOINTMENTS, GuidedEntry::OTHER], $this->ultimosBotones());
    }

    public function test_en_plena_conversacion_una_pregunta_es_del_modelo(): void
    {
        $this->elBotAcabaDeHablar();

        $this->assertNull($this->escribe('tienen parqueadero?'));
    }

    public function test_el_nombre_raro_no_se_usa_en_el_saludo(): void
    {
        $this->conversacion->client->forceFill(['name' => '🦋 Yess 🦋'])->save();

        $this->escribe('Hola');

        Http::assertSent(fn ($r) => str_starts_with($r->data()['text'] ?? '', "¡Hola! 👋\nTe damos la bienvenida a *Spa de prueba*"));
    }

    public function test_con_una_confirmacion_esperando_no_interrumpe(): void
    {
        UltimoPedido::guardar($this->phone(), [
            'servicios' => ['Semipermanente'],
            'confirmar' => ['hora_24' => '10:00', 'hora' => '10 am'],
        ]);

        $this->assertNull($this->escribe('quiero una cita'));
    }

    // -- Agendar ------------------------------------------------------------

    public function test_agendar_ofrece_aqui_o_en_la_web_y_aqui_trae_los_mas_pedidos(): void
    {
        $this->escribe('Hola');
        $this->escribe(GuidedEntry::BOOK);

        $this->assertSame([GuidedEntry::HERE, GuidedEntry::WEB], $this->ultimosBotones());

        $respuesta = $this->escribe(GuidedEntry::HERE);

        $this->assertSame(['menu_inicial'], $respuesta['tools_used']);
        Http::assertSent(fn ($r) => str_contains($r->data()['text'] ?? '', 'los servicios que más nos piden'));
        $this->assertContains('Semipermanente', UltimoPedido::ver($this->phone())['opciones']);
    }

    public function test_agendar_en_la_web_manda_el_link(): void
    {
        $this->escribe('quiero agendar');
        $respuesta = $this->escribe(GuidedEntry::WEB);

        $this->assertStringContainsString('https://agenda.test/reservar/luxury', $respuesta['text']);
    }

    public function test_hola_quiero_agendar_saluda_antes_de_preguntar(): void
    {
        // Ir al grano sin un hola es descortés: si abre la conversación,
        // primero la bienvenida.
        $this->escribe('Hola, quiero agendar');

        Http::assertSent(fn ($r) => str_contains($r->data()['text'] ?? '', 'Te damos la bienvenida a *Spa de prueba*')
            && str_contains($r->data()['text'] ?? '', '¿Cómo prefieres agendar?'));
    }

    public function test_quiero_una_cita_salta_directo_a_como_agendar(): void
    {
        $this->elBotAcabaDeHablar();

        $this->escribe('quiero agendar una cita');

        $this->assertSame([GuidedEntry::HERE, GuidedEntry::WEB], $this->ultimosBotones());
    }

    public function test_si_ya_dijo_el_servicio_va_por_el_camino_normal(): void
    {
        $this->assertNull($this->escribe('Quiero una cita de manicure'));
        $this->assertNull($this->escribe('cita para semipermanente mañana'));
    }

    public function test_la_fecha_dicha_llega_hasta_las_horas(): void
    {
        // "cita para mañana en la tarde" → cómo → aquí → servicio → horas
        // de MAÑANA, sin modelo y sin repetir nada.
        DateInText::remember($this->phone(), 'Hola, quiero una cita para mañana en la tarde');
        $this->escribe('Hola, quiero una cita para mañana en la tarde');
        $this->escribe(GuidedEntry::HERE);

        $respuesta = app(Toques::class)->atender($this->conversacion->fresh(['business', 'client']), 'Semipermanente');

        $this->assertSame('', $respuesta['text']);
        $manana = CarbonImmutable::now('America/Bogota')->addDay()->locale('es')->isoFormat('dddd D [de] MMMM');
        Http::assertSent(fn ($r) => str_contains($r->data()['text'] ?? '', 'Para *Semipermanente*')
            && str_contains($r->data()['text'] ?? '', $manana));
    }

    // -- Mis citas ----------------------------------------------------------

    public function test_sin_citas_ofrece_agendar(): void
    {
        $this->escribe('Hola');
        $this->escribe(GuidedEntry::MY_APPOINTMENTS);

        Http::assertSent(fn ($r) => str_contains($r->data()['text'] ?? '', 'No tienes citas próximas'));
        $this->assertSame([GuidedEntry::BOOK, GuidedEntry::OTHER], $this->ultimosBotones());
    }

    public function test_mis_citas_lista_elige_y_cancela(): void
    {
        $this->cita('Semipermanente', $this->manana(9));
        $segunda = $this->cita('Tradicional', $this->manana(11));

        $this->escribe('Hola');
        $this->escribe(GuidedEntry::MY_APPOINTMENTS);

        // Una fila por cita: el día y la hora en el título.
        $filas = $this->ultimosBotones();
        $this->assertCount(2, $filas);

        $this->escribe($filas[1]);
        $this->assertSame([GuidedEntry::RESCHEDULE, GuidedEntry::CANCEL, GuidedEntry::BACK], $this->ultimosBotones());
        Http::assertSent(fn ($r) => str_contains($r->data()['text'] ?? '', 'Tu cita: *Tradicional*'));

        $this->escribe(GuidedEntry::CANCEL);
        $this->assertSame([GuidedEntry::CONFIRM_CANCEL, GuidedEntry::KEEP], $this->ultimosBotones());

        $this->escribe(GuidedEntry::CONFIRM_CANCEL);

        $this->assertSame(Appointment::STATUS_CANCELLED, $segunda->fresh()->status);
        $this->assertSame(1, Appointment::withoutGlobalScopes()->where('status', '!=', Appointment::STATUS_CANCELLED)->count());
    }

    public function test_cancelar_tarde_se_puede_con_la_multa_avisada_antes(): void
    {
        /*
         * Alejandro: dentro de las 3 horas antes era un "no se puede", pero
         * la clienta igual no iba a llegar y el cupo tampoco se liberaba.
         * Se puede cancelar, con la multa -- dicha ANTES de confirmar.
         */
        $this->business->forceFill(['scheduling_settings' => [
            ...($this->business->scheduling_settings ?? []),
            'min_booking_notice_min' => 0,
            'late_cancellation_penalty_amount' => 10000,
        ]])->save();
        $cita = $this->cita('Semipermanente', CarbonImmutable::now('America/Bogota')->addHour()->startOfHour()->addHour());

        $this->escribe(GuidedEntry::MY_APPOINTMENTS);
        $this->escribe(GuidedEntry::CANCEL);

        Http::assertSent(fn ($r) => str_contains($r->data()['text'] ?? '', 'multa por cancelación tardía de *$10.000*'));
        $this->assertSame([GuidedEntry::CONFIRM_CANCEL, GuidedEntry::KEEP], $this->ultimosBotones());
        $this->assertNotSame(Appointment::STATUS_CANCELLED, $cita->fresh()->status);

        $this->escribe(GuidedEntry::CONFIRM_CANCEL);

        // Cancelada, el cupo libre, la multa en la ficha y el equipo avisado.
        $this->assertSame(Appointment::STATUS_CANCELLED, $cita->fresh()->status);
        $multa = ClientPenalty::withoutGlobalScope('business')->sole();
        $this->assertSame(ClientPenalty::KIND_LATE_CANCELLATION, $multa->kind);
        $this->assertEquals(10000, $multa->amount);
        $this->assertSame($cita->id, $multa->appointment_id);
        $this->assertTrue(Message::withoutGlobalScope('business')
            ->where('kind', Message::KIND_STAFF)->where('body', 'like', '%Cancelación TARDÍA%')->exists());
        Http::assertSent(fn ($r) => str_contains($r->data()['text'] ?? '', 'Quedó registrada la multa'));
    }

    public function test_sin_multa_propia_vale_la_de_inasistencia(): void
    {
        $this->business->forceFill(['scheduling_settings' => [
            ...($this->business->scheduling_settings ?? []),
            'no_show_penalty_amount' => 15000,
        ]])->save();
        $this->cita('Semipermanente', CarbonImmutable::now('America/Bogota')->addHour()->startOfHour()->addHour());

        $this->escribe(GuidedEntry::MY_APPOINTMENTS);
        $this->escribe(GuidedEntry::CANCEL);

        Http::assertSent(fn ($r) => str_contains($r->data()['text'] ?? '', '*$15.000*'));
    }

    public function test_mis_citas_no_muestra_las_de_hoy_que_ya_pasaron(): void
    {
        // Abrió "Mis citas" al mediodía y le salió la de las 9 am de ese
        // mismo día, que ya había pasado.
        $this->travelTo(CarbonImmutable::now('America/Bogota')->setTime(9, 0));
        $this->cita('Semipermanente', CarbonImmutable::now('America/Bogota')->setTime(10, 0));
        $this->travelTo(CarbonImmutable::now('America/Bogota')->setTime(12, 0));

        $this->escribe(GuidedEntry::MY_APPOINTMENTS);

        Http::assertSent(fn ($r) => str_contains($r->data()['text'] ?? '', 'No tienes citas próximas'));
    }

    public function test_quiero_ver_mis_citas_escrito_va_a_la_lista(): void
    {
        $this->cita('Semipermanente', $this->manana(9));
        $this->elBotAcabaDeHablar();

        $this->escribe('Quiero ver mis citas');

        Http::assertSent(fn ($r) => str_contains($r->data()['text'] ?? '', 'Tu cita: *Semipermanente*'));
    }

    public function test_por_aqui_escrito_tambien_elige_agendar_aqui(): void
    {
        $this->escribe('quiero agendar');
        $respuesta = $this->escribe('Dale, mejor por aquí');

        $this->assertSame(['menu_inicial'], $respuesta['tools_used']);
    }

    public function test_una_cita_tocada_se_encuentra_aunque_el_titulo_vuelva_recortado(): void
    {
        /*
         * Alejandro tocó la fila «Sáb. 26 sep. · 3:30 pm» y WhatsApp
         * devolvió «Sáb. 26 sep. · 3:30», sin el "pm". El bot buscaba el
         * título exacto, no encontraba la cita, y la cancelación terminaba
         * en un "no entendí la fecha".
         */
        $this->cita('Semipermanente', $this->manana(9));
        $this->cita('Tradicional', $this->manana(11));

        $this->escribe(GuidedEntry::MY_APPOINTMENTS);
        $filas = $this->ultimosBotones();

        // Como vuelve de WhatsApp: cortado.
        $recortada = mb_substr($filas[0], 0, mb_strlen($filas[0]) - 3);
        $this->escribe($recortada);

        $this->assertSame([GuidedEntry::RESCHEDULE, GuidedEntry::CANCEL, GuidedEntry::BACK], $this->ultimosBotones());
    }

    public function test_no_dejarla_no_cancela_nada(): void
    {
        $cita = $this->cita('Semipermanente', $this->manana(9));

        $this->escribe(GuidedEntry::MY_APPOINTMENTS);
        $this->escribe('Hola'); // reinicia
        $this->escribe(GuidedEntry::MY_APPOINTMENTS);
        $this->escribe(GuidedEntry::CANCEL);
        $respuesta = $this->escribe(GuidedEntry::KEEP);

        $this->assertStringContainsString('sigue en pie', $respuesta['text']);
        $this->assertNotSame(Appointment::STATUS_CANCELLED, $cita->fresh()->status);
    }

    public function test_reagendar_desde_mis_citas_pregunta_el_dia_y_el_si_la_mueve(): void
    {
        $cita = $this->cita('Semipermanente', $this->manana(9));

        $this->escribe('Hola');
        $this->escribe(GuidedEntry::MY_APPOINTMENTS); // una sola: abre directo
        $this->escribe(GuidedEntry::RESCHEDULE);

        $this->assertSame(['Hoy', 'Mañana', 'Otro día'], $this->ultimosBotones());

        $toques = app(Toques::class);
        $toques->atender($this->conversacion->fresh(['business', 'client']), 'Mañana');
        Http::assertSent(fn ($r) => str_contains($r->data()['text'] ?? '', 'movemos tu cita de *Semipermanente*'));

        $nueva = array_values(UltimoPedido::ver($this->phone())['horas'])[1];
        $toques->atender($this->conversacion->fresh(['business', 'client']), $nueva['hora']);
        $respuesta = $toques->atender($this->conversacion->fresh(['business', 'client']), Toques::SI_MOVER);

        $this->assertSame(['reagendar_cita'], $respuesta['tools_used']);
        $quedo = Appointment::withoutGlobalScopes()->sole();
        $this->assertSame($cita->id, $quedo->id);
        $this->assertSame($nueva['hora_24'], $quedo->starts_at->timezone('America/Bogota')->format('H:i'));
    }

    public function test_cambiar_mi_cita_escrito_va_directo_a_horas(): void
    {
        $this->cita('Semipermanente', $this->manana(9));

        DateInText::remember($this->phone(), 'Hola! Necesito cambiar mi cita de mañana');
        $respuesta = $this->escribe('Hola! Necesito cambiar mi cita de mañana');

        $this->assertSame(['mover_cita'], $respuesta['tools_used']);
        Http::assertSent(fn ($r) => str_contains($r->data()['text'] ?? '', 'movemos tu cita de *Semipermanente*'));
    }

    public function test_cambiar_con_varias_citas_muestra_la_lista(): void
    {
        $this->cita('Semipermanente', $this->manana(9));
        $this->cita('Tradicional', $this->manana(11));

        $this->escribe('quiero cambiar mi cita');

        $this->assertCount(2, $this->ultimosBotones());
    }

    // -- Otra consulta -----------------------------------------------------

    public function test_otra_consulta_y_hablar_con_el_admin_pasa_a_una_persona(): void
    {
        $this->escribe('Hola');
        $this->escribe(GuidedEntry::OTHER);

        $this->assertSame([GuidedEntry::WARRANTY, GuidedEntry::ADMIN, GuidedEntry::QUESTION], $this->ultimosBotones());

        $respuesta = $this->escribe(GuidedEntry::ADMIN);

        $this->assertStringContainsString('Ya le avisé al equipo', $respuesta['text']);
        $this->assertTrue($this->conversacion->fresh()->agentIsPaused());
    }

    public function test_otra_pregunta_le_abre_la_puerta_al_modelo(): void
    {
        $this->escribe('Hola');
        $this->escribe(GuidedEntry::OTHER);
        $respuesta = $this->escribe(GuidedEntry::QUESTION);

        $this->assertStringContainsString('¿en qué te ayudo?', $respuesta['text']);
        $this->elBotAcabaDeHablar();
        $this->assertNull($this->escribe('¿tienen parqueadero?'));
    }

    public function test_garantia_por_el_ultimo_servicio_llega_al_equipo_atribuida(): void
    {
        /*
         * La garantía se le anota a quien hizo el trabajo ORIGINAL: el
         * conteo por persona es la señal de quién está haciendo mal su
         * trabajo. El bot no decide si es garantía, pero le deja al equipo
         * el servicio, la cita y quién lo hizo.
         */
        $this->travel(-2)->days();
        $original = $this->cita('Semipermanente', CarbonImmutable::now('America/Bogota')->setTime(10, 0));
        $this->travel(2)->days();

        $this->escribe('Hola');
        $this->escribe(GuidedEntry::OTHER);
        $this->escribe(GuidedEntry::WARRANTY);

        $this->assertSame([GuidedEntry::LAST_SERVICE, GuidedEntry::OTHER_SERVICE], $this->ultimosBotones());
        Http::assertSent(fn ($r) => str_contains($r->data()['text'] ?? '', 'Tu último servicio fue *Semipermanente*'));

        $respuesta = $this->escribe(GuidedEntry::LAST_SERVICE);

        $this->assertStringContainsString('foto', $respuesta['text']);
        $this->assertTrue($this->conversacion->fresh()->agentIsPaused());

        $nota = Message::withoutGlobalScope('business')->where('kind', Message::KIND_STAFF)->sole()->body;
        $this->assertStringContainsString('GARANTÍA', $nota);
        $this->assertStringContainsString('anotarla a Maria', $nota);
        $this->assertStringContainsString('cita #'.$original->id, $nota);
    }

    public function test_se_me_cayo_el_esmalte_va_directo_a_garantia(): void
    {
        $this->escribe('hola, se me cayó el esmalte de una uña');

        $this->assertSame([GuidedEntry::LAST_SERVICE, GuidedEntry::OTHER_SERVICE], $this->ultimosBotones());
    }

    public function test_escribir_otra_cosa_suelta_el_menu(): void
    {
        $this->escribe('Hola');
        $this->elBotAcabaDeHablar();

        // No tocó ningún botón: es conversación, y el menú no la atrapa.
        $this->assertNull($this->escribe('una pregunta, ¿aceptan tarjeta?'));
        $this->assertArrayNotHasKey('menu', UltimoPedido::ver($this->phone()));
    }

    // -- El retoque ---------------------------------------------------------

    /** Su última visita, hace `$haceDias`. */
    private function visitaPasada(int $haceDias, string $servicio = 'Semipermanente'): Appointment
    {
        $this->travel(-$haceDias)->days();
        $cita = $this->cita($servicio, CarbonImmutable::now('America/Bogota')->setTime(10, 0));
        $this->travel($haceDias)->days();

        return $cita;
    }

    // -- Los botones del recordatorio --------------------------------------

    public function test_confirmo_que_voy_deja_la_cita_confirmada(): void
    {
        /*
         * Es lo que el salón hace hoy llamando una por una. La cita pasa por
         * la máquina de estados --no se le escribe el estado a mano-- para
         * que quede el registro y el tablero del mostrador diga lo mismo que
         * la ficha.
         */
        $cita = $this->cita('Semipermanente', $this->manana(11));

        $respuesta = $this->escribe(GuidedEntry::CONFIRM_ATTENDANCE);

        $this->assertSame(['confirmar_asistencia'], $respuesta['tools_used']);
        $this->assertStringContainsString('Te esperamos', $respuesta['text']);
        $this->assertSame(Appointment::STATUS_CONFIRMED, $cita->fresh()->status);
    }

    public function test_confirmar_no_le_reenvia_la_confirmacion(): void
    {
        /*
         * Sin la guarda de «lo hizo el cliente», mover la cita de etapa le
         * mandaría la confirmación entera un segundo después de que el bot
         * ya le respondió: dos mensajes para un solo toque.
         */
        $this->cita('Semipermanente', $this->manana(11));

        $this->escribe(GuidedEntry::CONFIRM_ATTENDANCE);

        $this->assertSame(0, Message::withoutGlobalScope('business')->where('kind', Message::KIND_STAGE)->count());
    }

    public function test_cancelar_cita_pregunta_antes_de_cancelar(): void
    {
        // Con una sola cita va directo a la pregunta; cancelar sin preguntar
        // por un toque sería imperdonable.
        $this->cita('Semipermanente', $this->manana(11));

        $this->escribe(GuidedEntry::CANCEL_APPOINTMENT);

        $this->assertSame([GuidedEntry::CONFIRM_CANCEL, GuidedEntry::KEEP], $this->ultimosBotones());
    }

    public function test_cancelar_cita_con_varias_muestra_la_lista(): void
    {
        // Cancelar la que no era es peor que un toque de más.
        $this->cita('Semipermanente', $this->manana(11));
        $this->cita('Tradicional', $this->manana(15));

        $this->escribe(GuidedEntry::CANCEL_APPOINTMENT);

        $this->assertCount(2, $this->ultimosBotones());
    }

    public function test_sin_citas_confirmar_no_rompe_nada(): void
    {
        $respuesta = $this->escribe(GuidedEntry::CONFIRM_ATTENDANCE);

        $this->assertStringContainsString('No veo citas próximas', $respuesta['text']);
    }

    // -- El final de la visita ---------------------------------------------

    public function test_calificar_servicio_le_manda_el_enlace(): void
    {
        /*
         * El botón que va detrás del «Gracias por tu visita». El token se
         * crea al TOCARLO, no al mandar el mensaje: así `survey_sent_at`
         * dice cuándo se le ofreció de verdad.
         */
        $cita = $this->visitaPasada(1);

        $respuesta = $this->escribe(GuidedEntry::RATE);

        $this->assertSame(['calificar'], $respuesta['tools_used']);
        $this->assertStringContainsString('/encuesta/'.$cita->fresh()->survey_token, $respuesta['text']);
        $this->assertNotNull($cita->fresh()->survey_sent_at);
    }

    public function test_mi_tarjeta_dice_cuantos_sellos_lleva(): void
    {
        /*
         * "Te faltan 3" es una razón concreta para volver, y es información
         * que la clienta no tiene de otra forma: en el mostrador nadie se la
         * dice. En ManyChat se pedía escribiendo «Mi tarjeta»; acá también
         * se puede tocar.
         */
        $programa = LoyaltyProgram::create([
            'business_id' => $this->business->id,
            'name' => 'Tarjeta Luxury',
            'mode' => 'simple',
            'stamps_required' => 10,
            'reward_type' => LoyaltyCalculator::REWARD_DISCOUNT_PERCENT,
            'reward_value' => 15,
            'min_ticket' => 0,
            'is_active' => true,
        ]);

        // Siete días atrás: el mismo día de la semana, así Maria trabaja.
        $visita = $this->visitaPasada(7);
        LoyaltyStamp::create([
            'business_id' => $this->business->id,
            'program_id' => $programa->id,
            'client_id' => $this->conversacion->client_id,
            'appointment_id' => $visita->id,
            'earned_at' => now()->subDays(7),
        ]);

        $respuesta = $this->escribe(GuidedEntry::MY_CARD);

        $this->assertSame(['mi_tarjeta'], $respuesta['tools_used']);
        $this->assertStringContainsString('*1 de 10* sellos', $respuesta['text']);
        $this->assertStringContainsString('15% de descuento', $respuesta['text']);
        $this->assertStringContainsString('Te faltan *9 sellos*', $respuesta['text']);
    }

    public function test_sin_programa_de_sellos_no_inventa_una_tarjeta(): void
    {
        $respuesta = $this->escribe(GuidedEntry::MY_CARD);

        $this->assertStringContainsString('no tenemos tarjeta de sellos', $respuesta['text']);
    }

    public function test_agendar_retoque_solo_pregunta_el_dia(): void
    {
        /*
         * LA mejora sobre ManyChat. Allá el botón devolvía al inicio y había
         * que repetir todo -- servicio, manicurista, día, hora -- para
         * llegar a lo mismo de siempre. Acá el único dato que falta es el
         * día, porque es el único que el salón no puede saber.
         */
        $this->visitaPasada(21);

        $respuesta = $this->escribe(GuidedEntry::RETOUCH);

        $this->assertSame('', $respuesta['text']);
        $this->assertSame(['retoque'], $respuesta['tools_used']);
        $this->assertSame(['Hoy', 'Mañana', 'Otro día'], $this->ultimosBotones());

        // Y se lo dice, para que vea que no tiene que repetir nada.
        Http::assertSent(fn ($r) => str_contains($r->data()['text'] ?? '', 'Te repito tu *Semipermanente* con *Maria*'));

        $pedido = UltimoPedido::ver($this->phone());
        $this->assertSame(['Semipermanente'], $pedido['servicios']);
        $this->assertSame('Maria', $pedido['empleado']);
    }

    public function test_el_retoque_no_vuelve_a_preguntar_por_la_manicurista(): void
    {
        // `empleado_preguntado` es lo que apaga ese paso: ya se preguntó, en
        // su última visita.
        $this->visitaPasada(21);
        $this->escribe(GuidedEntry::RETOUCH);

        $this->assertTrue(UltimoPedido::ver($this->phone())['empleado_preguntado']);
        $this->assertNotContains('¡Cualquiera!', $this->ultimosBotones());
    }

    public function test_si_la_manicurista_ya_no_esta_el_retoque_sigue_con_el_servicio(): void
    {
        /*
         * Prometerle la de siempre y que no esté es peor que no ofrecerla:
         * la clienta elige día y hora contando con alguien que renunció.
         */
        $this->visitaPasada(21);
        Resource::withoutGlobalScope('business')->where('name', 'Maria')->sole()->update(['is_active' => false]);

        $this->escribe(GuidedEntry::RETOUCH);

        $pedido = UltimoPedido::ver($this->phone());
        $this->assertSame(['Semipermanente'], $pedido['servicios']);
        $this->assertArrayNotHasKey('empleado', $pedido);
    }

    public function test_sin_visita_anterior_el_retoque_sigue_el_camino_normal(): void
    {
        /*
         * No debería pasar -- el recordatorio sale de una visita -- pero si
         * pasa, el atajo se aparta en vez de inventarse un servicio: "Agendar
         * retoque" es un agendamiento como cualquier otro y lo lleva el
         * camino de siempre, sin dejar nada a medias en el pedido.
         */
        $respuesta = $this->escribe(GuidedEntry::RETOUCH);

        $this->assertNotSame(['retoque'], $respuesta['tools_used'] ?? []);
        $this->assertArrayNotHasKey('servicios', UltimoPedido::ver($this->phone()));
    }

    public function test_darse_de_baja_apaga_los_mensajes_del_spa(): void
    {
        /*
         * El botón que Alejandro señaló en el mensaje de ManyChat, y con
         * razón: quien no puede salirse bloquea el número -- y un bloqueo se
         * lleva por delante también los recordatorios de su propia cita.
         *
         * Se apaga `accepts_marketing`, la MISMA llave que ya frena las
         * difusiones: darse de baja se pide una vez, no una por cada tipo de
         * mensaje que inventemos después.
         */
        $respuesta = $this->escribe(GuidedEntry::UNSUBSCRIBE);

        $this->assertSame(['darse_de_baja'], $respuesta['tools_used']);
        $this->assertFalse((bool) $this->conversacion->client->fresh()->accepts_marketing);

        // Y se le dice qué sigue llegando: lo de sus propias citas.
        Http::assertSent(fn ($r) => str_contains($r->data()['text'] ?? '', 'tus propias citas'));
        $this->assertSame([GuidedEntry::RESUBSCRIBE], $this->ultimosBotones());
    }

    public function test_volver_a_recibir_cuesta_lo_mismo_que_salirse(): void
    {
        $this->escribe(GuidedEntry::UNSUBSCRIBE);

        $respuesta = $this->escribe(GuidedEntry::RESUBSCRIBE);

        $this->assertStringContainsString('de vuelta', $respuesta['text']);
        $this->assertTrue((bool) $this->conversacion->client->fresh()->accepts_marketing);
    }

    public function test_empezar_de_cero_abre_el_catalogo_sin_nada_pegado(): void
    {
        $this->visitaPasada(21);
        $this->escribe(GuidedEntry::RETOUCH);

        $this->escribe(GuidedEntry::FROM_SCRATCH);

        // El servicio anterior no se arrastra: quería otra cosa.
        $pedido = UltimoPedido::ver($this->phone());
        $this->assertArrayNotHasKey('empleado', $pedido);
        $this->assertArrayNotHasKey('servicios', $pedido);
        // Y ve el catálogo completo, como quien llega nueva.
        $this->assertContains('Tradicional', $pedido['opciones']);
    }
}
