<?php

namespace Tests\Feature\Messaging;

use App\Models\Appointment;
use App\Models\Business;
use App\Models\Client;
use App\Models\Message;
use App\Models\Resource;
use App\Models\Service;
use App\Services\Messaging\Contracts\MessagingChannel;
use App\Services\Scheduling\BookingService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Scheduling\SchedulingScenario;
use Tests\Support\FakeMessagingChannel;
use Tests\TestCase;

/**
 * El aviso a quien ATIENDE: le agendaron, o le cancelaron.
 *
 * Hasta ahora el equipo se enteraba mirando la agenda, y eso funciona hasta
 * que no: quien trabaja por comisión quiere saber que le agendaron sin abrir
 * el panel, y una cancelación que nadie vio es una hora que alguien se queda
 * esperando en el salón.
 */
class TeamNoticeTest extends TestCase
{
    use RefreshDatabase, SchedulingScenario;

    private Business $business;

    private Resource $maria;

    private Service $manos;

    private Client $carolina;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(
            CarbonImmutable::now('America/Bogota')->startOfDay()
                ->previous(CarbonImmutable::WEDNESDAY)->setTime(9, 0),
        );

        $this->app->instance(MessagingChannel::class, new FakeMessagingChannel);

        $this->business = $this->makeBusiness([
            'min_booking_notice_min' => 0,
            'notify_team_whatsapp' => true,
        ]);
        $this->maria = $this->makeResource($this->business, 'Maria');
        $this->maria->update(['phone' => '+573009998877']);
        $this->manos = $this->makeService($this->business, 60, [$this->maria], name: 'Manicure');

        $this->carolina = Client::create([
            'business_id' => $this->business->id,
            'name' => 'Carolina', 'last_name' => 'Pérez',
            'phone' => '+573001112233', 'is_active' => true,
        ]);
    }

    /** @param list<array{0: Service, 1: Resource}> $conQuien */
    private function agendar(array $conQuien = []): Appointment
    {
        $inicio = CarbonImmutable::now('America/Bogota')->addDay()->setTime(10, 0);
        $conQuien = $conQuien !== [] ? $conQuien : [[$this->manos, $this->maria]];

        return app(BookingService::class)->book(
            $this->business,
            array_map(fn (array $par) => [
                'service_id' => $par[0]->id,
                'resource_id' => $par[1]->id,
                'starts_at' => $inicio,
            ], $conQuien),
            $this->carolina,
            'Carolina Pérez',
            '+573001112233',
            Appointment::SOURCE_ADMIN,
            null,
            false,
        );
    }

    /** @return \Illuminate\Support\Collection<int, Message> */
    private function avisos(string $kind)
    {
        return Message::withoutGlobalScopes()->where('kind', $kind)->get();
    }

    public function test_le_avisa_a_quien_la_va_a_atender(): void
    {
        $this->agendar();

        $aviso = $this->avisos(Message::KIND_TEAM_BOOKED)->sole();

        $this->assertSame('573009998877', $aviso->to);
        $this->assertStringContainsString('Carolina', $aviso->body);
        $this->assertStringContainsString('Manicure', $aviso->body);
        $this->assertStringContainsString('10:00 am', $aviso->body);
    }

    public function test_va_como_plantilla_porque_ella_no_le_escribe_al_salon(): void
    {
        /*
         * Quien atiende RECIBE del número del salón pero no le escribe, así
         * que su ventana de 24h está cerrada casi siempre. Como texto libre
         * este aviso no se entregaría -- y nadie se enteraría, porque Meta
         * lo acepta igual.
         */
        $this->agendar();

        $aviso = $this->avisos(Message::KIND_TEAM_BOOKED)->sole();

        $this->assertSame('cita_nueva_equipo', $aviso->template_name);
        $this->assertSame('Maria', $aviso->template_params[0]);
    }

    public function test_apagado_no_le_escribe_a_nadie(): void
    {
        /*
         * Encenderlo solo, en un deploy, sería empezar a escribirle al equipo
         * de cada negocio a nombre del salón sin que nadie lo pidiera.
         */
        $this->business->update(['scheduling_settings' => array_merge(
            $this->business->scheduling_settings,
            ['notify_team_whatsapp' => false],
        )]);

        $this->agendar();

        $this->assertCount(0, $this->avisos(Message::KIND_TEAM_BOOKED));
    }

    public function test_sin_telefono_no_se_avisa_y_no_es_una_falla(): void
    {
        // Pasa todo el tiempo: muchas manicuristas no tienen cuenta ni
        // teléfono cargado.
        $this->maria->update(['phone' => null]);

        $cita = $this->agendar();

        $this->assertCount(0, $this->avisos(Message::KIND_TEAM_BOOKED));
        $this->assertNotNull($cita->fresh());
    }

    public function test_cae_al_telefono_de_su_usuario(): void
    {
        $usuario = \App\Models\User::create([
            'business_id' => $this->business->id, 'name' => 'Maria',
            'email' => 'maria@prueba.test', 'phone' => '+573004445566',
            'password' => bcrypt('password123'), 'is_active' => true,
        ]);
        $this->maria->update(['phone' => null, 'user_id' => $usuario->id]);

        $this->agendar();

        $this->assertSame('573004445566', $this->avisos(Message::KIND_TEAM_BOOKED)->sole()->to);
    }

    public function test_dos_manicuristas_reciben_cada_una_lo_suyo(): void
    {
        /*
         * Manos y pies con dos personas: las dos tienen que enterarse, y cada
         * aviso nombra SU servicio. Con el índice viejo --uno por cita y
         * tipo-- el aviso de la segunda chocaba con el de la primera y se
         * descartaba en silencio.
         */
        $anyi = $this->makeResource($this->business, 'Anyi');
        $anyi->update(['phone' => '+573007776655']);
        $pies = $this->makeService($this->business, 60, [$anyi], name: 'Pedicure');

        $this->agendar([[$this->manos, $this->maria], [$pies, $anyi]]);

        $avisos = $this->avisos(Message::KIND_TEAM_BOOKED);

        $this->assertCount(2, $avisos);
        $this->assertStringContainsString('Manicure', $avisos->firstWhere('to', '573009998877')->body);
        $this->assertStringContainsString('Pedicure', $avisos->firstWhere('to', '573007776655')->body);
        // Y el de una NO nombra el servicio de la otra.
        $this->assertStringNotContainsString('Pedicure', $avisos->firstWhere('to', '573009998877')->body);
    }

    public function test_al_cancelar_le_avisan_que_esa_hora_queda_libre(): void
    {
        $cita = $this->agendar();

        app(BookingService::class)->cancel($cita, null, 'La clienta no puede');

        $aviso = $this->avisos(Message::KIND_TEAM_CANCELLED)->sole();

        $this->assertSame('573009998877', $aviso->to);
        $this->assertStringContainsString('libre', $aviso->body);
        $this->assertSame('cita_cancelada_equipo', $aviso->template_name);
    }

    public function test_agendar_y_cancelar_la_misma_cita_manda_los_dos_avisos(): void
    {
        // Por eso son dos tipos y no uno: con uno solo, el índice único
        // dejaría pasar el primero y descartaría el segundo.
        $cita = $this->agendar();
        app(BookingService::class)->cancel($cita, null, null);

        $this->assertCount(1, $this->avisos(Message::KIND_TEAM_BOOKED));
        $this->assertCount(1, $this->avisos(Message::KIND_TEAM_CANCELLED));
    }
}
