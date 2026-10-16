<?php

namespace Tests\Feature\Payroll;

use App\Models\Appointment;
use App\Models\Business;
use App\Models\PaymentMethod;
use App\Models\PayrollAdjustment;
use App\Models\Resource;
use App\Models\Service;
use App\Models\User;
use App\Services\Payroll\PayrollService;
use App\Services\Scheduling\BookingService;
use App\Support\PermissionCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Scheduling\SchedulingScenario;
use Tests\TestCase;

/**
 * La propina es de quien atendió, entera: no paga comisión, llega en la
 * liquidación y la ve en el detalle de lo que lleva y de lo que le pagaron.
 */
class PropinasTest extends TestCase
{
    use RefreshDatabase, SchedulingScenario;

    private Business $business;

    private Resource $maria;

    private Resource $lucia;

    private Service $semi;

    private User $empleada;

    private User $admin;

    private PaymentMethod $efectivo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-09-16 18:00', 'America/Bogota'));
        PermissionCatalog::sync();

        $this->business = $this->makeBusiness(['min_booking_notice_min' => 0]);
        $this->maria = $this->makeResource($this->business, 'Maria', '08:00:00', '20:00:00');
        $this->lucia = $this->makeResource($this->business, 'Lucia', '08:00:00', '20:00:00');
        $this->semi = $this->makeService($this->business, 60, [$this->maria, $this->lucia], name: 'Semipermanente');

        $this->empleada = $this->usuario('maria@prueba.test', PermissionCatalog::ROLE_STAFF);
        $this->maria->update(['user_id' => $this->empleada->id]);
        $this->admin = $this->usuario('ana@prueba.test', PermissionCatalog::ROLE_ADMIN);

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

    private function cita(Resource $con, string $hora = '10:00'): Appointment
    {
        return app(BookingService::class)->book(
            $this->business,
            [['service_id' => $this->semi->id, 'resource_id' => $con->id,
                'starts_at' => CarbonImmutable::now('America/Bogota')->setTimeFromTimeString($hora)]],
            null, 'Carolina Pérez', null, Appointment::SOURCE_ADMIN, null, false,
        );
    }

    private function cobrar(Appointment $cita, array $extra = [])
    {
        return $this->postJson("/api/v1/appointments/{$cita->id}/checkout", [
            'payment_method_id' => $this->efectivo->id,
            ...$extra,
        ]);
    }

    private function miDia(): array
    {
        Sanctum::actingAs($this->empleada);

        return $this->getJson('/api/v1/my-work')->assertOk()->json();
    }

    public function test_la_propina_al_cobrar_es_de_quien_atendio_y_no_paga_comision(): void
    {
        Sanctum::actingAs($this->admin);
        $cita = $this->cita($this->maria);

        $this->cobrar($cita, ['tips' => [$this->maria->id => 8000]])->assertOk();

        $propina = PayrollAdjustment::withoutGlobalScopes()->sole();
        $this->assertSame('propina', $propina->category);
        $this->assertSame(PayrollAdjustment::KIND_BONUS, $propina->kind);
        $this->assertSame($this->maria->id, $propina->resource_id);
        $this->assertEquals(8000, $propina->amount);
        $this->assertStringContainsString('Semipermanente', $propina->description);

        $to = $this->miDia()['to_date'];
        $comisionDelServicio = (float) $cita->fresh()->items->first()->commission_amount;

        // La comisión sale del servicio, sin la propina...
        $this->assertEqualsWithDelta($comisionDelServicio, $to['commission'], 0.01);
        // ...y la propina va entera, aparte.
        $this->assertEquals(8000, $to['tips']);
        $this->assertEqualsWithDelta($comisionDelServicio + 8000, $to['net'], 0.01);
    }

    public function test_cada_persona_de_la_cita_recibe_la_suya(): void
    {
        Sanctum::actingAs($this->admin);
        $cita = app(BookingService::class)->book(
            $this->business,
            [
                ['service_id' => $this->semi->id, 'resource_id' => $this->maria->id, 'starts_at' => CarbonImmutable::now('America/Bogota')->setTime(9, 0)],
                ['service_id' => $this->semi->id, 'resource_id' => $this->lucia->id, 'starts_at' => CarbonImmutable::now('America/Bogota')->setTime(9, 0)],
            ],
            null, 'Carolina', null, Appointment::SOURCE_ADMIN, null, false,
        );

        $this->cobrar($cita, ['tips' => [$this->maria->id => 5000, $this->lucia->id => 3000, 99999 => 7000]])->assertOk();

        $this->assertEquals(5000, PayrollAdjustment::withoutGlobalScopes()->where('resource_id', $this->maria->id)->sum('amount'));
        $this->assertEquals(3000, PayrollAdjustment::withoutGlobalScopes()->where('resource_id', $this->lucia->id)->sum('amount'));
        // El id de alguien que no es de esta cita no anota nada.
        $this->assertSame(2, PayrollAdjustment::withoutGlobalScopes()->count());
    }

