<?php

namespace Tests\Feature\PublicBooking;

use App\Models\Appointment;
use App\Models\Business;
use App\Models\Client;
use App\Models\Resource;
use App\Models\Service;
use App\Services\ClientPortalService;
use App\Services\Scheduling\BookingService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Scheduling\SchedulingScenario;
use Tests\TestCase;

/**
 * "Mis citas": el cliente mueve su propia cita, sin cuenta.
 *
 * Lo que se defiende acá, antes que la funcionalidad, es que esto NO SEA UN
 * DIRECTORIO. Blue Souls exponía `/api/external/*` con throttle y nada más: se
 * podían enumerar clientes con teléfono y borrar citas sin credenciales. Un
 * teléfono no es un secreto — está en la vitrina, en Instagram, en un grupo de
 * WhatsApp — así que no puede ser lo que autoriza a leer nombres y horarios.
 */
class ClientPortalTest extends TestCase
{
    use RefreshDatabase, SchedulingScenario;

    private Business $business;

    private Client $carolina;

    private Resource $maria;

    private Service $manicure;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(
            CarbonImmutable::now('America/Bogota')->startOfDay()
                ->previous(CarbonImmutable::WEDNESDAY)->setTime(8, 0),
        );

        $this->business = $this->makeBusiness([
            'min_booking_notice_min' => 0,
            // Tres horas de preaviso para cambiar o cancelar.
            'min_cancellation_notice_min' => 180,
        ]);
        $this->business->update(['slug' => 'spa-portal']);

        $this->maria = $this->makeResource($this->business, 'Maria', '09:00:00', '18:00:00');
        $this->manicure = $this->makeService($this->business, 60, [$this->maria]);

        $this->carolina = Client::create([
            'business_id' => $this->business->id,
            'name' => 'Carolina', 'last_name' => 'Pérez',
            'phone' => '+573001112233', 'email' => 'caro@prueba.test',
            'is_active' => true,
        ]);

