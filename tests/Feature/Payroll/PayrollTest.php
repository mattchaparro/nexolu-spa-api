<?php

namespace Tests\Feature\Payroll;

use App\Models\Business;
use App\Models\Expense;
use App\Models\PaymentMethod;
use App\Models\PayrollAdjustment;
use App\Models\Resource;
use App\Models\ResourceSchedule;
use App\Models\Service;
use App\Models\User;
use App\Support\Payroll\BasePeriod;
use App\Support\Payroll\PayrollMode;
use App\Support\PermissionCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Scheduling\SchedulingScenario;
use Tests\TestCase;

/**
 * Liquidar a una profesional, de punta a punta.
 *
 * Lo que estas pruebas cuidan es que nadie cobre dos veces lo mismo y que
 * nadie pierda un anticipo: los dos errores que cuestan plata de verdad.
 */
class PayrollTest extends TestCase
{
    use RefreshDatabase, SchedulingScenario;

    private Business $business;

    private User $admin;

    private Resource $maria;

    private Service $service;

    private PaymentMethod $efectivo;

    protected function setUp(): void
    {
        parent::setUp();

        PermissionCatalog::sync();

        $this->business = $this->makeBusiness();

        $this->admin = User::create([
            'business_id' => $this->business->id, 'name' => 'Ana',
            'email' => 'ana@prueba.test', 'password' => Hash::make('password123'), 'is_active' => true,
        ]);
        PermissionCatalog::applyRole($this->admin, PermissionCatalog::ROLE_ADMIN);

        $this->maria = Resource::create([
            'business_id' => $this->business->id, 'type' => Resource::TYPE_STAFF,
            'name' => 'Maria', 'is_active' => true,
            // Sin esto el periodo arrancaria el dia que se creo la ficha, que
            // en una prueba es hoy, y no habria ventana que liquidar.
            'payroll_started_on' => $this->dia(30)->toDateString(),
        ]);

        foreach (range(1, 7) as $weekday) {
            ResourceSchedule::create([
                'business_id' => $this->business->id, 'resource_id' => $this->maria->id,
                'weekday' => $weekday, 'start_time' => '00:00:00', 'end_time' => '23:59:00',
                'effective_from' => '2020-01-01',
            ]);
        }

        $this->service = $this->makeService($this->business, 60, [$this->maria]);
        $this->service->update(['price' => 100000, 'commission_rate' => 0.40]);

        $this->efectivo = PaymentMethod::create([
            'business_id' => $this->business->id, 'name' => 'Efectivo',
            'counts_as_cash' => true, 'is_active' => true, 'sort_order' => 1,
        ]);

        Sanctum::actingAs($this->admin->fresh());
    }

    /** Un dia pasado, en la zona del negocio. */
    private function dia(int $diasAtras): CarbonImmutable
    {
        return CarbonImmutable::now($this->business->businessTimezone())
            ->subDays($diasAtras)->startOfDay();
    }

    /**
     * Un servicio prestado y cobrado. La comision se congela al cobrar, que es
     * la fecha que cuenta para la nomina.
     */
    private function cobrar(int $diasAtras, int $hora = 10): void
    {
        $cuando = $this->dia($diasAtras)->setTime($hora, 0);

        $cita = $this->postJson('/api/v1/walk-in', [
            'service_id' => $this->service->id,
            'resource_id' => $this->maria->id,
            'started_at' => $cuando->format('Y-m-d H:i:s'),
            'client_name' => 'Cliente',
            'payment_method_id' => $this->efectivo->id,
        ])->assertCreated();

        // El checkout se sella con la hora real; para las pruebas se corre a
        // la fecha del servicio, que es lo que pasaria en produccion cuando se
        // cobra el mismo dia.
        \App\Models\Appointment::withoutGlobalScope('business')
            ->whereKey($cita->json('id'))
            ->update(['checked_out_at' => $cuando->utc()]);
    }

    private function anticipo(int $diasAtras, float $monto, string $categoria = 'anticipo'): int
    {
        return $this->postJson('/api/v1/payroll/adjustments', [
            'resource_id' => $this->maria->id,
            'date' => $this->dia($diasAtras)->toDateString(),
            'category' => $categoria,
            'amount' => $monto,
        ])->assertCreated()->json('id');
    }

    /*
    |--------------------------------------------------------------------------
    | Lo basico
    |--------------------------------------------------------------------------
    */

