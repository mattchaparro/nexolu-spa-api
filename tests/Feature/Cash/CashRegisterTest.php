<?php

namespace Tests\Feature\Cash;

use App\Models\Business;
use App\Models\Expense;
use App\Models\PaymentMethod;
use App\Models\Resource;
use App\Models\Service;
use App\Models\User;
use App\Support\PermissionCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Scheduling\SchedulingScenario;
use Tests\TestCase;

/**
 * Turno de caja, cierre del dia, gastos y resumen.
 *
 * El motor viene del POS. Lo que se prueba aca son las dos reglas que hacen
 * que un cierre cuadre y que Blue Souls nunca tuvo bien: el ingreso cuenta
 * por cuando se COBRO, y el gasto por su fecha CONTABLE.
 */
class CashRegisterTest extends TestCase
{
    use RefreshDatabase, SchedulingScenario;

    private Business $business;

    private User $admin;

    private Resource $maria;

    private Service $service;

    private PaymentMethod $efectivo;

    private PaymentMethod $transferencia;

    protected function setUp(): void
    {
        parent::setUp();

        PermissionCatalog::sync();

        $this->business = $this->makeBusiness(['slot_granularity_min' => 60]);

        // El turno viene APAGADO por defecto: en un spa nadie abre y cierra
        // caja por turnos. Se enciende aca a proposito, porque esta clase
        // prueba justamente esa funcion para el negocio que si la use.
        $this->business->update([
            'feature_flags' => array_merge($this->business->feature_flags ?? [], ['cash_shift' => true]),
        ]);
        $this->admin = User::create([
            'business_id' => $this->business->id,
            'name' => 'Admin',
            'email' => 'admin@prueba.test',
            'password' => Hash::make('password123'),
            'is_active' => true,
        ]);
        PermissionCatalog::applyRole($this->admin, PermissionCatalog::ROLE_ADMIN);

        $this->efectivo = PaymentMethod::create([
            'business_id' => $this->business->id, 'name' => 'Efectivo', 'counts_as_cash' => true,
        ]);
        $this->transferencia = PaymentMethod::create([
            'business_id' => $this->business->id, 'name' => 'Transferencia', 'counts_as_cash' => false,
        ]);

        $this->maria = $this->makeResource($this->business, 'Maria', '09:00:00', '18:00:00');
        $this->service = $this->makeService($this->business, 60, [$this->maria]);
        $this->service->update(['price' => 50000, 'commission_rate' => 0.30]);

        Sanctum::actingAs($this->admin);
    }

    /**
     * Un dia laboral que YA paso.
     *
     * Estas pruebas no pueden usar el miercoles ficticio de
     * SchedulingScenario: ese esta en el futuro a proposito, para que la
     * disponibilidad tenga sentido. El cierre de caja rechaza fechas futuras,
     * y con razon -- no se cuadra una caja que todavia no ocurrio.
     */
    private function laboral(int $diasAtras = 0): CarbonImmutable
    {
        $day = CarbonImmutable::now('America/Bogota')->startOfDay()->subDays($diasAtras);

        // El recurso trabaja de lunes a sabado.
        return $day->isSunday() ? $day->subDay() : $day;
    }

    private function cobrar(string $hora, PaymentMethod $metodo): int
    {
        $fecha = $this->laboral()->toDateString();

        $id = $this->postJson('/api/v1/appointments', [
            'service_id' => $this->service->id,
            'resource_id' => $this->maria->id,
            'starts_at' => "{$fecha} {$hora}:00",
            'client_name' => 'Cliente '.$hora,
        ])->assertCreated()->json('id');

        $this->postJson("/api/v1/appointments/{$id}/checkout", ['payment_method_id' => $metodo->id])
            ->assertOk();

        return $id;
    }

