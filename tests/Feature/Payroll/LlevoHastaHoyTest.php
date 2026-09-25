<?php

namespace Tests\Feature\Payroll;

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
 * «¿Cuánto llevo?», lo primero de Mi día.
 *
 * La semana y el mes no contestan esa pregunta: el pago no corta por semanas
 * ni por meses, corta cuando se liquida. Marcela quería saber sus 262.500 del
 * periodo, no lo de esta semana.
 */
class LlevoHastaHoyTest extends TestCase
{
    use RefreshDatabase, SchedulingScenario;

    private Business $business;

    private Resource $maria;

    private Service $semi;

    private User $empleada;

    private PaymentMethod $efectivo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-09-16 18:00', 'America/Bogota'));

        PermissionCatalog::sync();

        $this->business = $this->makeBusiness(['min_booking_notice_min' => 0]);
        $this->maria = $this->makeResource($this->business, 'Maria', '08:00:00', '20:00:00');
        $this->semi = $this->makeService($this->business, 60, [$this->maria], name: 'Semipermanente');

        $this->empleada = User::create([
            'business_id' => $this->business->id, 'name' => 'Maria',
            'email' => 'maria@prueba.test', 'password' => Hash::make('password123'),
            'is_active' => true,
        ]);
        $this->maria->update(['user_id' => $this->empleada->id]);
        PermissionCatalog::applyRole($this->empleada, PermissionCatalog::ROLE_STAFF);

        $this->efectivo = PaymentMethod::withoutGlobalScopes()->create([
            'business_id' => $this->business->id, 'name' => 'Efectivo',
            'counts_as_cash' => true, 'is_active' => true, 'sort_order' => 0,
        ]);

        Sanctum::actingAs($this->empleada->fresh());
    }

    /** Un servicio atendido y cobrado ese día a las 10. */
    private function atendidoEl(CarbonImmutable $dia): Appointment
    {
        $cita = app(BookingService::class)->book(
            $this->business,
            [['service_id' => $this->semi->id, 'resource_id' => $this->maria->id, 'starts_at' => $dia->setTime(10, 0)]],
            null, 'Carolina', null, Appointment::SOURCE_ADMIN, null, false,
        );

        return app(CheckoutService::class)->checkout(
            $cita, $this->efectivo, $this->empleada,
            cobradoEn: $dia->setTime(11, 0)->utc(),
        );
    }

    private function miDia(): array
    {
        return $this->getJson('/api/v1/my-work')->assertOk()->json();
    }

    public function test_lo_que_lleva_incluye_lo_de_dias_anteriores_sin_pagar(): void
    {
        $hoy = CarbonImmutable::now('America/Bogota');
        $this->atendidoEl($hoy->subDays(2));
        $this->atendidoEl($hoy);

        $dia = $this->miDia();

        $this->assertSame(2, $dia['to_date']['services']);
        $this->assertEquals($dia['today']['commission'] * 2, $dia['to_date']['commission']);
        $this->assertGreaterThan(0, $dia['to_date']['commission']);
        $this->assertSame(1, $dia['today']['services']);
    }

    public function test_es_la_misma_cifra_de_la_liquidacion(): void
    {
        /*
         * Si esta pantalla sacara su propia cuenta, un día le diría una
         * cifra y quien paga vería otra.
         */
        $hoy = CarbonImmutable::now('America/Bogota');
        $this->atendidoEl($hoy->subDay());
        $this->atendidoEl($hoy);

        $liquidacion = app(PayrollService::class)->preview($this->business, $this->maria->fresh(), $hoy);

        $this->assertEquals($liquidacion['net_total'], $this->miDia()['to_date']['net']);
    }

    public function test_despues_de_pagarle_vuelve_a_empezar(): void
    {
        $hoy = CarbonImmutable::now('America/Bogota');
        $this->atendidoEl($hoy->subDays(3));

        app(PayrollService::class)->settle($this->business, $this->maria->fresh(), $hoy->subDay(), $this->empleada);

        $this->atendidoEl($hoy);

        $dia = $this->miDia();
        $this->assertSame(1, $dia['to_date']['services']);
        $this->assertEquals($dia['today']['commission'], $dia['to_date']['commission']);
    }
}