        $this->token = app(ClientPortalService::class)->tokenFor($this->carolina);
    }

    private function hoy(): CarbonImmutable
    {
        return CarbonImmutable::now('America/Bogota')->startOfDay();
    }

    private function agendar(string $hora = '15:00', ?Client $cliente = null): Appointment
    {
        return app(BookingService::class)->book(
            $this->business,
            [[
                'service_id' => $this->manicure->id,
                'resource_id' => $this->maria->id,
                'starts_at' => $this->hoy()->addDay()->setTimeFromTimeString($hora),
            ]],
            $cliente ?? $this->carolina,
            'Carolina Pérez',
            '+573001112233',
        );
    }

    private function url(string $suffix = ''): string
    {
        return "/api/v1/public/spa-portal/mis-citas/{$this->token}{$suffix}";
    }

    // ---- Que no sea un directorio ----

    public function test_no_se_entra_con_el_telefono(): void
    {
        /*
         * No existe ninguna ruta que acepte un teléfono. Si algún día alguien
         * la agrega, esta prueba no la ve — pero el token de 48 caracteres sí
         * documenta la decisión, y las dos de abajo la defienden.
         */
        $this->agendar();

        $this->getJson('/api/v1/public/spa-portal/mis-citas/+573001112233')->assertStatus(404);
        $this->getJson('/api/v1/public/spa-portal/mis-citas/3001112233')->assertStatus(404);
    }

    public function test_un_token_inventado_no_abre_nada(): void
    {
        $this->getJson('/api/v1/public/spa-portal/mis-citas/'.str_repeat('a', 48))->assertStatus(404);
    }

    public function test_el_token_de_otro_negocio_no_abre_este(): void
    {
        // Mismo 404 que un token inventado: que ese cliente exista en algún
        // lado tampoco es asunto de quien pregunta.
        $otro = $this->makeBusiness();
        $ajeno = Client::create([
            'business_id' => $otro->id, 'name' => 'Ajena',
            'phone' => '+573009998877', 'is_active' => true,
        ]);
        $tokenAjeno = app(ClientPortalService::class)->tokenFor($ajeno);

        $this->getJson("/api/v1/public/spa-portal/mis-citas/{$tokenAjeno}")->assertStatus(404);
    }

    public function test_no_se_toca_la_cita_de_otra_persona(): void
    {
        $otra = Client::create([
            'business_id' => $this->business->id, 'name' => 'Otra',
            'phone' => '+573004445566', 'is_active' => true,
        ]);
        $ajena = $this->agendar('16:00', $otra);

        // Con MI token y SU id de cita: 404, no la cita de ella.
        $this->getJson($this->url("/{$ajena->id}/slots?date=".$this->hoy()->addDay()->toDateString()))
            ->assertStatus(404);

        $this->postJson($this->url("/{$ajena->id}/cancel"))->assertStatus(404);

        $this->assertSame(Appointment::STATUS_PENDING, $ajena->fresh()->status);
    }

    public function test_el_token_no_sale_en_ninguna_respuesta(): void
    {
        // Vale tanto como una contraseña: quien lo tenga ve y mueve las citas
        // de esa persona.
        $this->assertArrayNotHasKey('portal_token', $this->carolina->fresh()->toArray());
    }

    // ---- Lo que sí puede hacer ----

    public function test_ve_sus_proximas_citas(): void
    {
        $this->agendar();

        $body = $this->getJson($this->url())->assertOk();

        $this->assertCount(1, $body->json('appointments'));
        $this->assertSame('Manicure', $body->json('appointments.0.items.0.service'));
        $this->assertSame('Maria', $body->json('appointments.0.items.0.resource'));
        $this->assertTrue($body->json('appointments.0.can_change'));
    }

    public function test_no_ve_lo_que_ya_paso(): void
    {
        /*
         * El historial completo -- cuánto gastó, qué se hizo hace ocho meses --
         * es del negocio. No tiene por qué viajar a un enlace que puede quedar
         * abierto en un teléfono prestado.
         */
        $vieja = $this->agendar();
        $vieja->forceFill(['starts_at' => $this->hoy()->subDays(30)])->save();

        $this->assertCount(0, $this->getJson($this->url())->assertOk()->json('appointments'));
    }

    public function test_mueve_la_hora_de_su_cita(): void
    {
        $cita = $this->agendar('15:00');

        $nueva = $this->hoy()->addDay()->setTime(11, 0);

        $this->postJson($this->url("/{$cita->id}/reschedule"), [
            'starts_at' => $nueva->toIso8601String(),
        ])->assertOk();

        $this->assertSame(
            $nueva->utc()->format('Y-m-d H:i'),
            CarbonImmutable::parse($cita->fresh()->starts_at)->utc()->format('Y-m-d H:i'),
        );
    }

    public function test_las_horas_que_se_le_ofrecen_son_de_su_misma_persona(): void
    {
        // La clienta reservó con quien reservó. Ofrecerle otra cara sin
        // decirlo es cambiarle la cita, no moverla.
        $lucia = $this->makeResource($this->business, 'Lucia', '09:00:00', '18:00:00');
        $this->manicure->resources()->attach($lucia->id);

        $cita = $this->agendar();

        $body = $this->getJson($this->url("/{$cita->id}/slots?date=".$this->hoy()->addDay()->toDateString()))
            ->assertOk();

        $this->assertSame('Maria', $body->json('resource_name'));
        $this->assertNotEmpty($body->json('slots'));
    }

    public function test_cancela_su_cita(): void
    {
        $cita = $this->agendar();

        $this->postJson($this->url("/{$cita->id}/cancel"))->assertOk();

        $this->assertSame(Appointment::STATUS_CANCELLED, $cita->fresh()->status);

        // Sin `cancelled_by_user_id`: no la canceló nadie del equipo. Cargarle
        // la cancelación a la profesional sería atribuirle algo que no hizo.
        $this->assertNull($cita->fresh()->cancelled_by_user_id);
    }

    public function test_cancelar_libera_el_hueco(): void
    {
        $cita = $this->agendar('15:00');
        $this->postJson($this->url("/{$cita->id}/cancel"))->assertOk();

        $libres = collect($this->getJson('/api/v1/public/spa-portal/availability?service_id='
            .$this->manicure->id.'&date='.$this->hoy()->addDay()->toDateString())
            ->assertOk()->json('slots'))->pluck('label');

        $this->assertTrue($libres->contains('3:00 pm'));
    }

    // ---- El preaviso ----

    public function test_no_se_cancela_sobre_la_hora(): void
    {
        /*
         * `min_cancellation_notice_min` existía como ajuste y nadie lo
         * aplicaba. Sin él, alguien cancela a las 8:55 una cita de las 9:00 y
         * esa hora ya no se vuelve a vender: el local pierde la mañana y la
         * profesional su comisión.
         */
        $cita = $this->agendar();
        $cita->forceFill(['starts_at' => CarbonImmutable::now()->addHour()])->save();

        $this->postJson($this->url("/{$cita->id}/cancel"))
            ->assertStatus(422)
            ->assertJsonPath('message', fn (string $m) => str_contains($m, 'anticipación'));

        $this->assertSame(Appointment::STATUS_PENDING, $cita->fresh()->status);
    }

    public function test_la_pantalla_sabe_de_antemano_que_no_se_puede(): void
    {
        // Se dice ANTES de tocar el botón, no después: un botón que existe y
        // siempre falla es peor que uno que no está.
        $cita = $this->agendar();
        $cita->forceFill(['starts_at' => CarbonImmutable::now()->addHour()])->save();

        $body = $this->getJson($this->url())->assertOk();

        $this->assertFalse($body->json('appointments.0.can_change'));
        $this->assertNotNull($body->json('appointments.0.refusal'));
    }

    public function test_una_cita_ya_cobrada_no_se_mueve(): void
    {
        $cita = $this->agendar();
        $cita->forceFill(['checked_out_at' => now()])->save();

        $this->postJson($this->url("/{$cita->id}/reschedule"), [
            'starts_at' => $this->hoy()->addDay()->setTime(11, 0)->toIso8601String(),
        ])->assertStatus(422);
    }

    public function test_una_visita_de_varios_servicios_manda_a_escribir(): void
    {
        /*
         * Cada eslabón puede ser de una persona distinta y moverla entera es
         * reencajar la cadena completa. Si no cabe, la respuesta sería un
         * error que la clienta no puede resolver sola: se le dice que escriba,
         * que es lo que iba a terminar haciendo igual.
         */
        $pedicure = $this->makeService($this->business, 60, [$this->maria], name: 'Pedicure');

        $cita = app(BookingService::class)->book(
            $this->business,
            [
                ['service_id' => $this->manicure->id, 'resource_id' => $this->maria->id,
                    'starts_at' => $this->hoy()->addDay()->setTime(10, 0)],
                ['service_id' => $pedicure->id, 'resource_id' => $this->maria->id,
                    'starts_at' => $this->hoy()->addDay()->setTime(11, 0)],
            ],
            $this->carolina,
            'Carolina Pérez',
            '+573001112233',
        );

        $body = $this->getJson($this->url("/{$cita->id}/slots?date=".$this->hoy()->addDay()->toDateString()))
            ->assertOk();

        $this->assertSame([], $body->json('slots'));
        $this->assertStringContainsString('Escríbenos', $body->json('message'));
    }

    // ---- Prellenar el formulario ----

    public function test_el_enlace_con_token_prellena_los_datos(): void
    {
        /*
         * Es la única respuesta honesta a "que el enlace traiga su número": el
         * navegador NO conoce el teléfono de quien lo abre. Lo conoce el
         * negocio, porque ya estaba en la ficha, y viaja porque el token dice
         * de quién es esa ficha.
         */
        $body = $this->getJson("/api/v1/public/spa-portal?c={$this->token}")->assertOk();

        $this->assertSame('Carolina Pérez', $body->json('client.name'));
        $this->assertSame('+573001112233', $body->json('client.phone'));
        $this->assertSame('caro@prueba.test', $body->json('client.email'));
    }

    public function test_sin_token_no_se_prellena_nada(): void
    {
        // Adivinar por IP o por cookie sería ponerle a alguien el teléfono de
        // otro en el formulario, y eso sólo se descubre cuando la
        // confirmación no llega.
        $this->assertNull($this->getJson('/api/v1/public/spa-portal')->assertOk()->json('client'));
    }

    public function test_un_token_malo_en_el_enlace_deja_reservar_igual(): void
    {
        // Un enlace viejo o mal copiado tiene que llevar a reservar
        // normalmente, no a un error.
        $body = $this->getJson('/api/v1/public/spa-portal?c=basura')->assertOk();

        $this->assertNull($body->json('client'));
        $this->assertNotEmpty($body->json('services'));
    }
}