    public function test_el_turno_suma_solo_lo_que_cobro_esa_persona(): void
    {
        $this->postJson('/api/v1/cash/shift/open', ['opening_cash' => 50000])->assertCreated();

        $this->cobrar('10:00', $this->efectivo);
        $this->cobrar('11:00', $this->transferencia);

        $response = $this->getJson('/api/v1/cash/shift')->assertOk();

        $this->assertEqualsWithDelta(100000, $response->json('totals.total_charged'), 0.01);
        $this->assertEqualsWithDelta(50000, $response->json('totals.total_cash'), 0.01);
        // La transferencia suma a la venta pero NO al cajon.
        $this->assertEqualsWithDelta(50000, $response->json('totals.total_other_methods'), 0.01);
        $this->assertEqualsWithDelta(100000, $response->json('totals.expected_cash'), 0.01);
    }

    public function test_cerrar_el_turno_guarda_la_diferencia_aunque_sea_negativa(): void
    {
        $this->postJson('/api/v1/cash/shift/open', ['opening_cash' => 50000])->assertCreated();
        $this->cobrar('10:00', $this->efectivo);

        // Faltan 5.000 en el cajon. La diferencia es el dato que importa del
        // cierre: esconderla lo volveria inutil.
        $response = $this->postJson('/api/v1/cash/shift/close', ['counted_cash' => 95000])->assertOk();

        $this->assertEqualsWithDelta(100000, $response->json('expected_cash'), 0.01);
        $this->assertEqualsWithDelta(-5000, $response->json('difference'), 0.01);
        $this->assertFalse($response->json('is_open'));
    }

    public function test_no_se_pueden_tener_dos_turnos_abiertos(): void
    {
        $this->postJson('/api/v1/cash/shift/open', ['opening_cash' => 0])->assertCreated();
        $this->postJson('/api/v1/cash/shift/open', ['opening_cash' => 0])->assertStatus(422);
    }

    public function test_un_gasto_en_efectivo_baja_lo_esperado_y_uno_por_transferencia_no(): void
    {
        $fecha = $this->laboral()->toDateString();
        $this->cobrar('10:00', $this->efectivo);

        $sinGastos = $this->getJson("/api/v1/cash/closing/preview?date={$fecha}")->json('expected_cash');

        $this->postJson('/api/v1/expenses', [
            'date' => $fecha, 'description' => 'Domicilio insumos', 'value' => 10000,
            'scope' => Expense::SCOPE_OPERATIONAL, 'payment_method_id' => $this->efectivo->id,
        ])->assertCreated();

        $this->postJson('/api/v1/expenses', [
            'date' => $fecha, 'description' => 'Proveedor', 'value' => 30000,
            'scope' => Expense::SCOPE_OPERATIONAL, 'payment_method_id' => $this->transferencia->id,
        ])->assertCreated();

        $conGastos = $this->getJson("/api/v1/cash/closing/preview?date={$fecha}")->assertOk();

        // Los dos son gasto del negocio...
        $this->assertEqualsWithDelta(40000, $conGastos->json('total_expenses'), 0.01);
        // ...pero solo el de efectivo salio del cajon.
        $this->assertEqualsWithDelta($sinGastos - 10000, $conGastos->json('expected_cash'), 0.01);
    }

    public function test_un_gasto_administrativo_no_es_gasto_del_dia_pero_si_sale_del_cajon(): void
    {
        $fecha = $this->laboral()->toDateString();
        $this->cobrar('10:00', $this->efectivo);

        $antes = $this->getJson("/api/v1/cash/closing/preview?date={$fecha}")->json('expected_cash');

        $this->postJson('/api/v1/expenses', [
            'date' => $fecha, 'description' => 'Arriendo', 'value' => 2000000,
            'scope' => Expense::SCOPE_ADMINISTRATIVE, 'payment_method_id' => $this->efectivo->id,
        ])->assertCreated();

        $despues = $this->getJson("/api/v1/cash/closing/preview?date={$fecha}");

        // El arriendo es del mes, no de este martes: no entra al gasto del dia
        // ni haria ver la jornada en perdida.
        $this->assertEqualsWithDelta(0, $despues->json('total_expenses'), 0.01);

        // Pero se pago con billetes de la caja, y esos billetes ya no estan.
        // Excluirlo del cuadre por su clasificacion contable dejaba el cierre
        // corto sin nada que lo explicara.
        $this->assertEqualsWithDelta($antes - 2000000, $despues->json('expected_cash'), 0.01);
    }