    public function test_cobrar_sin_propina_o_con_cero_no_anota_nada(): void
    {
        Sanctum::actingAs($this->admin);

        $this->cobrar($this->cita($this->maria), ['tips' => [$this->maria->id => 0]])->assertOk();

        $this->assertSame(0, PayrollAdjustment::withoutGlobalScopes()->count());
    }

    public function test_ella_anota_su_propina_y_aparece_en_lo_que_lleva(): void
    {
        Sanctum::actingAs($this->empleada);
        $cita = $this->cita($this->maria);

        $this->postJson('/api/v1/my-work/tips', ['amount' => 10000, 'appointment_id' => $cita->id, 'note' => 'Bold'])
            ->assertCreated()
            ->assertJsonPath('amount', 10000);

        $dia = $this->miDia();
        $this->assertEquals(10000, $dia['to_date']['tips']);
        $this->assertTrue($dia['to_date']['adjustments'][0]['is_tip']);
        $this->assertStringContainsString('Bold', $dia['to_date']['adjustments'][0]['description']);
    }

    public function test_no_puede_anotar_propina_de_una_cita_ajena(): void
    {
        Sanctum::actingAs($this->empleada);
        $deLucia = $this->cita($this->lucia);

        $this->postJson('/api/v1/my-work/tips', ['amount' => 5000, 'appointment_id' => $deLucia->id])
            ->assertStatus(422);

        $this->assertSame(0, PayrollAdjustment::withoutGlobalScopes()->count());
    }

    public function test_el_valor_tiene_que_ser_positivo(): void
    {
        Sanctum::actingAs($this->empleada);

        $this->postJson('/api/v1/my-work/tips', ['amount' => 0])->assertStatus(422);
        $this->postJson('/api/v1/my-work/tips', ['amount' => -500])->assertStatus(422);
    }

    public function test_puede_quitar_la_suya_mientras_no_este_liquidada(): void
    {
        Sanctum::actingAs($this->empleada);
        $id = $this->postJson('/api/v1/my-work/tips', ['amount' => 4000])->assertCreated()->json('id');

        $this->deleteJson("/api/v1/my-work/tips/{$id}")->assertNoContent();
        $this->assertSame(0, PayrollAdjustment::withoutGlobalScopes()->count());
    }

    public function test_no_quita_la_de_otra_persona_ni_una_que_no_es_propina(): void
    {
        $ajena = PayrollAdjustment::withoutGlobalScopes()->create([
            'business_id' => $this->business->id, 'resource_id' => $this->lucia->id,
            'date' => '2026-09-16', 'kind' => 'bonus', 'category' => 'propina', 'amount' => 3000,
        ]);
        $vale = PayrollAdjustment::withoutGlobalScopes()->create([
            'business_id' => $this->business->id, 'resource_id' => $this->maria->id,
            'date' => '2026-09-16', 'kind' => 'deduction', 'category' => 'anticipo', 'amount' => 3000,
        ]);

        Sanctum::actingAs($this->empleada);
        $this->deleteJson("/api/v1/my-work/tips/{$ajena->id}")->assertNotFound();
        $this->deleteJson("/api/v1/my-work/tips/{$vale->id}")->assertNotFound();
    }

    public function test_liquidada_ya_no_se_puede_quitar_y_queda_en_el_pago(): void
    {
        Sanctum::actingAs($this->admin);
        $this->cobrar($this->cita($this->maria, '09:00'), ['tips' => [$this->maria->id => 8000]])->assertOk();

        $pago = app(PayrollService::class)->settle(
            $this->business, $this->maria->fresh(), CarbonImmutable::now('America/Bogota'), $this->admin,
        );

        $this->assertEquals(8000, $pago->tip_total);
        // La propina va DENTRO del neto: comisión + propina.
        $this->assertEqualsWithDelta((float) $pago->commission_total + 8000, (float) $pago->net_total, 0.01);

        $tip = PayrollAdjustment::withoutGlobalScopes()->sole();
        Sanctum::actingAs($this->empleada);
        $this->deleteJson("/api/v1/my-work/tips/{$tip->id}")->assertStatus(422);

        $pagos = $this->miDia()['payments'];
        $this->assertEquals(8000, $pagos[0]['tip_total']);
    }

    public function test_la_liquidacion_del_admin_trae_las_propinas_aparte(): void
    {
        Sanctum::actingAs($this->admin);
        $this->cobrar($this->cita($this->maria), ['tips' => [$this->maria->id => 8000]])->assertOk();
        PayrollAdjustment::withoutGlobalScopes()->create([
            'business_id' => $this->business->id, 'resource_id' => $this->maria->id,
            'date' => '2026-09-16', 'kind' => 'bonus', 'category' => 'bono', 'amount' => 20000,
        ]);

        $preview = app(PayrollService::class)->preview($this->business, $this->maria->fresh(), CarbonImmutable::now('America/Bogota'));

        // Los premios incluyen la propina; `tip_total` la separa.
        $this->assertEquals(28000, $preview['bonus_total']);
        $this->assertEquals(8000, $preview['tip_total']);
    }
}
