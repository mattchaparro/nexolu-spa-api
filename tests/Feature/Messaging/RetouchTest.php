<?php

namespace Tests\Feature\Messaging;

use App\Models\Appointment;
use App\Models\Business;
use App\Models\Client;
use App\Models\Message;
use App\Models\Resource;
use App\Models\Service;
use App\Models\WhatsappConversation;
use App\Services\Messaging\Contracts\MessagingChannel;
use App\Services\Messaging\RetouchReminderService;
use App\Services\Scheduling\BookingService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Scheduling\SchedulingScenario;
use Tests\Support\FakeMessagingChannel;
use Tests\TestCase;

/**
 * "Ya casi te toca retoque".
 *
 * Es el mensaje que más citas devuelve, y el que más fácil se vuelve spam: lo
 * que decide si sirve no es mandarlo, es A QUIÉN se le manda y por cuál
 * servicio.
 */
class RetouchTest extends TestCase
{
    use RefreshDatabase, SchedulingScenario;

    private Business $business;

    private Resource $maria;

    private Service $semi;

    private Client $carolina;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(
            CarbonImmutable::now('America/Bogota')->startOfDay()
                ->previous(CarbonImmutable::WEDNESDAY)->setTime(10, 0),
        );

        $this->business = $this->makeBusiness(['min_booking_notice_min' => 0]);
        $this->maria = $this->makeResource($this->business, 'Maria');
        $this->semi = $this->makeService($this->business, 60, [$this->maria], name: 'Semipermanente');

