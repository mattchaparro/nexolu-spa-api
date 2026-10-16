<?php

namespace Tests\Feature\Cash;

use App\Models\Appointment;
use App\Models\Business;
use App\Models\PaymentMethod;
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
 * Ver las citas sin cobrar y borrarlas; y que cobrar no se pueda repetir.
 *
 * El resumen del día solo decía «3 citas sin cobrar» sin cuáles. Y el cobro
 * revisaba «¿ya está cobrada?» sobre una copia vieja de la cita: dos
 * peticiones simultáneas pasaban las dos.
 */
class SinCobrarTest extends TestCase
{
    use RefreshDatabase, SchedulingScenario;

    private Business $business;

    private Resource $maria;

    private Service $semi;

    private User $admin;

    private PaymentMethod $efectivo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-09-16 18:00', 'America/Bogota'));
        PermissionCatalog::sync();

        $this->business = $this->makeBusiness(['min_booking_notice_min' => 0]);
        $this->maria = $this->makeResource($this->business, 'Maria', '08:00:00', '20:00:00');
        $this->semi = $this->makeService($this->business, 60, [$this->maria], name: 'Semipermanente');

        $this->admin = $this->usuario('ana@prueba.test', PermissionCatalog::ROLE_ADMIN);
        Sanctum::actingAs($this->admin);

        $this->efectivo = PaymentMethod::withoutGlobalScopes()->create([
            'business_id' => $this->business->id, 'name' => 'Efectivo',
            'counts_as_cash' => true, 'is_active' => true, 'sort_order' => 0,
        ]);
    }

    private function usuario(string $email, string $rol): User
    {
        $user = User::create([
            'business_id' => $this->business->id, 'name' => 'Persona',
            'email' => $email, 'password' => Hash::make('password123'), 'is_active' => true,
        ]);
        PermissionCatalog::applyRole($user, $rol);

        return $user->fresh();
    }

    private function cita(string $fecha, string $hora = '10:00'): Appointment
    {
        return app(BookingService::class)->book(
            $this->business,
            [['service_id' => $this->semi->id, 'resource_id' => $this->maria->id,
                'starts_at' => CarbonImmutable::parse($fecha, 'America/Bogota')->setTimeFromTimeString($hora)]],
            null, 'Carolina Pérez', null, Appointment::SOURCE_ADMIN, null, false,
        );
    }

    public function test_la_lista_trae_las_sin_cobrar_del_dia(): void
    {
        $sin = $this->cita('2026-09-16', '09:00');
        $cobrada = $this->cita('2026-09-16', '11:00');
        app(CheckoutService::class)->checkout($cobrada, $this->efectivo, $this->admin);
        $this->cita('2026-09-15', '10:00');

        $r = $this->getJson('/api/v1/daily-summary/unbilled?date=2026-09-16')->assertOk()->json('items');

        $this->assertSame([$sin->id], array_column($r, 'appointment_id'));
        $this->assertSame('Semipermanente', $r[0]['services']);
        $this->assertSame('Maria', $r[0]['people']);
        $this->assertFalse($r[0]['expired']);
    }

    public function test_con_todos_los_dias_trae_las_viejas_pero_no_las_futuras(): void
    {
        $vieja = $this->cita('2026-09-10');
        $hoy = $this->cita('2026-09-16', '09:00');
        $this->cita('2026-09-18');

        $r = $this->getJson('/api/v1/daily-summary/unbilled?date=2026-09-16&all=1')->assertOk()->json('items');

        $this->assertSame([$vieja->id, $hoy->id], array_column($r, 'appointment_id'));
    }

    public function test_marca_las_que_ya_no_se_pueden_cobrar_por_la_quincena_pagada(): void
    {
        $vieja = $this->cita('2026-09-10');
        $cobrada = $this->cita('2026-09-11');
        app(CheckoutService::class)->checkout($cobrada, $this->efectivo, $this->admin);
        app(PayrollService::class)->settle($this->business, $this->maria->fresh(), CarbonImmutable::parse('2026-09-15', 'America/Bogota'), $this->admin);

        $r = $this->getJson('/api/v1/daily-summary/unbilled?date=2026-09-10')->assertOk()->json('items');

        $this->assertSame($vieja->id, $r[0]['appointment_id']);
        $this->assertTrue($r[0]['expired']);
    }

    public function test_no_salen_canceladas_ni_no_asistio(): void
    {
        $a = $this->cita('2026-09-16', '09:00');
        $a->update(['status' => Appointment::STATUS_CANCELLED]);
        $b = $this->cita('2026-09-16', '11:00');
        $b->update(['status' => Appointment::STATUS_NO_SHOW]);

        $this->assertSame([], $this->getJson('/api/v1/daily-summary/unbilled?date=2026-09-16')->json('items'));
    }

    public function test_el_admin_borra_una_sin_cobrar_y_desaparece_de_la_lista(): void
    {
        $cita = $this->cita('2026-09-16', '09:00');

        $this->deleteJson("/api/v1/appointments/{$cita->id}", ['reason' => 'Nunca se hizo'])->assertNoContent();

        $this->assertSame([], $this->getJson('/api/v1/daily-summary/unbilled?date=2026-09-16')->json('items'));
        $this->assertNull(Appointment::find($cita->id));
    }

    public function test_la_lista_pide_el_permiso_de_reportes(): void
    {
        Sanctum::actingAs($this->usuario('maria@prueba.test', PermissionCatalog::ROLE_STAFF));

        $this->getJson('/api/v1/daily-summary/unbilled?date=2026-09-16')->assertForbidden();
    }

    public function test_cobrar_dos_veces_la_misma_cita_con_una_copia_vieja_no_se_deja(): void
    {
        $cita = $this->cita('2026-09-16', '09:00');

        // Dos peticiones que llegaron a la vez: las dos cargaron la cita sin cobrar.
        $copiaVieja = Appointment::withoutGlobalScopes()->find($cita->id);
        app(CheckoutService::class)->checkout($cita, $this->efectivo, $this->admin);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('ya fue cobrada');

        app(CheckoutService::class)->checkout($copiaVieja, $this->efectivo, $this->admin);
    }
}
