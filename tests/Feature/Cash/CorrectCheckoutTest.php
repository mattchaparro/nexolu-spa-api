<?php

namespace Tests\Feature\Cash;

use App\Models\Appointment;
use App\Models\AppointmentItem;
use App\Models\Business;
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
 * Corregir lo que ya se cobró, desde el Resumen del día: el servicio, el
 * valor o el medio; deshacerlo o eliminarlo. Y lo que NO se puede hacer con
 * una cita cobrada sin pasar por aquí.
 */
class CorrectCheckoutTest extends TestCase
{
    use RefreshDatabase, SchedulingScenario;

    private Business $business;

    private User $admin;

    private Resource $marcela;

    private Resource $alejandra;

    private Service $semi;

    private Service $rubber;

    private PaymentMethod $efectivo;

    private PaymentMethod $bold;

    private string $fecha;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(
            CarbonImmutable::now('America/Bogota')->startOfDay()->previous(CarbonImmutable::WEDNESDAY)->setTime(8, 0),
        );
        $this->fecha = CarbonImmutable::now('America/Bogota')->toDateString();

        PermissionCatalog::sync();
        $this->business = $this->makeBusiness(['slot_granularity_min' => 60]);
        $this->admin = User::create([
            'business_id' => $this->business->id, 'name' => 'Admin', 'email' => 'admin@prueba.test',
            'password' => Hash::make('password123'), 'is_active' => true,
        ]);
        PermissionCatalog::applyRole($this->admin, PermissionCatalog::ROLE_ADMIN);

        $this->efectivo = PaymentMethod::create(['business_id' => $this->business->id, 'name' => 'Efectivo', 'counts_as_cash' => true]);
        $this->bold = PaymentMethod::create(['business_id' => $this->business->id, 'name' => 'Bold', 'counts_as_cash' => false]);

        $this->marcela = $this->makeResource($this->business, 'Marcela');
        $this->alejandra = $this->makeResource($this->business, 'Alejandra');
        $this->semi = $this->makeService($this->business, 60, [$this->marcela, $this->alejandra], name: 'Semi');
        $this->semi->update(['price' => 45000, 'commission_rate' => 0.40]);
        $this->rubber = $this->makeService($this->business, 60, [$this->marcela, $this->alejandra], name: 'Semi + Rubber');
        $this->rubber->update(['price' => 60000, 'commission_rate' => 0.40]);

