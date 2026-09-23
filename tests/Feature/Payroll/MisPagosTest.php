<?php

namespace Tests\Feature\Payroll;

use App\Models\Business;
use App\Models\PayrollSettlement;
use App\Models\Resource;
use App\Models\User;
use App\Support\PermissionCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Scheduling\SchedulingScenario;
use Tests\TestCase;

/**
 * Lo que YA le pagaron, visto por ella misma.
 *
 * En la app vieja era una pantalla suya (Employee/Payments) y es de las que
 * más se miran. Sin esto, "¿cuánto me pagaron el mes pasado?" vuelve a ser
 * una pregunta para el administrador -- y la nómina es exactamente el tema
 * en el que nadie quiere depender de la memoria de otro.
 */
class MisPagosTest extends TestCase
{
    use RefreshDatabase, SchedulingScenario;

    private Business $business;

    private Resource $maria;

    private Resource $lucia;

    private User $empleada;

    protected function setUp(): void
    {
        parent::setUp();

        PermissionCatalog::sync();

        $this->business = $this->makeBusiness();
        $this->maria = $this->makeResource($this->business, 'Maria');
        $this->lucia = $this->makeResource($this->business, 'Lucia');

        $this->empleada = User::create([
            'business_id' => $this->business->id, 'name' => 'Maria',
            'email' => 'maria@prueba.test', 'password' => Hash::make('password123'),
            'is_active' => true,
        ]);

        $this->maria->update(['user_id' => $this->empleada->id]);
        PermissionCatalog::applyRole($this->empleada, PermissionCatalog::ROLE_STAFF);

        Sanctum::actingAs($this->empleada->fresh());
    }

    private function liquidacion(Resource $de, array $datos = []): PayrollSettlement
    {
        $mes = CarbonImmutable::now('America/Bogota')->startOfMonth();

        return PayrollSettlement::create($datos + [
            'business_id' => $this->business->id,
            'resource_id' => $de->id,
            'period_start' => $mes->subMonth()->toDateString(),
            'period_end' => $mes->subDay()->toDateString(),
            'mode' => 'commission',
            'base_amount' => 0,
            'base_period' => 'month',
            'services_count' => 40,
            'charged_total' => 2000000,
            'commission_total' => 800000,
            'base_total' => 0,
            'bonus_total' => 50000,
            'deduction_total' => 30000,
            'net_total' => 820000,
            'paid_at' => $mes->toDateTimeString(),
        ]);
    }

    private function miDia(): array
    {
        return $this->getJson('/api/v1/my-work')->assertOk()->json();
    }

    public function test_ve_lo_que_le_pagaron_con_su_desglose(): void
    {
        /*
         * El desglose y no sólo el neto: el descuento que no se entiende es
         * el que termina en una discusión el día de pago.
         */
        $this->liquidacion($this->maria);

        $pago = $this->miDia()['payments'][0];

        $this->assertSame(40, $pago['services_count']);
        $this->assertEquals(800000, $pago['commission_total']);
        $this->assertEquals(50000, $pago['bonus_total']);
        $this->assertEquals(30000, $pago['deduction_total']);
        $this->assertEquals(820000, $pago['net_total']);
    }

    public function test_no_ve_lo_que_le_pagaron_a_su_companera(): void
    {
        // Dos manicuristas que trabajan a un metro: lo que gana la otra no
        // es información suya.
        $this->liquidacion($this->lucia);

        $this->assertSame([], $this->miDia()['payments']);
    }

    public function test_solo_las_ultimas_doce(): void
    {
        /*
         * Alcanza para "¿cuánto me pagaron en mayo?" sin volverlo un
         * historial que nadie baja hasta el final. Una liquidación existe
         * SOLO cuando ya se pagó -- `paid_at` no admite nulos -- así que no
         * hay forma de que acá aparezca una que todavía se está preparando.
         */
        // Un período por mes: hay un índice único (recurso, inicio de
        // período) que impide liquidar dos veces el mismo.
        foreach (range(1, 14) as $i) {
            $mes = CarbonImmutable::now('America/Bogota')->startOfMonth()->subMonths($i);

            $this->liquidacion($this->maria, [
                'period_start' => $mes->toDateString(),
                'period_end' => $mes->endOfMonth()->toDateString(),
                'paid_at' => $mes->addMonth()->toDateTimeString(),
            ]);
        }

        $this->assertCount(12, $this->miDia()['payments']);
    }

    public function test_sin_pagos_la_lista_viene_vacia_y_no_falla(): void
    {
        // Quien acaba de entrar al equipo todavía no tiene ninguno.
        $this->assertSame([], $this->miDia()['payments']);
    }
}