    public function test_a_comision_se_liquida_lo_cobrado_en_el_periodo(): void
    {
        $this->cobrar(10);
        $this->cobrar(5);

        $preview = $this->getJson("/api/v1/payroll/resources/{$this->maria->id}/preview")
            ->assertOk();

        $this->assertSame(2, $preview->json('services_count'));
        $this->assertEqualsWithDelta(200000, $preview->json('charged_total'), 0.01);
        // 40% de 200.000.
        $this->assertEqualsWithDelta(80000, $preview->json('commission_total'), 0.01);
        $this->assertEqualsWithDelta(80000, $preview->json('net_total'), 0.01);
    }

    public function test_el_periodo_arranca_donde_termino_el_anterior(): void
    {
        $this->cobrar(20);

        $this->postJson("/api/v1/payroll/resources/{$this->maria->id}/settle", [
            'until' => $this->dia(15)->toDateString(),
            'payment_method_id' => $this->efectivo->id,
        ])->assertCreated();

        $this->cobrar(10);

        $preview = $this->getJson("/api/v1/payroll/resources/{$this->maria->id}/preview")->assertOk();

        // Arranca al dia siguiente del corte anterior, no donde alguien
        // escriba. Es lo que impide pagar dos veces la misma quincena.
        $this->assertSame($this->dia(14)->toDateString(), $preview->json('period_start'));
        $this->assertSame(1, $preview->json('services_count'));
        $this->assertEqualsWithDelta(40000, $preview->json('commission_total'), 0.01);
    }

    public function test_no_se_puede_liquidar_hacia_atras_de_lo_ya_pagado(): void
    {
        $this->cobrar(20);

        $this->postJson("/api/v1/payroll/resources/{$this->maria->id}/settle", [
            'until' => $this->dia(10)->toDateString(),
        ])->assertCreated();

        $this->getJson("/api/v1/payroll/resources/{$this->maria->id}/preview?until="
            .$this->dia(15)->toDateString())
            ->assertStatus(422);
    }

    public function test_un_servicio_sin_cobrar_no_se_liquida(): void
    {
        // Prestado pero no cobrado: la comision no se gano todavia. Es la
        // misma regla del cierre de caja, y si no coincidieran la nomina y la
        // caja contarian la misma plata en dias distintos.
        $this->postJson('/api/v1/walk-in', [
            'service_id' => $this->service->id,
            'resource_id' => $this->maria->id,
            'started_at' => $this->dia(3)->setTime(10, 0)->format('Y-m-d H:i:s'),
            'client_name' => 'Sin cobrar',
        ])->assertCreated();

        $preview = $this->getJson("/api/v1/payroll/resources/{$this->maria->id}/preview")->assertOk();

        $this->assertSame(0, $preview->json('services_count'));
    }

    /*
    |--------------------------------------------------------------------------
    | Anticipos y descuentos
    |--------------------------------------------------------------------------
    */

    public function test_un_anticipo_se_descuenta_una_sola_vez(): void
    {
        $this->cobrar(10);
        $this->anticipo(8, 20000);

        $preview = $this->getJson("/api/v1/payroll/resources/{$this->maria->id}/preview")->assertOk();
        $this->assertEqualsWithDelta(20000, $preview->json('deduction_total'), 0.01);
        $this->assertEqualsWithDelta(20000, $preview->json('net_total'), 0.01);

        $this->postJson("/api/v1/payroll/resources/{$this->maria->id}/settle", [
            'until' => $this->dia(6)->toDateString(),
            'payment_method_id' => $this->efectivo->id,
        ])->assertCreated();

        $this->cobrar(2);

        // El anticipo ya se cobro: no vuelve a aparecer.
        $siguiente = $this->getJson("/api/v1/payroll/resources/{$this->maria->id}/preview")->assertOk();
        $this->assertEqualsWithDelta(0, $siguiente->json('deduction_total'), 0.01);
        $this->assertEqualsWithDelta(40000, $siguiente->json('net_total'), 0.01);
    }

