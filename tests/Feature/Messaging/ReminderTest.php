<?php

namespace Tests\Feature\Messaging;

use App\Models\Appointment;
use App\Models\Business;
use App\Models\Client;
use App\Models\Message;
use App\Models\Resource;
use App\Models\Service;
use App\Services\Messaging\Contracts\MessagingChannel;
use App\Services\Messaging\ReminderService;
use App\Services\Scheduling\BookingService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Scheduling\SchedulingScenario;
use Tests\Support\FakeMessagingChannel;
use Tests\TestCase;

/**
 * Recordatorios de cita.
 *
 * Es la función que más se pide y la que más se hace mal. Lo que decide si
 * sirve no es mandar el mensaje: es CUÁNDO se manda y A QUÉ citas.
 */
class ReminderTest extends TestCase
{
    use RefreshDatabase, SchedulingScenario;

    private Business $business;

    private Resource $maria;

    private Service $manicure;

    private Client $carolina;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(
            CarbonImmutable::now('America/Bogota')->startOfDay()
                ->previous(CarbonImmutable::WEDNESDAY)->setTime(8, 0),
        );

        $this->business = $this->makeBusiness([
            'min_booking_notice_min' => 0,
            'reminder_hours_before' => 24,
        ]);

        $this->maria = $this->makeResource($this->business, 'Maria');
        $this->manicure = $this->makeService($this->business, 60, [$this->maria]);

