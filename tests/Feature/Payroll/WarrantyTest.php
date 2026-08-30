<?php

namespace Tests\Feature\Payroll;

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
 * Garantías: rehacer un trabajo que falló, sin cobrarle al cliente.
 *
 * Ocupa agenda como cualquier servicio -- la silla y el tiempo se gastan
 * igual -- pero vale 0 y no paga comisión.
 *
 * Lo que se defiende acá es la atribución: la garantía se le anota a quien
 * hizo el ORIGINAL, no a quien la rehace. Todo el sentido de llevar la cuenta
 * es saber quién está recibiendo garantías.
 */
class WarrantyTest extends TestCase
{
    use RefreshDatabase, SchedulingScenario;

    private Business $business;

    private User $admin;

    private Resource $maria;

    private Resource $ana;

    private Service $service;

    private PaymentMethod $efectivo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(
            CarbonImmutable::now('America/Bogota')->startOfDay()
                ->previous(CarbonImmutable::WEDNESDAY)->setTime(8, 0),
        );

        PermissionCatalog::sync();

        $this->business = $this->makeBusiness(['slot_granularity_min' => 60, 'min_booking_notice_min' => 0]);

        $this->admin = User::create([
            'business_id' => $this->business->id, 'name' => 'Dueña',
            'email' => 'admin@prueba.test', 'password' => Hash::make('password123'), 'is_active' => true,
        ]);
        PermissionCatalog::applyRole($this->admin, PermissionCatalog::ROLE_ADMIN);

        $this->efectivo = PaymentMethod::create([
            'business_id' => $this->business->id, 'name' => 'Efectivo', 'counts_as_cash' => true,
        ]);

        $this->maria = $this->makeResource($this->business, 'Maria', '09:00:00', '18:00:00');
        $this->ana = $this->makeResource($this->business, 'Ana', '09:00:00', '18:00:00');

        $this->service = $this->makeService($this->business, 60, [$this->maria, $this->ana]);
        $this->service->update(['name' => 'Manicure', 'price' => 50000, 'commission_rate' => 0.30]);