    public function test_un_anticipo_mas_viejo_que_el_periodo_igual_entra(): void
    {
        // El bug de la app del local: el anticipo se buscaba dentro de la
        // ventana, asi que uno con fecha anterior quedaba pendiente para
        // siempre y nunca se cobraba.
        $this->cobrar(20);
        $this->postJson("/api/v1/payroll/resources/{$this->maria->id}/settle", [
            'until' => $this->dia(18)->toDateString(),
        ])->assertCreated();

        // Anticipo digitado tarde, con fecha anterior al periodo que ya se
        // liquido. Pasa todo el tiempo: se entrego la plata y se anoto despues.
        $this->anticipo(25, 50000);

        $this->cobrar(5);

        $preview = $this->getJson("/api/v1/payroll/resources/{$this->maria->id}/preview")->assertOk();

        // Quedo pendiente y aparece marcado como de fuera del periodo, para
        // que quien paga entienda de donde sale.
        $this->assertEqualsWithDelta(50000, $preview->json('deduction_total'), 0.01);
        $this->assertTrue($preview->json('adjustments.0.outside_period'));
    }

    public function test_un_bono_suma(): void
    {
        $this->cobrar(5);
        $this->anticipo(3, 30000, 'bono');

        $preview = $this->getJson("/api/v1/payroll/resources/{$this->maria->id}/preview")->assertOk();

        $this->assertEqualsWithDelta(30000, $preview->json('bonus_total'), 0.01);
        $this->assertEqualsWithDelta(70000, $preview->json('net_total'), 0.01);
    }

    public function test_el_tipo_lo_pone_el_catalogo_no_quien_llama(): void
    {
        // Mandar kind: 'bonus' con categoria 'anticipo' sumaria en vez de
        // restar. El campo ni siquiera se lee del cuerpo.
        $this->postJson('/api/v1/payroll/adjustments', [
            'resource_id' => $this->maria->id,
            'date' => $this->dia(2)->toDateString(),
            'category' => 'anticipo',
            'kind' => PayrollAdjustment::KIND_BONUS,
            'amount' => 40000,
        ])->assertCreated();

        $this->assertDatabaseHas('payroll_adjustments', [
            'resource_id' => $this->maria->id,
            'kind' => PayrollAdjustment::KIND_DEDUCTION,
        ]);
    }

    public function test_un_movimiento_ya_liquidado_no_se_borra(): void
    {
        $this->cobrar(5);
        $id = $this->anticipo(4, 10000);

        $this->postJson("/api/v1/payroll/resources/{$this->maria->id}/settle")->assertCreated();

        $this->deleteJson("/api/v1/payroll/adjustments/{$id}")->assertStatus(422);
    }

    /*
    |--------------------------------------------------------------------------
    | Base y minimo garantizado
    |--------------------------------------------------------------------------
    */

    public function test_base_mas_comision_prorratea_por_los_dias_del_periodo(): void
    {
        $this->putJson("/api/v1/payroll/compensation/{$this->maria->id}", [
            'payroll_mode' => PayrollMode::BASE_PLUS_COMMISSION,
            'base_amount' => 1_500_000,
            'base_period' => BasePeriod::MONTH,
        ])->assertOk();

        $this->cobrar(5);

        // El periodo va desde payroll_started_on (hace 30 dias) hasta hoy: 31
        // dias inclusive, a 50.000 diarios.
        $preview = $this->getJson("/api/v1/payroll/resources/{$this->maria->id}/preview")->assertOk();

        $this->assertSame(31, $preview->json('days'));
        $this->assertEqualsWithDelta(1_550_000, $preview->json('base_total'), 0.01);
        $this->assertEqualsWithDelta(1_590_000, $preview->json('net_total'), 0.01);
    }

    public function test_la_base_temporal_deja_de_pagarse_cuando_vence(): void
    {
        // La base que se le da a quien entra mientras arma clientela: vencio
        // hace 20 dias, asi que solo cubre 11 de los 31 del periodo.
        $this->putJson("/api/v1/payroll/compensation/{$this->maria->id}", [
            'payroll_mode' => PayrollMode::BASE_PLUS_COMMISSION,
            'base_amount' => 300000,
            'base_period' => BasePeriod::WEEK,
            'base_until' => $this->dia(20)->toDateString(),
        ])->assertOk();

        $preview = $this->getJson("/api/v1/payroll/resources/{$this->maria->id}/preview")->assertOk();

        // 300.000 / 7 = 42.857,14 por dia, x 11 dias.
        $this->assertEqualsWithDelta(471428.57, $preview->json('base_total'), 0.05);
    }