        $this->carolina = Client::create([
            'business_id' => $this->business->id,
            'name' => 'Carolina', 'last_name' => 'Pérez',
            'phone' => '+573001112233', 'is_active' => true,
        ]);
    }

    private function reminders(): ReminderService
    {
        return $this->app->make(ReminderService::class);
    }

    private function ahora(): CarbonImmutable
    {
        return CarbonImmutable::now('America/Bogota');
    }

    /**
     * Agenda una cita que arranca en `$enHoras`, creada hace `$creadaHace`.
     *
     * Las dos cosas importan: la ventana mira cuándo EMPIEZA, y la regla de
     * "no recordar lo que se agendó tarde" mira cuándo SE CREÓ.
     */
    private function agendar(int $enHoras, int $creadaHaceHoras = 72): Appointment
    {
        $cita = app(BookingService::class)->book(
            $this->business,
            [[
                'service_id' => $this->manicure->id,
                'resource_id' => $this->maria->id,
                'starts_at' => $this->ahora()->addHours($enHoras),
            ]],
            $this->carolina,
            'Carolina Pérez',
            '+573001112233',
            Appointment::SOURCE_ADMIN,
            null,
            false,
        );

        /*
         * `created_at` se escribe con un UPDATE directo, y EN UTC.
         *
         * Lo de UTC no es cosmético: un `update()` de query builder guarda el
         * Carbon tal cual se le pasa, sin convertir. Escribirlo en hora Bogotá
         * lo dejaba cinco horas antes de lo que la prueba creía, y el caso que
         * quería medir — "se agendó tarde" — pasaba el filtro. La prueba habría
         * quedado verde probando lo contrario de su nombre.
         */
        Appointment::withoutGlobalScopes()
            ->whereKey($cita->id)
            ->update(['created_at' => $this->ahora()->subHours($creadaHaceHoras)->utc()]);

        return $cita->fresh();
    }

    // ---- La ventana ----

    public function test_prepara_el_recordatorio_de_una_cita_de_manana(): void
    {
        $cita = $this->agendar(enHoras: 20);

        $this->assertSame(['queued' => 1, 'skipped' => 0], $this->reminders()->run($this->business));

        $mensaje = Message::withoutGlobalScopes()->first();
        $this->assertSame(Message::KIND_REMINDER, $mensaje->kind);
        $this->assertSame($cita->id, $mensaje->appointment_id);
        $this->assertStringContainsString('Carolina', $mensaje->body);
        $this->assertStringContainsString('Maria', $mensaje->body);
    }

    public function test_el_recordatorio_lleva_como_mover_la_cita(): void
    {
        /*
         * Ahí está la diferencia entre un recordatorio que sirve y uno que no:
         * si la persona no va a poder, tiene que poder moverla EN ESE MOMENTO.
         * Uno sin salida sólo consigue que la inasistencia llegue avisada.
         */
        $this->agendar(enHoras: 20);
        $this->reminders()->run($this->business);

        $this->assertStringContainsString('/mis-citas/', Message::withoutGlobalScopes()->first()->body);
    }

    public function test_no_alcanza_a_las_citas_de_pasado_manana(): void
    {
        // Le toca en una corrida futura, no en esta.
        $this->agendar(enHoras: 50);

        $this->assertSame(0, $this->reminders()->run($this->business)['queued']);
    }

    public function test_no_recuerda_lo_que_ya_empezo(): void
    {
        // El cliente está en la silla, o no vino. Un recordatorio ahí es ruido.
        $this->agendar(enHoras: -2, creadaHaceHoras: 72);

        $this->assertSame(0, $this->reminders()->run($this->business)['queued']);
    }

    public function test_una_corrida_perdida_se_recupera_en_la_siguiente(): void
    {
        /*
         * La ventana es ABIERTA -- las citas que arrancan dentro de las
         * próximas N horas -- no un instante exacto. Si el comando no corrió
         * porque el servidor estaba caído, la siguiente corrida las alcanza.
         *
         * Con un instante exacto, un minuto de caída son las citas de ese
         * minuto sin avisar y nadie se entera nunca.
         */
        $this->agendar(enHoras: 3, creadaHaceHoras: 72);

        $this->assertSame(1, $this->reminders()->run($this->business)['queued']);
    }

    // ---- No recordar lo que se agendó tarde ----

    public function test_no_recuerda_una_cita_que_se_acaba_de_agendar(): void
    {
        /*
         * Si alguien reservó hace dos horas para mañana, no necesita que le
         * recuerden algo que acaba de decidir. Sin esta regla, cada reserva de
         * última hora dispara un mensaje redundante que lee como spam.
         */
        $this->agendar(enHoras: 20, creadaHaceHoras: 2);

        $this->assertSame(0, $this->reminders()->run($this->business)['queued']);
    }

    public function test_si_se_agendo_antes_del_punto_de_recordatorio_si_le_toca(): void
    {
        // Creada hace 30 horas para dentro de 20: cuando tocaba recordarla
        // (starts_at − 24h) la cita ya existía.
        $this->agendar(enHoras: 20, creadaHaceHoras: 30);

        $this->assertSame(1, $this->reminders()->run($this->business)['queued']);
    }

    // ---- Una sola vez ----

    public function test_correrlo_dos_veces_no_manda_dos_recordatorios(): void
    {
        /*
         * Por el índice único de `messages`, no por una bandera en la cita.
         * Es lo que permite programarlo cada 15 minutos sin miedo.
         */
        $this->agendar(enHoras: 20);

        $this->reminders()->run($this->business);
        $segunda = $this->reminders()->run($this->business);

        $this->assertSame(0, $segunda['queued']);
        $this->assertSame(1, Message::withoutGlobalScopes()->count());
    }

    public function test_una_cita_cancelada_no_recibe_recordatorio(): void
    {
        $cita = $this->agendar(enHoras: 20);
        app(BookingService::class)->cancel($cita, null, 'Ya no puede');

        $this->assertSame(0, $this->reminders()->run($this->business)['queued']);
    }

    // ---- Lo que se omite sin ser un error ----

    public function test_sin_telefono_se_omite_y_no_falla(): void
    {
        /*
         * Es normalísimo: alguien se agendó por el mostrador y nadie anotó el
         * número. Contarlo como fallo haría que el comando se vea rojo todos
         * los días y que nadie mire la salida cuando de verdad falle algo.
         */
        $cita = $this->agendar(enHoras: 20);
        $cita->forceFill(['client_phone' => null])->save();
        $this->carolina->forceFill(['phone' => null])->save();

        $resultado = $this->reminders()->run($this->business);

        $this->assertSame(0, $resultado['queued']);
        $this->assertSame(1, $resultado['skipped']);
    }

    public function test_sin_la_funcion_de_recordatorios_no_hace_nada(): void
    {
        $this->agendar(enHoras: 20);
        $this->business->update(['feature_flags' => array_merge(
            $this->business->feature_flags,
            ['reminders' => false],
        )]);

        $this->assertSame(0, $this->reminders()->run($this->business->fresh())['queued']);
    }

    public function test_con_el_ajuste_en_cero_no_se_recuerda_nada(): void
    {
        // Es cómo un negocio apaga los recordatorios sin perder la función.
        $this->agendar(enHoras: 20);
        $this->business->update(['scheduling_settings' => array_merge(
            $this->business->scheduling_settings,
            ['reminder_hours_before' => 0],
        )]);

        $this->assertSame(0, $this->reminders()->run($this->business->fresh())['queued']);
    }

    // ---- Manual y automático ----

    public function test_en_manual_queda_por_enviar_a_mano(): void
    {
        $this->agendar(enHoras: 20);
        $this->reminders()->run($this->business);

        $this->assertSame(Message::STATUS_MANUAL, Message::withoutGlobalScopes()->first()->status);
    }

    public function test_en_automatico_sale_solo(): void
    {
        $canal = new FakeMessagingChannel;
        $this->app->instance(MessagingChannel::class, $canal);
        $this->business->update(['messaging_mode' => 'auto']);

        $this->agendar(enHoras: 20);
        $this->reminders()->run($this->business->fresh());

        $this->assertCount(1, $canal->sent);
        $this->assertSame(Message::STATUS_SENT, Message::withoutGlobalScopes()->first()->status);
    }

    // ---- El comando ----

    public function test_el_comando_prepara_los_de_todos_los_negocios(): void
    {
        $this->agendar(enHoras: 20);

        $this->artisan('recordatorios:preparar')
            ->expectsOutputToContain('Listos: 1')
            ->assertSuccessful();
    }

    public function test_el_dry_run_no_prepara_nada(): void
    {
        // Para poder mirar a quién le tocaría antes de encender el envío
        // automático a las clientas de alguien.
        $this->agendar(enHoras: 20);

        $this->artisan('recordatorios:preparar', ['--dry-run' => true])
            ->expectsOutputToContain('Le tocaría a 1')
            ->assertSuccessful();

        $this->assertSame(0, Message::withoutGlobalScopes()->count());
    }

    public function test_un_negocio_inactivo_no_manda_nada(): void
    {
        $this->agendar(enHoras: 20);
        $this->business->update(['is_active' => false]);

        $this->artisan('recordatorios:preparar')->assertSuccessful();

        $this->assertSame(0, Message::withoutGlobalScopes()->count());
    }
}
