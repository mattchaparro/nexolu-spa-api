<?php

namespace Tests\Feature\Waitlist;

use App\Models\Appointment;
use App\Models\Business;
use App\Models\Client;
use App\Models\Message;
use App\Models\Resource;
use App\Models\ResourceOccupancy;
use App\Models\Service;
use App\Models\WaitlistEntry;
use App\Services\Scheduling\BookingService;
use App\Services\Waitlist\WaitlistService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\Feature\Scheduling\SchedulingScenario;
use Tests\TestCase;

/**
 * La lista de espera: se libera un cupo, se avisa a todos los que encajan, y
 * se lo queda quien lo tome primero.
 *
 * Lo que estas pruebas defienden, en orden de importancia: que la CARRERA
 * tenga un solo ganador, que la CASCADA emerja sin código propio, y que los
 * avisos no se conviertan en spam.
 */
class WaitlistTest extends TestCase
{
    use RefreshDatabase, SchedulingScenario;

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

        $this->business = $this->makeBusiness(['min_booking_notice_min' => 0]);
        $this->business->update(['slug' => 'spa-espera']);

        $this->maria = $this->makeResource($this->business, 'Maria');
        $this->manicure = $this->makeService($this->business, 60, [$this->maria]);
    }

    private function waitlist(): WaitlistService
    {
        return $this->app->make(WaitlistService::class);
    }

    private function booking(): BookingService
    {
        return $this->app->make(BookingService::class);
    }

    private function manana(): CarbonImmutable
    {
        return CarbonImmutable::now('America/Bogota')->addDay()->startOfDay();
    }

    private function clienta(string $nombre, string $telefono): Client
    {
        return Client::create([
            'business_id' => $this->business->id,
            'name' => $nombre,
            'phone' => $telefono,
            'is_active' => true,
        ]);
    }

    private function esperar(Client $clienta, array $overrides = []): WaitlistEntry
    {
        return $this->waitlist()->register(
            $this->business,
            $clienta,
            $overrides['service'] ?? $this->manicure,
            $clienta->phone,
            $overrides['from'] ?? $this->manana(),
            $overrides['to'] ?? $this->manana()->addDays(7),
            $overrides['resource_id'] ?? null,
            $overrides['location_id'] ?? null,
            $overrides['time_from'] ?? null,
            $overrides['time_to'] ?? null,
        );
    }

    private function agendar(Client $clienta, string $hora, ?CarbonImmutable $dia = null): Appointment
    {
        return $this->booking()->book(
            $this->business,
            [[
                'service_id' => $this->manicure->id,
                'resource_id' => $this->maria->id,
                'starts_at' => ($dia ?? $this->manana())->setTimeFromTimeString($hora),
            ]],
            $clienta,
            $clienta->fullName(),
            $clienta->phone,
        );
    }

    private function avisos(): Collection
    {
        return Message::withoutGlobalScopes()->where('kind', Message::KIND_WAITLIST)->get();
    }

    // ---- El disparador ----

    public function test_cancelar_avisa_a_quien_espera(): void
    {
        $ana = $this->clienta('Ana', '+573001111111');
        $cita = $this->agendar($ana, '10:00:00');

        $carolina = $this->clienta('Carolina', '+573002222222');
        $this->esperar($carolina);

        $this->booking()->cancel($cita);

        $this->assertCount(1, $this->avisos());
        $aviso = $this->avisos()->first();
        $this->assertSame('573002222222', $aviso->to);
        // Concreto y honesto: el cupo puntual, y la regla del juego.
        $this->assertStringContainsString('Manicure', $aviso->body);
        $this->assertStringContainsString('quien lo tome primero', $aviso->body);
        $this->assertStringContainsString('/cupo/spa-espera/', $aviso->body);
    }

    public function test_se_avisa_a_todos_los_que_encajan_no_de_a_uno(): void
    {
        /*
         * Broadcast, no fila. El momento de la cancelación es la hora de oro
         * y serializar los avisos la desperdicia. El empate lo arbitra el
         * índice único de ocupación, que ya existía.
         */
        $ana = $this->clienta('Ana', '+573001111111');
        $cita = $this->agendar($ana, '10:00:00');

        $this->esperar($this->clienta('Carolina', '+573002222222'));
        $this->esperar($this->clienta('Lucía', '+573003333333'));

        $this->booking()->cancel($cita);

        $this->assertCount(2, $this->avisos());
    }

    public function test_no_se_le_avisa_a_quien_solto_el_cupo(): void
    {
        // "Se liberó el cupo que acabas de cancelar" es una burla.
        $ana = $this->clienta('Ana', '+573001111111');
        $cita = $this->agendar($ana, '10:00:00');
        $this->esperar($ana);

        $this->booking()->cancel($cita);

        $this->assertCount(0, $this->avisos());
    }

    public function test_una_inasistencia_tambien_libera(): void
    {
        // No-show con etapa que libera el horario: mismo disparador.
        $ana = $this->clienta('Ana', '+573001111111');
        $cita = $this->agendar($ana, '10:00:00');
        $this->esperar($this->clienta('Carolina', '+573002222222'));

        // La acción de liberar, tal como la ejecutaría la etapa de no-show.
        ResourceOccupancy::whereIn(
            'appointment_item_id',
            $cita->items()->pluck('id'),
        )->delete();
        $this->waitlist()->appointmentFreed($cita->fresh(['items.resource', 'business']));

        $this->assertCount(1, $this->avisos());
    }

    // ---- A quién no le toca ----

    public function test_fuera_del_rango_de_fechas_no_se_avisa(): void
    {
        $ana = $this->clienta('Ana', '+573001111111');
        $cita = $this->agendar($ana, '10:00:00');

        // Espera para la OTRA semana.
        $this->esperar($this->clienta('Carolina', '+573002222222'), [
            'from' => $this->manana()->addDays(10),
            'to' => $this->manana()->addDays(15),
        ]);

        $this->booking()->cancel($cita);

        $this->assertCount(0, $this->avisos());
    }

    public function test_fuera_de_la_franja_horaria_no_se_avisa(): void
    {
        // Sólo puede en la tarde; el cupo liberado es de las 10am.
        $ana = $this->clienta('Ana', '+573001111111');
        $cita = $this->agendar($ana, '10:00:00');

        $this->esperar($this->clienta('Carolina', '+573002222222'), [
            'time_from' => '14:00:00',
            'time_to' => '18:00:00',
        ]);

        $this->booking()->cancel($cita);

        $this->assertCount(0, $this->avisos());
    }

    public function test_con_maria_o_con_nadie(): void
    {
        $lucia = $this->makeResource($this->business, 'Lucia');
        $this->manicure->resources()->attach($lucia->id);

        $ana = $this->clienta('Ana', '+573001111111');
        $citaConLucia = $this->booking()->book(
            $this->business,
            [[
                'service_id' => $this->manicure->id,
                'resource_id' => $lucia->id,
                'starts_at' => $this->manana()->setTime(10, 0),
            ]],
            $ana,
            'Ana',
            $ana->phone,
        );

        // Carolina espera A MARÍA. El cupo que se libera es de Lucía.
        $this->esperar($this->clienta('Carolina', '+573002222222'), [
            'resource_id' => $this->maria->id,
        ]);

        $this->booking()->cancel($citaConLucia);

        $this->assertCount(0, $this->avisos());
    }

    public function test_una_entrada_vencida_no_se_avisa_y_queda_expirada(): void
    {
        $entrada = $this->esperar($this->clienta('Carolina', '+573002222222'), [
            'from' => $this->manana()->subDays(10),
            'to' => $this->manana()->subDays(3),
        ]);

        $ana = $this->clienta('Ana', '+573001111111');
        $cita = $this->agendar($ana, '10:00:00');
        $this->booking()->cancel($cita);

        $this->assertCount(0, $this->avisos());
        $this->assertSame(WaitlistEntry::STATUS_EXPIRED, $entrada->fresh()->status);
    }

    public function test_el_freno_de_spam_una_notificacion_por_hora(): void
    {
        /*
         * Dos cancelaciones en la misma ráfaga → UN mensaje. La persona no
         * pierde nada: su enlace muestra los cupos vigentes en vivo, incluido
         * el segundo. Lo que sí perdería con dos mensajes es la disposición a
         * leer el tercero.
         */
        $ana = $this->clienta('Ana', '+573001111111');
        $lucia = $this->clienta('Lucía', '+573003333333');
        $citaA = $this->agendar($ana, '10:00:00');
        $citaB = $this->agendar($lucia, '14:00:00');

        $this->esperar($this->clienta('Carolina', '+573002222222'));

        $this->booking()->cancel($citaA);
        $this->booking()->cancel($citaB);

        $this->assertCount(1, $this->avisos());
    }

    // ---- La carrera ----

    public function test_el_cupo_es_de_quien_lo_toma_primero(): void
    {
        $ana = $this->clienta('Ana', '+573001111111');
        $cita = $this->agendar($ana, '10:00:00');

        $carolina = $this->esperar($this->clienta('Carolina', '+573002222222'));
        $lucia = $this->esperar($this->clienta('Lucía', '+573003333333'));

        $this->booking()->cancel($cita);

        $slot = ['resource_id' => $this->maria->id, 'starts_at' => $this->manana()->setTime(10, 0)->toIso8601String()];

        // Carolina llega primero.
        $this->postJson("/api/v1/public/spa-espera/cupo/{$carolina->token}/take", $slot)
            ->assertOk();

        // Lucía llega tarde: la verdad, no un error — y SIGUE en la lista.
        $this->postJson("/api/v1/public/spa-espera/cupo/{$lucia->token}/take", $slot)
            ->assertStatus(409)
            ->assertJsonPath('message', fn (string $m) => str_contains($m, 'Sigues en la lista'));

        $this->assertSame(WaitlistEntry::STATUS_FULFILLED, $carolina->fresh()->status);
        $this->assertSame(WaitlistEntry::STATUS_OPEN, $lucia->fresh()->status);
    }

    // ---- El traslado y la cascada ----

    public function test_tomar_un_cupo_teniendo_cita_la_mueve_en_vez_de_duplicarla(): void
    {
        /*
         * La parte diferenciadora. Carolina quería el sábado, se conformó con
         * el jueves. Se libera el sábado: tomar el cupo MUEVE su cita del
         * jueves — misma cita, mismo precio, mismo abono — no le crea una
         * segunda que va a terminar en inasistencia.
         */
        $carolina = $this->clienta('Carolina', '+573002222222');
        $citaJueves = $this->agendar($carolina, '15:00:00', $this->manana()->addDay());

        $entrada = $this->esperar($carolina);

        $ana = $this->clienta('Ana', '+573001111111');
        $citaSabado = $this->agendar($ana, '10:00:00');
        $this->booking()->cancel($citaSabado);

        // El enlace le anuncia el traslado ANTES de tocar.
        $vista = $this->getJson("/api/v1/public/spa-espera/cupo/{$entrada->token}")->assertOk();
        $this->assertSame($citaJueves->id, $vista->json('swaps.appointment_id'));

        $this->postJson("/api/v1/public/spa-espera/cupo/{$entrada->token}/take", [
            'resource_id' => $this->maria->id,
            'starts_at' => $this->manana()->setTime(10, 0)->toIso8601String(),
        ])->assertOk()->assertJsonPath('moved', true);

        // UNA cita, movida. No dos.
        $this->assertSame(1, Appointment::withoutGlobalScopes()
            ->where('client_id', $carolina->id)
            ->whereIn('status', [Appointment::STATUS_PENDING, Appointment::STATUS_CONFIRMED])
            ->count());

        $this->assertSame(
            $this->manana()->setTime(10, 0)->utc()->format('Y-m-d H:i'),
            CarbonImmutable::parse($citaJueves->fresh()->starts_at)->utc()->format('Y-m-d H:i'),
        );
    }

    public function test_la_cascada_emerge_sola(): void
    {
        /*
         * Carolina se mueve del jueves al sábado; su jueves queda libre; a
         * Lucía — que esperaba justo esa franja — le llega el aviso. Sin una
         * línea de código de "cascada": es el mismo disparador repitiéndose.
         */
        $carolina = $this->clienta('Carolina', '+573002222222');
        $this->agendar($carolina, '15:00:00', $this->manana()->addDay());
        $entradaCarolina = $this->esperar($carolina);

        $lucia = $this->clienta('Lucía', '+573003333333');
        $this->esperar($lucia, [
            'time_from' => '14:00:00',
            'time_to' => '17:00:00',
        ]);

        $ana = $this->clienta('Ana', '+573001111111');
        $citaSabado = $this->agendar($ana, '10:00:00');
        $this->booking()->cancel($citaSabado);

        // Carolina toma el sábado → su jueves 3pm se libera.
        $this->postJson("/api/v1/public/spa-espera/cupo/{$entradaCarolina->token}/take", [
            'resource_id' => $this->maria->id,
            'starts_at' => $this->manana()->setTime(10, 0)->toIso8601String(),
        ])->assertOk();

        // Y a Lucía le llegó el aviso del jueves en la tarde.
        $avisosLucia = $this->avisos()->where('to', '573003333333');
        $this->assertCount(1, $avisosLucia);
    }

    // ---- Cierre orgánico y opt-out ----

    public function test_reservar_por_su_cuenta_cierra_la_espera(): void
    {
        // Volvió a mirar la página y encontró cupo sola: seguir avisándole de
        // algo que ya tiene es la vía rápida a que pida silencio total.
        $carolina = $this->clienta('Carolina', '+573002222222');
        $entrada = $this->esperar($carolina);

        $this->agendar($carolina, '11:00:00');

        $this->assertSame(WaitlistEntry::STATUS_FULFILLED, $entrada->fresh()->status);
    }

    public function test_ya_no_me_avisen(): void
    {
        $carolina = $this->clienta('Carolina', '+573002222222');
        $entrada = $this->esperar($carolina);

        $this->postJson("/api/v1/public/spa-espera/cupo/{$entrada->token}/stop")->assertOk();

        $this->assertSame(WaitlistEntry::STATUS_STOPPED, $entrada->fresh()->status);

        // Y de verdad no se le avisa más.
        $ana = $this->clienta('Ana', '+573001111111');
        $cita = $this->agendar($ana, '10:00:00');
        $this->booking()->cancel($cita);

        $this->assertCount(0, $this->avisos());
    }

    public function test_apuntarse_dos_veces_refresca_no_duplica(): void
    {
        // Duplicar duplicaría los avisos: quien vuelve a apuntarse está
        // corrigiendo su preferencia, no compitiendo consigo mismo.
        $carolina = $this->clienta('Carolina', '+573002222222');
        $this->esperar($carolina);
        $this->esperar($carolina, ['time_from' => '14:00:00', 'time_to' => '18:00:00']);

        $this->assertSame(1, WaitlistEntry::withoutGlobalScopes()->count());
        $this->assertSame('14:00:00', WaitlistEntry::withoutGlobalScopes()->first()->time_from);
    }

    // ---- El enlace ----

    public function test_el_enlace_muestra_los_cupos_vigentes_en_vivo(): void
    {
        // No el cupo del mensaje: lo vigente. Un aviso de hace una hora sigue
        // sirviendo aunque ese cupo puntual ya se haya ido.
        $entrada = $this->esperar($this->clienta('Carolina', '+573002222222'));

        $vista = $this->getJson("/api/v1/public/spa-espera/cupo/{$entrada->token}")->assertOk();

        $this->assertNotEmpty($vista->json('slots'));
        $this->assertSame('Manicure', $vista->json('service'));
    }

    public function test_un_token_inventado_no_abre_nada(): void
    {
        $this->getJson('/api/v1/public/spa-espera/cupo/'.str_repeat('x', 48))->assertStatus(404);
    }

    public function test_apuntarse_desde_la_pagina_publica(): void
    {
        $this->postJson('/api/v1/public/spa-espera/waitlist', [
            'service_id' => $this->manicure->id,
            'date_from' => $this->manana()->toDateString(),
            'date_to' => $this->manana()->addDays(7)->toDateString(),
            'client_name' => 'Carolina Pérez',
            'client_phone' => '+573002222222',
        ])->assertCreated();

        $entrada = WaitlistEntry::withoutGlobalScopes()->first();
        $this->assertSame(WaitlistEntry::STATUS_OPEN, $entrada->status);
        // Y creó la ficha, como una reserva: es un cliente interesado.
        $this->assertNotNull($entrada->client_id);
    }
}