    public function test_un_gasto_administrativo_por_transferencia_no_toca_la_caja(): void
    {
        $fecha = $this->laboral()->toDateString();
        $this->cobrar('10:00', $this->efectivo);

        $antes = $this->getJson("/api/v1/cash/closing/preview?date={$fecha}")->json('expected_cash');

        $this->postJson('/api/v1/expenses', [
            'date' => $fecha, 'description' => 'Arriendo', 'value' => 2000000,
            'scope' => Expense::SCOPE_ADMINISTRATIVE, 'payment_method_id' => $this->transferencia->id,
        ])->assertCreated();

        $this->assertEqualsWithDelta(
            $antes,
            $this->getJson("/api/v1/cash/closing/preview?date={$fecha}")->json('expected_cash'),
            0.01,
        );
    }

    public function test_un_gasto_con_fecha_de_ayer_no_descuenta_de_hoy(): void
    {
        $hoy = $this->laboral()->toDateString();
        $ayer = $this->laboral(1)->toDateString();
        $this->cobrar('10:00', $this->efectivo);

        $antes = $this->getJson("/api/v1/cash/closing/preview?date={$hoy}")->json('expected_cash');

        // Registrado hoy pero con fecha contable de ayer: la caja de hoy nunca
        // perdio esa plata.
        $this->postJson('/api/v1/expenses', [
            'date' => $ayer, 'description' => 'Gasto de ayer', 'value' => 20000,
            'scope' => Expense::SCOPE_OPERATIONAL, 'payment_method_id' => $this->efectivo->id,
        ])->assertCreated();

        $this->assertEqualsWithDelta(
            $antes,
            $this->getJson("/api/v1/cash/closing/preview?date={$hoy}")->json('expected_cash'),
            0.01,
        );
    }

    public function test_el_cierre_del_dia_deja_base_para_el_siguiente(): void
    {
        $fecha = $this->laboral()->toDateString();
        $this->cobrar('10:00', $this->efectivo);

        $this->postJson('/api/v1/cash/closing', [
            'date' => $fecha, 'actual_cash' => 48000,
        ])->assertCreated();

        // La base de manana es lo CONTADO, no lo esperado: el dia siguiente
        // arranca con la plata que hay.
        $this->assertDatabaseHas('cash_closings', ['date' => $fecha, 'base_for_next_day' => 48000.00]);

        $siguiente = $this->laboral()->addDay()->toDateString();
        $this->assertEqualsWithDelta(
            48000,
            $this->getJson("/api/v1/cash/closing/preview?date={$siguiente}")->json('opening_cash'),
            0.01,
        );
    }

    public function test_un_dia_solo_se_cierra_una_vez(): void
    {
        $fecha = $this->laboral()->toDateString();
        $payload = ['date' => $fecha, 'actual_cash' => 0];

        $this->postJson('/api/v1/cash/closing', $payload)->assertCreated();
        $this->postJson('/api/v1/cash/closing', $payload)->assertStatus(422);
    }

    public function test_no_se_puede_cerrar_un_dia_futuro(): void
    {
        $this->postJson('/api/v1/cash/closing', [
            'date' => now()->addDays(3)->toDateString(), 'actual_cash' => 0,
        ])->assertStatus(422);
    }

    public function test_los_dias_pendientes_solo_incluyen_los_que_tuvieron_movimiento(): void
    {
        // Sin citas, ningun dia pasado deberia aparecer como pendiente: una
        // lista con todos los lunes que el spa no abrio no la mira nadie.
        $this->assertSame([], $this->getJson('/api/v1/cash/closing/preview')->json('pending_dates'));
    }