    public function test_el_minimo_garantizado_completa_pero_no_recorta(): void
    {
        $this->putJson("/api/v1/payroll/compensation/{$this->maria->id}", [
            'payroll_mode' => PayrollMode::GUARANTEED_MINIMUM,
            'base_amount' => 10000,
            'base_period' => BasePeriod::DAY,
        ])->assertOk();

        // Piso de 10.000 x 31 dias = 310.000. Genero 40.000 de comision.
        $this->cobrar(5);

        $preview = $this->getJson("/api/v1/payroll/resources/{$this->maria->id}/preview")->assertOk();

        $this->assertEqualsWithDelta(310000, $preview->json('earned_total'), 0.01);
        $this->assertEqualsWithDelta(270000, $preview->json('topped_up'), 0.01);

        // Y si produce mas que el piso, se le paga lo que produjo.
        for ($hora = 8; $hora < 18; $hora++) {
            $this->cobrar(4, $hora);
        }

        $mejor = $this->getJson("/api/v1/payroll/resources/{$this->maria->id}/preview")->assertOk();
        $this->assertEqualsWithDelta(440000, $mejor->json('earned_total'), 0.01);
        $this->assertEqualsWithDelta(0, $mejor->json('topped_up'), 0.01);
    }

    /*
    |--------------------------------------------------------------------------
    | El pago
    |--------------------------------------------------------------------------
    */

    public function test_pagar_congela_el_comprobante(): void
    {
        $this->cobrar(5);

        $id = $this->postJson("/api/v1/payroll/resources/{$this->maria->id}/settle", [
            'payment_method_id' => $this->efectivo->id,
            'notes' => 'Quincena de agosto',
        ])->assertCreated()->json('id');

        // Se le sube el porcentaje despues de pagarle.
        $this->service->update(['commission_rate' => 0.60, 'price' => 200000]);

        $comprobante = $this->getJson("/api/v1/payroll/settlements/{$id}")->assertOk();

        // El papel que firmo sigue diciendo lo mismo.
        $this->assertEqualsWithDelta(40000, $comprobante->json('commission_total'), 0.01);
        $this->assertEqualsWithDelta(100000, $comprobante->json('items.0.charged'), 0.01);
        $this->assertEqualsWithDelta(0.40, $comprobante->json('items.0.commission_rate'), 0.001);
        $this->assertSame('Quincena de agosto', $comprobante->json('notes'));
    }

    public function test_el_pago_queda_como_gasto_del_negocio(): void
    {
        $this->cobrar(5);

        $this->postJson("/api/v1/payroll/resources/{$this->maria->id}/settle", [
            'payment_method_id' => $this->efectivo->id,
        ])->assertCreated();

        // Si la nomina no aparece como gasto, la plata sale de la caja sin
        // rastro y el cierre no cuadra.
        $gasto = Expense::withoutGlobalScope('business')
            ->where('business_id', $this->business->id)->latest('id')->first();

        $this->assertNotNull($gasto);
        $this->assertEqualsWithDelta(40000, (float) $gasto->value, 0.01);
        $this->assertSame(Expense::SCOPE_ADMINISTRATIVE, $gasto->scope);
        $this->assertStringContainsString('Maria', $gasto->description);
    }

    public function test_pagado_en_efectivo_descuenta_de_la_caja_del_dia(): void
    {
        $this->cobrar(0);

        $hoy = CarbonImmutable::now($this->business->businessTimezone())->toDateString();
        $antes = $this->getJson("/api/v1/cash/closing/preview?date={$hoy}")->assertOk()->json('expected_cash');

        $this->postJson("/api/v1/payroll/resources/{$this->maria->id}/settle", [
            'payment_method_id' => $this->efectivo->id,
        ])->assertCreated();

        $despues = $this->getJson("/api/v1/cash/closing/preview?date={$hoy}")->assertOk();

        // Le pagaste con billetes del cajon: esos billetes ya no estan.
        $this->assertEqualsWithDelta($antes - 40000, $despues->json('expected_cash'), 0.01);
        // Pero la nomina no es gasto de operar el dia.
        $this->assertEqualsWithDelta(0, $despues->json('total_expenses'), 0.01);
    }

    public function test_un_neto_negativo_no_genera_gasto(): void
    {
        // Pidio mas anticipos de lo que produjo: no sale plata, no hay gasto.
        $this->cobrar(5);
        $this->anticipo(4, 100000);

        $antes = Expense::withoutGlobalScope('business')->count();

        $respuesta = $this->postJson("/api/v1/payroll/resources/{$this->maria->id}/settle")
            ->assertCreated();

        $this->assertEqualsWithDelta(-60000, $respuesta->json('net_total'), 0.01);
        $this->assertSame($antes, Expense::withoutGlobalScope('business')->count());
    }