        $this->carolina = Client::create([
            'business_id' => $this->business->id,
            'name' => 'Carolina', 'last_name' => 'Pérez',
            'phone' => '+573001112233', 'is_active' => true,
        ]);
    }

    private function retoques(): RetouchReminderService
    {
        return $this->app->make(RetouchReminderService::class);
    }

    private function ahora(): CarbonImmutable
    {
        return CarbonImmutable::now('America/Bogota');
    }

    /** Una visita hace `$haceDias`, ya cumplida. */
    private function visita(int $haceDias, ?Service $servicio = null, ?Client $quien = null): Appointment
    {
        $this->travel(-$haceDias)->days();

        $cita = app(BookingService::class)->book(
            $this->business,
            [[
                'service_id' => ($servicio ?? $this->semi)->id,
                'resource_id' => $this->maria->id,
                'starts_at' => $this->ahora()->setTime(11, 0),
            ]],
            $quien ?? $this->carolina,
            'Carolina Pérez',
            '+573001112233',
            Appointment::SOURCE_ADMIN,
            null,
            false,
        );

        $this->travel($haceDias)->days();

        return $cita->fresh();
    }

    private function corrida(): array
    {
        return $this->retoques()->run($this->business, $this->ahora());
    }

    // ---- Cuándo toca ----

    public function test_a_los_veinte_dias_le_escribe(): void
    {
        // Veinte días es lo que dura un semipermanente antes de verse crecido.
        $cita = $this->visita(20);

        $this->assertSame(['queued' => 1, 'skipped' => 0], $this->corrida());

        $mensaje = Message::withoutGlobalScopes()->sole();
        $this->assertSame(Message::KIND_RETOUCH, $mensaje->kind);
        $this->assertSame($cita->id, $mensaje->appointment_id);
        $this->assertStringContainsString('Carolina', $mensaje->body);
        $this->assertStringContainsString('Semipermanente', $mensaje->body);
    }

    public function test_a_los_quince_dias_todavia_no(): void
    {
        // Escribirle antes de tiempo es pedirle plata a quien todavía tiene
        // las uñas bien; el mensaje deja de leerse el día que toque de verdad.
        $this->visita(15);

        $this->assertSame(0, $this->corrida()['queued']);
    }

    public function test_va_como_plantilla_porque_pasaron_semanas(): void
    {
        /*
         * Sale semanas después de la última conversación: fuera de la ventana
         * de 24h Meta descarta el texto libre SIN AVISAR -- el mensaje se ve
         * enviado en la bandeja y nunca llega.
         */
        $this->visita(20);
        $this->corrida();

        $mensaje = Message::withoutGlobalScopes()->sole();
        $this->assertSame('retoque_recordatorio', $mensaje->template_name);
        $this->assertSame(['Carolina', $this->business->name, 'Semipermanente'], $mensaje->template_params);
    }

    public function test_sale_como_plantilla_aunque_la_ventana_este_abierta(): void
    {
        /*
         * Con la ventana abierta el texto libre suele ser mejor -- lleva
         * enlaces y renglones que una plantilla no puede --, pero no puede
         * llevar BOTONES, y el botón «Agendar retoque» es justo lo que hace
         * que este mensaje sirva: sin él la clienta tiene que escribir y
         * volver a contar lo que el salón ya sabe.
         */
        $canal = new FakeMessagingChannel;
        $this->app->instance(MessagingChannel::class, $canal);
        $this->business->update(['messaging_mode' => 'auto']);

        WhatsappConversation::withoutGlobalScope('business')->create([
            'business_id' => $this->business->id,
            'phone' => '573001112233',
            'client_id' => $this->carolina->id,
            'last_message_at' => now(),
            'last_inbound_at' => now(),
            'status' => WhatsappConversation::STATUS_OPEN,
        ]);

        $this->visita(20);
        $this->retoques()->run($this->business->fresh(), $this->ahora());

        $this->assertSame('retoque_recordatorio', $canal->sent[0]['template']);
    }

    public function test_una_corrida_perdida_se_recupera_en_la_siguiente(): void
    {
        // Si el cron estuvo caído el fin de semana, el lunes salen los de
        // viernes, sábado y domingo en vez de perderse para siempre.
        $this->visita(22);

        $this->assertSame(1, $this->corrida()['queued']);
    }

    public function test_pasada_la_gracia_ya_no_es_un_recordatorio_de_retoque(): void
    {
        // A los 30 días de un servicio de 20 el mensaje ya sería un "hace
        // mucho no vienes": otro mensaje, otra decisión.
        $this->visita(30);

        $this->assertSame(0, $this->corrida()['queued']);
    }

    // ---- A quién ----

    public function test_a_quien_ya_volvio_a_agendar_no_se_le_recuerda_volver(): void
    {
        /*
         * Recibirlo teniendo cita se lee como que el salón no sabe quién es
         * -- y es justo lo contrario de lo que el mensaje quiere demostrar.
         */
        $this->visita(20);
        app(BookingService::class)->book(
            $this->business,
            [[
                'service_id' => $this->semi->id,
                'resource_id' => $this->maria->id,
                'starts_at' => $this->ahora()->addDays(2)->setTime(11, 0),
            ]],
            $this->carolina,
            'Carolina Pérez',
            '+573001112233',
            Appointment::SOURCE_ADMIN,
            null,
            false,
        );

        $this->assertSame(0, $this->corrida()['queued']);
    }

    public function test_manda_por_la_ultima_visita_no_por_una_vieja(): void
    {
        /*
         * Si volvió hace poco por otra cosa, el retoque que toca es el de esa
         * visita. Escribirle por un servicio que ya se rehizo se lee como que
         * el salón no sabe quién es.
         */
        $pedicure = $this->makeService($this->business, 60, [$this->maria], name: 'Pedicure');
        $this->visita(40);
        $this->visita(5, $pedicure);

        $this->assertSame(0, $this->corrida()['queued']);
    }

    public function test_a_quien_se_dio_de_baja_no_se_le_escribe(): void
    {
        /*
         * Es la misma llave que frena las difusiones. Quien no puede salirse
         * bloquea el número -- y un bloqueo se lleva por delante también los
         * recordatorios de su propia cita, y la calidad del número para todas
         * las demás.
         */
        $this->visita(20);
        $this->carolina->forceFill(['accepts_marketing' => false])->save();

        $this->assertSame(0, $this->corrida()['queued']);
    }

    public function test_el_mensaje_es_corto_y_dice_de_que_se_trata(): void
    {
        /*
         * El de ManyChat eran cinco párrafos. Lo que la clienta necesita
         * saber cabe en dos líneas: qué se hizo y que puede agendar; lo demás
         * se lee como publicidad, y la publicidad se salta.
         */
        $this->visita(20);
        $this->corrida();

        $cuerpo = Message::withoutGlobalScopes()->sole()->body;
        $this->assertStringStartsWith('*Se acerca tu retoque*', $cuerpo);
        $this->assertLessThan(220, mb_strlen($cuerpo));
    }

    public function test_una_cita_cancelada_no_cuenta_como_visita(): void
    {
        $this->visita(20)->forceFill(['status' => Appointment::STATUS_CANCELLED])->save();

        $this->assertSame(0, $this->corrida()['queued']);
    }

    public function test_uno_por_cita_aunque_el_cron_corra_dos_veces(): void
    {
        // Lo impide el índice único (appointment_id, kind), no una bandera:
        // una restricción no se desincroniza.
        $this->visita(20);

        $this->assertSame(1, $this->corrida()['queued']);
        $this->assertSame(0, $this->corrida()['queued']);
        $this->assertSame(1, Message::withoutGlobalScopes()->count());
    }

    // ---- Cada cuántos días ----

    public function test_el_servicio_manda_sobre_el_default(): void
    {
        $this->semi->update(['retouch_days' => 30]);
        $this->visita(20);

        $this->assertSame(0, $this->corrida()['queued']);

        $this->travel(10)->days();
        $this->assertSame(1, $this->retoques()->run($this->business, $this->ahora())['queued']);
    }

    public function test_cero_dias_es_un_servicio_que_no_se_retoca(): void
    {
        // Un retiro, una reparación: no hay nada que retocar después.
        $this->semi->update(['retouch_days' => 0]);
        $this->visita(20);

        $this->assertSame(0, $this->corrida()['queued']);
    }

    // ---- El cron ----

    public function test_el_comando_espera_a_la_hora_local_del_negocio(): void
    {
        /*
         * Corre cada hora y cada negocio elige la suya: un salón en otro huso
         * no puede recibir su mensaje de las 10 am a las 5 de la mañana.
         */
        $this->business->forceFill([
            'scheduling_settings' => [...$this->business->scheduling_settings, 'retouch_reminder_hour' => 15],
        ])->save();
        $this->visita(20);

        $this->artisan('retoques:recordar')->assertSuccessful();
        $this->assertSame(0, Message::withoutGlobalScopes()->count());

        $this->travelTo($this->ahora()->setTime(15, 0));
        $this->artisan('retoques:recordar')->assertSuccessful();
        $this->assertSame(1, Message::withoutGlobalScopes()->count());
    }

    public function test_dry_run_no_prepara_nada(): void
    {
        $this->visita(20);

        $this->artisan('retoques:recordar --ahora --dry-run')
            ->expectsOutputToContain('Carolina')
            ->assertSuccessful();

        $this->assertSame(0, Message::withoutGlobalScopes()->count());
    }
}
