<?php

namespace Tests\Feature\Messaging;

use App\Models\Appointment;
use App\Models\Business;
use App\Models\Client;
use App\Models\Message;
use App\Models\User;
use App\Services\Messaging\DailyDigestService;
use App\Services\Scheduling\BookingService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Scheduling\SchedulingScenario;
use Tests\TestCase;

/**
 * El resumen del día para el dueño.
 *
 * Lo que se defiende: que cuente lo del día y NADA más, y sobre todo que un
 * día sin movimiento no genere correo. "Hoy no pasó nada" repetido cada
 * noche es exactamente como se le enseña a alguien a ignorar un remitente.
 */
class DailyDigestTest extends TestCase
{
    use RefreshDatabase, SchedulingScenario;

    private Business $business;

    private User $duenia;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-09-18 15:00', 'America/Bogota'));

        config()->set('services.comms_core.api_key', 'llave-comms');
        config()->set('services.comms_core.base_url', 'http://comms.test');

        $this->business = $this->makeBusiness(['min_booking_notice_min' => 0]);
        $this->duenia = User::create([
            'business_id' => $this->business->id,
            'name' => 'Alejandro',
            'email' => 'duena@luxury.co',
            'password' => Hash::make('secreto123'),
            'is_owner' => true,
            'is_active' => true,
        ]);
    }

    private function agendarHoy(int $hora = 16): Appointment
    {
        $maria = $this->makeResource($this->business, 'Maria '.uniqid());
        $servicio = $this->makeService($this->business, 60, [$maria], name: 'Manicure '.uniqid());
        $cliente = Client::create([
            'business_id' => $this->business->id,
            'name' => 'Carolina',
            'phone' => '5730011122'.random_int(10, 99),
            'is_active' => true,
        ]);

        return app(BookingService::class)->book(
            $this->business,
            [[
                'service_id' => $servicio->id,
                'resource_id' => $maria->id,
                'starts_at' => CarbonImmutable::now('America/Bogota')->setTime($hora, 0),
            ]],
            $cliente,
        );
    }

    public function test_un_dia_sin_movimiento_no_genera_correo(): void
    {
        Http::fake();

        $this->assertNull(app(DailyDigestService::class)->compose($this->business));
        $this->assertFalse(app(DailyDigestService::class)->sendFor($this->business));

        Http::assertNothingSent();
    }

    public function test_el_resumen_cuenta_citas_canceladas_y_mensajes(): void
    {
        $cita = $this->agendarHoy(16);
        $this->agendarHoy(17);
        app(BookingService::class)->cancel($cita, null, 'La clienta no puede');

        Message::create([
            'business_id' => $this->business->id,
            'kind' => Message::KIND_INBOUND,
            'direction' => Message::DIRECTION_IN,
            'to' => '573001112233',
            'body' => '¿Tienen hora?',
            'status' => Message::STATUS_SENT,
            'sent_at' => now(),
        ]);

        $texto = app(DailyDigestService::class)->compose($this->business);

        $this->assertStringContainsString('Citas agendadas: 2', $texto);
        $this->assertStringContainsString('Canceladas: 1', $texto);
        $this->assertStringContainsString('Mensajes recibidos: 1', $texto);
        $this->assertStringContainsString('connect.nexolu.co/chat', $texto);
    }

    public function test_el_resumen_distingue_lo_que_agendo_el_bot(): void
    {
        // Es el dato que dice si el agente está sirviendo para algo.
        $cita = $this->agendarHoy(16);
        $cita->update(['source' => Appointment::SOURCE_WHATSAPP_AGENT]);

        $this->assertStringContainsString(
            '(1 por WhatsApp)',
            app(DailyDigestService::class)->compose($this->business),
        );
    }

    public function test_el_correo_sale_por_connect_hacia_los_duenios(): void
    {
        Http::fake([
            'comms.test/*' => Http::response(['results' => [['channel' => 'email', 'status' => 'sent']]]),
        ]);

        $this->agendarHoy(16);

        $this->assertTrue(app(DailyDigestService::class)->sendFor($this->business));

        Http::assertSent(function ($request) {
            $body = $request->data();

            return str_contains($request->url(), '/v1/notifications/send')
                && $body['channels'] === ['email']
                && $body['to']['email'] === 'duena@luxury.co'
                && str_contains($body['subject'], 'Resumen de hoy')
                && str_contains($body['reference'], 'daily_digest:');
        });
    }

    public function test_sin_duenios_con_correo_no_se_manda_nada(): void
    {
        Http::fake();
        $this->duenia->update(['is_owner' => false]);

        $this->agendarHoy(16);

        $this->assertFalse(app(DailyDigestService::class)->sendFor($this->business));
        Http::assertNothingSent();
    }

    public function test_el_comando_en_seco_muestra_el_texto_sin_mandarlo(): void
    {
        Http::fake();
        $this->agendarHoy(16);

        $this->artisan('resumen:diario', ['--dry-run' => true])
            ->expectsOutputToContain('Citas agendadas: 1')
            ->assertSuccessful();

        Http::assertNothingSent();
    }
}
