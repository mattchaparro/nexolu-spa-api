<?php

namespace Tests\Feature\Payroll;

use App\Models\Appointment;
use App\Models\Business;
use App\Models\PaymentMethod;
use App\Models\PayrollAdjustment;
use App\Models\PayrollSettlement;
use App\Models\Resource;
use App\Models\Service;
use App\Models\User;
use App\Services\Payroll\PayrollService;
use App\Services\Scheduling\BookingService;
use App\Services\Scheduling\CheckoutService;
use App\Support\PermissionCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Scheduling\SchedulingScenario;
use Tests\TestCase;

/**
 * El detalle de «lo que llevo», y la quincena que ya se pagó.
 *
 * Marcela veía «llevo 600.000» sin saber de qué servicios salía, y tenía un
 * servicio del 29 de septiembre sin subir después de que se le pagó el 1 de
 * octubre: ese ya no se puede cobrar, porque se le pagó sin él.
 */
class QuincenaCerradaTest extends TestCase
{
    use RefreshDatabase, SchedulingScenario;

    private Business $business;

    private Resource $maria;

    private Service $semi;

    private User $empleada;

    private User $admin;

    private PaymentMethod $efectivo;

    protected function setUp(): void
    {
        parent::setUp();

        // Miércoles 16 de septiembre de 2026, 6 pm.
        $this->travelTo(CarbonImmutable::parse('2026-09-16 18:00', 'America/Bogota'));

        PermissionCatalog::sync();

        $this->business = $this->makeBusiness(['min_booking_notice_min' => 0]);
        $this->maria = $this->makeResource($this->business, 'Maria', '08:00:00', '20:00:00');
        $this->semi = $this->makeService($this->business, 60, [$this->maria], name: 'Semipermanente');

        $this->empleada = $this->usuario('maria@prueba.test', PermissionCatalog::ROLE_STAFF);
        $this->maria->update(['user_id' => $this->empleada->id]);
        $this->admin = $this->usuario('ana@prueba.test', PermissionCatalog::ROLE_ADMIN);

        $this->efectivo = PaymentMethod::withoutGlobalScopes()->create([
            'business_id' => $this->business->id, 'name' => 'Efectivo',
            'counts_as_cash' => true, 'is_active' => true, 'sort_order' => 0,
        ]);

        Sanctum::actingAs($this->empleada->fresh());
    }

    private function usuario(string $email, string $rol): User
    {
        $user = User::create([
            'business_id' => $this->business->id, 'name' => 'Persona',
            'email' => $email, 'password' => Hash::make('password123'), 'is_active' => true,
        ]);
        PermissionCatalog::applyRole($user, $rol);

        return $user;
    }

    private function dia(string $fecha): CarbonImmutable
    {
        return CarbonImmutable::parse($fecha, 'America/Bogota');
    }

    /** Un servicio atendido ese día a las 10, SIN cobrar. */
    private function sinCobrar(string $fecha, string $hora = '10:00'): Appointment
    {
        return app(BookingService::class)->book(
            $this->business,
            [['service_id' => $this->semi->id, 'resource_id' => $this->maria->id,
                'starts_at' => $this->dia($fecha)->setTimeFromTimeString($hora)]],
            null, 'Carolina Pérez', null, Appointment::SOURCE_ADMIN, null, false,
        );
    }

    private function cobrada(string $fecha): Appointment
    {
        return app(CheckoutService::class)->checkout(
            $this->sinCobrar($fecha), $this->efectivo, $this->admin,
            cobradoEn: $this->dia($fecha)->setTime(11, 0)->utc(),
        );
    }

    private function pagarHasta(string $fecha): PayrollSettlement
    {
        return app(PayrollService::class)->settle($this->business, $this->maria->fresh(), $this->dia($fecha), $this->admin);
    }

    private function miDia(): array
    {
        return $this->getJson('/api/v1/my-work')->assertOk()->json();
    }

    public function test_lo_que_lleva_trae_el_detalle_servicio_por_servicio(): void
    {
        $this->cobrada('2026-09-14');
        $this->cobrada('2026-09-15');

        $to = $this->miDia()['to_date'];

        $this->assertCount(2, $to['items']);
        $this->assertSame('Semipermanente', $to['items'][0]['service_name']);
        // Solo el primer nombre de la clienta.
        $this->assertSame('Carolina', $to['items'][0]['client_name']);
        $this->assertSame('2026-09-14', $to['items'][0]['date']);
        $this->assertGreaterThan(0, $to['items'][0]['commission_rate']);
        // El detalle suma la cifra de arriba.
        $this->assertEqualsWithDelta($to['commission'], array_sum(array_column($to['items'], 'commission_amount')), 0.01);
    }