    public function test_el_resumen_del_dia_reparte_por_profesional(): void
    {
        $fecha = $this->laboral()->toDateString();
        $this->cobrar('10:00', $this->efectivo);
        $this->cobrar('11:00', $this->transferencia);

        $response = $this->getJson("/api/v1/daily-summary?date={$fecha}")->assertOk();

        $this->assertSame(2, $response->json('appointments.total'));
        $this->assertSame(2, $response->json('appointments.completed'));
        $this->assertEqualsWithDelta(100000, $response->json('totals.total_charged'), 0.01);
        // 30% de 100.000: la pregunta que sigue a "cuanto se hizo hoy".
        $this->assertEqualsWithDelta(30000, $response->json('totals.total_commissions'), 0.01);

        $maria = collect($response->json('by_resource'))->firstWhere('name', 'Maria');
        $this->assertSame(2, $maria['appointments']);
        $this->assertEqualsWithDelta(30000, $maria['commission'], 0.01);
    }

    public function test_el_resumen_avisa_de_lo_que_falta_cobrar(): void
    {
        $fecha = $this->laboral()->toDateString();
        $this->cobrar('10:00', $this->efectivo);

        $this->postJson('/api/v1/appointments', [
            'service_id' => $this->service->id,
            'resource_id' => $this->maria->id,
            'starts_at' => "{$fecha} 15:00:00",
            'client_name' => 'Sin cobrar',
        ])->assertCreated();

        // La accion pendiente mas comun al cerrar la jornada.
        $this->getJson("/api/v1/daily-summary?date={$fecha}")
            ->assertOk()
            ->assertJsonPath('appointments.pending_checkout', 1);
    }

    public function test_solo_se_puede_deshacer_el_ultimo_cierre(): void
    {
        $primero = $this->laboral(2)->toDateString();
        $segundo = $this->laboral(1)->toDateString();

        $idPrimero = $this->postJson('/api/v1/cash/closing', ['date' => $primero, 'actual_cash' => 10000])
            ->assertCreated()->json('id');
        $idSegundo = $this->postJson('/api/v1/cash/closing', ['date' => $segundo, 'actual_cash' => 20000])
            ->assertCreated()->json('id');

        // Deshacer uno del medio dejaria a los dias siguientes con una base
        // que ya no corresponde, y nadie se enteraria.
        $this->deleteJson("/api/v1/cash/closings/{$idPrimero}")->assertStatus(422);
        $this->deleteJson("/api/v1/cash/closings/{$idSegundo}")->assertOk();
        $this->deleteJson("/api/v1/cash/closings/{$idPrimero}")->assertOk();
    }

    public function test_un_miembro_del_equipo_no_cierra_la_caja_del_negocio(): void
    {
        $staff = User::create([
            'business_id' => $this->business->id, 'name' => 'Maria',
            'email' => 'maria@prueba.test', 'password' => Hash::make('password123'), 'is_active' => true,
        ]);
        PermissionCatalog::applyRole($staff, PermissionCatalog::ROLE_STAFF);

        // El turno NO viene con el rol: en un spa nadie abre y cierra caja
        // por turnos. Se da a mano en el negocio que si tenga cajera, que es
        // el caso que esta prueba representa.
        $staff->givePermissionTo('caja.turno');

        Sanctum::actingAs($staff->fresh());

        // Puede manejar SU turno, no el cierre del negocio.
        $this->postJson('/api/v1/cash/shift/open', ['opening_cash' => 0])->assertCreated();
        $this->getJson('/api/v1/cash/closing/preview')->assertStatus(403);
        $this->postJson('/api/v1/cash/closing', ['date' => now()->toDateString(), 'actual_cash' => 0])
            ->assertStatus(403);
    }

    public function test_el_alcance_de_un_gasto_es_un_enum_cerrado(): void
    {
        $this->postJson('/api/v1/expenses', [
            'date' => $this->laboral()->toDateString(),
            'description' => 'X', 'value' => 1000, 'scope' => 'lo-que-sea',
        ])->assertStatus(422)->assertJsonValidationErrors('scope');
    }
}