    public function test_deshacer_devuelve_los_anticipos_a_pendientes(): void
    {
        $this->cobrar(5);
        $this->anticipo(4, 10000);

        $id = $this->postJson("/api/v1/payroll/resources/{$this->maria->id}/settle", [
            'payment_method_id' => $this->efectivo->id,
        ])->assertCreated()->json('id');

        $this->deleteJson("/api/v1/payroll/settlements/{$id}")->assertOk();

        // Vuelve a estar pendiente, y el gasto se fue con la liquidacion.
        $preview = $this->getJson("/api/v1/payroll/resources/{$this->maria->id}/preview")->assertOk();
        $this->assertEqualsWithDelta(10000, $preview->json('deduction_total'), 0.01);
        $this->assertEqualsWithDelta(40000, $preview->json('commission_total'), 0.01);

        // El gasto se anula. Queda con deleted_at y no desaparece de la base:
        // borrar de verdad un movimiento de plata deja un hueco que nadie
        // puede auditar despues.
        $this->assertSame(0, Expense::withoutGlobalScope('business')->count());
        $this->assertSame(1, Expense::withoutGlobalScope('business')->withTrashed()->count());
    }

    public function test_solo_se_deshace_la_ultima(): void
    {
        $this->cobrar(20);
        $primera = $this->postJson("/api/v1/payroll/resources/{$this->maria->id}/settle", [
            'until' => $this->dia(15)->toDateString(),
        ])->assertCreated()->json('id');

        $this->cobrar(10);
        $this->postJson("/api/v1/payroll/resources/{$this->maria->id}/settle")->assertCreated();

        // Borrar una del medio dejaria el periodo siguiente arrancando despues
        // de un hueco que nadie liquido.
        $this->deleteJson("/api/v1/payroll/settlements/{$primera}")->assertStatus(422);
    }

    /*
    |--------------------------------------------------------------------------
    | Alcance
    |--------------------------------------------------------------------------
    */

    public function test_el_resumen_lista_a_todo_el_equipo(): void
    {
        $this->cobrar(5);

        $pendiente = $this->getJson('/api/v1/payroll/pending')->assertOk();

        $maria = collect($pendiente->json('resources'))->firstWhere('name', 'Maria');
        $this->assertEqualsWithDelta(40000, $maria['net_total'], 0.01);
    }

    public function test_una_profesional_no_entra_a_la_nomina(): void
    {
        $staff = User::create([
            'business_id' => $this->business->id, 'name' => 'Maria',
            'email' => 'maria@prueba.test', 'password' => Hash::make('password123'), 'is_active' => true,
        ]);
        PermissionCatalog::applyRole($staff, PermissionCatalog::ROLE_STAFF);

        Sanctum::actingAs($staff->fresh());

        $this->getJson('/api/v1/payroll/pending')->assertForbidden();
        $this->postJson('/api/v1/payroll/adjustments', [
            'resource_id' => $this->maria->id, 'date' => $this->dia(1)->toDateString(),
            'category' => 'bono', 'amount' => 500000,
        ])->assertForbidden();
    }

    public function test_no_se_le_registra_un_descuento_a_alguien_de_otro_negocio(): void
    {
        $otro = $this->makeBusiness();

        // Por DB directo: `BelongsToBusiness` reasigna el business_id al del
        // usuario autenticado al crear, asi que un Resource::create() aca
        // terminaria dentro del negocio de Ana y no probaria nada.
        $ajenaId = \Illuminate\Support\Facades\DB::table('resources')->insertGetId([
            'business_id' => $otro->id, 'type' => Resource::TYPE_STAFF,
            'name' => 'Ajena', 'is_active' => true, 'sort_order' => 0,
            'payroll_mode' => PayrollMode::COMMISSION, 'base_amount' => 0,
            'base_period' => BasePeriod::MONTH,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->postJson('/api/v1/payroll/adjustments', [
            'resource_id' => $ajenaId,
            'date' => $this->dia(1)->toDateString(),
            'category' => 'anticipo',
            'amount' => 10000,
        ])->assertNotFound();
    }
}