    public function test_los_vales_y_premios_vienen_con_el_detalle(): void
    {
        $this->cobrada('2026-09-15');
        PayrollAdjustment::withoutGlobalScopes()->create([
            'business_id' => $this->business->id, 'resource_id' => $this->maria->id,
            'date' => '2026-09-15', 'kind' => 'deduction', 'category' => 'anticipo',
            'amount' => 20000, 'description' => 'Adelanto',
        ]);

        $to = $this->miDia()['to_date'];

        $this->assertCount(1, $to['adjustments']);
        $this->assertSame('deduction', $to['adjustments'][0]['kind']);
        $this->assertEquals(20000, $to['adjustments'][0]['amount']);
    }

    public function test_el_servicio_sin_subir_de_una_quincena_pagada_ya_no_se_puede_cobrar(): void
    {
        $viejo = $this->sinCobrar('2026-09-14');
        $this->assertCount(1, $this->miDia()['pending_checkout']);

        // Se le paga hasta el 15, hoy 16.
        $this->pagarHasta('2026-09-15');

        $dia = $this->miDia();
        $this->assertSame([], $dia['pending_checkout']);
        $this->assertSame([$viejo->id], array_column($dia['expired_checkout'], 'id'));

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('ya recibió su pago el 16 de septiembre');

        app(CheckoutService::class)->checkout($viejo, $this->efectivo, $this->admin);
    }

    public function test_la_api_de_cobro_lo_rechaza_con_el_motivo(): void
    {
        $viejo = $this->sinCobrar('2026-09-14');
        $this->pagarHasta('2026-09-15');

        Sanctum::actingAs($this->admin->fresh());

        $this->postJson("/api/v1/appointments/{$viejo->id}/checkout", ['payment_method_id' => $this->efectivo->id])
            ->assertStatus(422)
            ->assertJsonPath('message', fn (string $m) => str_contains($m, 'Maria ya recibió su pago'));

        $this->assertNull($viejo->fresh()->checked_out_at);
    }

    public function test_lo_del_dia_del_pago_en_adelante_sigue_abierto(): void
    {
        $hoy = $this->sinCobrar('2026-09-16', '08:00');
        $this->pagarHasta('2026-09-15');

        $this->assertSame([$hoy->id], array_column($this->miDia()['pending_checkout'], 'id'));

        $cobrada = app(CheckoutService::class)->checkout($hoy, $this->efectivo, $this->admin);
        $this->assertNotNull($cobrada->checked_out_at);
    }

    public function test_si_se_deshace_el_pago_se_reabre(): void
    {
        $viejo = $this->sinCobrar('2026-09-14');
        $pago = $this->pagarHasta('2026-09-15');
        $this->assertSame([], $this->miDia()['pending_checkout']);

        app(PayrollService::class)->undo($pago);

        $this->assertSame([$viejo->id], array_column($this->miDia()['pending_checkout'], 'id'));
    }

    public function test_si_la_otra_persona_no_se_ha_pagado_la_cita_se_puede_cobrar(): void
    {
        $lucia = $this->makeResource($this->business, 'Lucia', '08:00:00', '20:00:00');
        $manos = $this->makeService($this->business, 60, [$this->maria, $lucia], name: 'Manos');
        $cita = app(BookingService::class)->book(
            $this->business,
            [
                ['service_id' => $manos->id, 'resource_id' => $this->maria->id, 'starts_at' => $this->dia('2026-09-14')->setTime(9, 0)],
                ['service_id' => $manos->id, 'resource_id' => $lucia->id, 'starts_at' => $this->dia('2026-09-14')->setTime(9, 0)],
            ],
            null, 'Carolina', null, Appointment::SOURCE_ADMIN, null, false,
        );

        // Solo a Maria se le pagó.
        $this->pagarHasta('2026-09-15');

        $cobrada = app(CheckoutService::class)->checkout($cita, $this->efectivo, $this->admin);
        $this->assertNotNull($cobrada->checked_out_at);
    }
}