        Sanctum::actingAs($this->admin->fresh());
    }

    private function hoy(): CarbonImmutable
    {
        return CarbonImmutable::now('America/Bogota')->startOfDay();
    }

    private function agendar(array $extra, string $hora, ?Resource $quien = null): int
    {
        return $this->postJson('/api/v1/appointments', array_merge([
            'service_id' => $this->service->id,
            'resource_id' => ($quien ?? $this->maria)->id,
            'starts_at' => $this->hoy()->format('Y-m-d')." {$hora}:00",
            'client_name' => 'Carolina',
            'client_phone' => '3001234567',
        ], $extra))->assertCreated()->json('id');
    }

    public function test_una_garantia_no_se_le_cobra_al_cliente(): void
    {
        $id = $this->agendar([
            'is_warranty' => true,
            'warranty_for_resource_id' => $this->maria->id,
            'warranty_note' => 'Se cayó una uña a los tres días.',
        ], '10:00');

        $item = AppointmentItem::withoutGlobalScope('business')->where('appointment_id', $id)->first();

        // Se fuerza al agendar y no al cobrar: si dependiera de que alguien
        // acuerde poner el descuento, tarde o temprano se cobra una garantía,
        // y eso se descubre con la clienta delante.
        $this->assertEqualsWithDelta(0, $item->price, 0.01);
        $this->assertTrue($item->is_warranty);
    }

    public function test_una_garantia_no_paga_comision(): void
    {
        $id = $this->agendar([
            'is_warranty' => true,
            'warranty_for_resource_id' => $this->maria->id,
        ], '10:00');

        $cobrada = $this->postJson("/api/v1/appointments/{$id}/checkout", [
            'payment_method_id' => $this->efectivo->id,
        ])->assertOk();

        $this->assertEqualsWithDelta(0, $cobrada->json('total'), 0.01);
        $this->assertEqualsWithDelta(0, $cobrada->json('commission_total'), 0.01);
    }

    public function test_una_garantia_ocupa_la_agenda_igual(): void
    {
        // La silla y el tiempo se gastan aunque no se cobre: si no ocupara,
        // el sistema vendería esa hora dos veces.
        $this->agendar([
            'is_warranty' => true,
            'warranty_for_resource_id' => $this->maria->id,
        ], '10:00');

        $this->postJson('/api/v1/appointments', [
            'service_id' => $this->service->id,
            'resource_id' => $this->maria->id,
            'starts_at' => $this->hoy()->format('Y-m-d').' 10:00:00',
            'client_name' => 'Otra',
        ])->assertStatus(409);
    }

    public function test_la_garantia_se_le_anota_a_quien_hizo_el_original(): void
    {
        /*
         * Ana rehace un trabajo de María. La garantía es de MARÍA: el sentido
         * de llevar la cuenta es saber quién está recibiendo garantías, no
         * quién tuvo el turno libre para arreglarlas.
         */
        $this->agendar([
            'is_warranty' => true,
            'warranty_for_resource_id' => $this->maria->id,
            'warranty_note' => 'Trabajo de María.',
        ], '10:00', $this->ana);

        $deMaria = $this->getJson("/api/v1/payroll/resources/{$this->maria->id}/preview")->assertOk();
        $deAna = $this->getJson("/api/v1/payroll/resources/{$this->ana->id}/preview")->assertOk();

        $this->assertSame(1, $deMaria->json('warranties.count'));
        $this->assertSame(0, $deAna->json('warranties.count'));

        // Y se dice quién la rehizo, que es otra conversación.
        $this->assertSame('Ana', $deMaria->json('warranties.items.0.done_by'));
        $this->assertSame('Trabajo de María.', $deMaria->json('warranties.items.0.note'));
    }

    public function test_la_liquidacion_muestra_las_garantias_pero_no_las_descuenta_sola(): void
    {
        /*
         * Una multa automática por un número sin contexto convierte un esmalte
         * corrido en un descuento de nómina, y eso se pelea. Se muestran para
         * que alguien decida; si hay multa, va como ajuste y queda firmada.
         */
        foreach (['10:00', '11:00', '12:00'] as $hora) {
            $this->agendar([
                'is_warranty' => true,
                'warranty_for_resource_id' => $this->maria->id,
            ], $hora);
        }

        $preview = $this->getJson("/api/v1/payroll/resources/{$this->maria->id}/preview")->assertOk();

        $this->assertSame(3, $preview->json('warranties.count'));
        // Nada se descontó solo.
        $this->assertSame([], $preview->json('adjustments'));
    }

    public function test_una_garantia_exige_decir_a_quien_se_le_anota(): void
    {
        // Sin atribución, la garantía no sirve para nada: es un servicio
        // gratis del que nadie responde.
        $this->postJson('/api/v1/appointments', [
            'service_id' => $this->service->id,
            'resource_id' => $this->maria->id,
            'starts_at' => $this->hoy()->format('Y-m-d').' 10:00:00',
            'client_name' => 'Carolina',
            'is_warranty' => true,
        ])->assertStatus(422);
    }

    public function test_un_servicio_normal_sigue_cobrando_y_pagando(): void
    {
        $id = $this->agendar([], '10:00');

        $cobrada = $this->postJson("/api/v1/appointments/{$id}/checkout", [
            'payment_method_id' => $this->efectivo->id,
        ])->assertOk();

        $this->assertEqualsWithDelta(50000, $cobrada->json('total'), 0.01);
        $this->assertEqualsWithDelta(15000, $cobrada->json('commission_total'), 0.01);

        $preview = $this->getJson("/api/v1/payroll/resources/{$this->maria->id}/preview")->assertOk();
        $this->assertSame(0, $preview->json('warranties.count'));
    }
}