        Sanctum::actingAs($this->admin);
    }

    private function cobrada(string $hora = '10:00'): Appointment
    {
        $id = $this->postJson('/api/v1/appointments', [
            'service_id' => $this->semi->id,
            'resource_id' => $this->marcela->id,
            'starts_at' => "{$this->fecha} {$hora}:00",
            'client_name' => 'María',
        ])->assertCreated()->json('id');

        $this->postJson("/api/v1/appointments/{$id}/checkout", ['payment_method_id' => $this->efectivo->id])->assertOk();

        return Appointment::withoutGlobalScope('business')->find($id);
    }

    private function linea(Appointment $cita): AppointmentItem
    {
        return AppointmentItem::where('appointment_id', $cita->id)->first();
    }

    public function test_el_resumen_lista_cada_servicio_cobrado(): void
    {
        $cita = $this->cobrada();

        $this->getJson("/api/v1/daily-summary?date={$this->fecha}")
            ->assertOk()
            ->assertJsonPath('lines.0.appointment_id', $cita->id)
            ->assertJsonPath('lines.0.service_name', 'Semi')
            ->assertJsonPath('lines.0.resource_name', 'Marcela')
            ->assertJsonPath('lines.0.payment_method', 'Efectivo')
            ->assertJsonPath('lines.0.charged', 45000)
            ->assertJsonPath('lines.0.commission', 18000)
            ->assertJsonPath('lines.0.settled', false);
    }

    public function test_corregir_cambia_servicio_valor_y_medio_y_se_queda_en_su_dia(): void
    {
        $cita = $this->cobrada();
        $cobradaEl = $cita->checked_out_at;
        $this->travel(2)->days();

        $this->putJson("/api/v1/appointments/{$cita->id}/checkout", [
            'payment_method_id' => $this->bold->id,
            'lines' => [['id' => $this->linea($cita)->id, 'service_id' => $this->rubber->id, 'charged' => 55000]],
        ])->assertOk();

        $cita->refresh();
        $linea = $this->linea($cita);

        $this->assertSame($this->rubber->id, $linea->service_id);
        $this->assertEqualsWithDelta(55000, (float) $linea->charged_amount, 0.01);
        $this->assertEqualsWithDelta(22000, (float) $linea->commission_amount, 0.01);
        $this->assertEqualsWithDelta(55000, (float) $cita->total, 0.01);
        $this->assertSame($this->bold->id, $cita->payment_method_id);
        // Sigue en el día en que se cobró, no en el de la corrección.
        $this->assertTrue($cobradaEl->equalTo($cita->checked_out_at));
        $this->assertStringContainsString('Cobro corregido', (string) $cita->notes);
    }

    public function test_eliminar_una_cobrada_la_saca_de_la_caja(): void
    {
        $cita = $this->cobrada();

        $this->deleteJson("/api/v1/appointments/{$cita->id}", ['undo_checkout' => true, 'reason' => 'Subida de más'])
            ->assertNoContent();

        $this->assertSoftDeleted('appointments', ['id' => $cita->id]);
        $this->getJson("/api/v1/daily-summary?date={$this->fecha}")
            ->assertOk()
            ->assertJsonPath('totals.total_charged', 0)
            ->assertJsonCount(0, 'lines');
    }

    public function test_sin_decirlo_una_cobrada_no_se_elimina(): void
    {
        $cita = $this->cobrada();

        $this->deleteJson("/api/v1/appointments/{$cita->id}")->assertStatus(422);
    }

    public function test_lo_que_ya_se_pago_en_nomina_no_se_corrige_ni_se_deshace(): void
    {
        $cita = $this->cobrada();

        $this->postJson("/api/v1/payroll/resources/{$this->marcela->id}/settle", [
            'until' => $this->fecha,
            'payment_method_id' => $this->efectivo->id,
        ])->assertCreated();

        $this->deleteJson("/api/v1/appointments/{$cita->id}/checkout")
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'La comisión de este servicio ya se le pagó a Marcela (nómina del '.now('America/Bogota')->format('d/m').'). Para corregirlo, ajusta esa nómina.']);

        $this->putJson("/api/v1/appointments/{$cita->id}/checkout", [
            'payment_method_id' => $this->efectivo->id,
            'lines' => [['id' => $this->linea($cita)->id, 'charged' => 1000]],
        ])->assertStatus(422);

        $this->getJson("/api/v1/daily-summary?date={$this->fecha}")->assertJsonPath('lines.0.settled', true);
    }

    public function test_una_cobrada_no_se_pasa_a_otra_persona_arrastrandola(): void
    {
        $cita = $this->cobrada();

        $this->patchJson("/api/v1/appointments/{$cita->id}/reschedule", [
            'starts_at' => "{$this->fecha} 10:00:00",
            'resource_id' => $this->alejandra->id,
        ])->assertStatus(422);

        $this->assertSame($this->marcela->id, $this->linea($cita)->resource_id);
    }

    public function test_pasarla_a_otra_persona_antes_de_cobrar_le_pone_su_porcentaje(): void
    {
        // Alejandra va al 50 % en todo; Marcela, con el 40 % del servicio.
        $this->alejandra->update(['commission_rate' => 0.50]);

        $id = $this->postJson('/api/v1/appointments', [
            'service_id' => $this->semi->id,
            'resource_id' => $this->marcela->id,
            'starts_at' => "{$this->fecha} 11:00:00",
            'client_name' => 'Ana',
        ])->assertCreated()->json('id');

        $this->patchJson("/api/v1/appointments/{$id}/reschedule", [
            'starts_at' => "{$this->fecha} 11:00:00",
            'resource_id' => $this->alejandra->id,
        ])->assertOk();

        $linea = AppointmentItem::where('appointment_id', $id)->first();
        $this->assertSame($this->alejandra->id, $linea->resource_id);
        $this->assertEqualsWithDelta(0.50, (float) $linea->commission_rate, 0.0001);
    }

    public function test_una_cobrada_no_se_cancela(): void
    {
        $cita = $this->cobrada();

        $this->postJson("/api/v1/appointments/{$cita->id}/stage", ['status' => Appointment::STATUS_CONFIRMED])->assertOk();
        $this->postJson("/api/v1/appointments/{$cita->id}/cancel")->assertStatus(422);
    }
}
