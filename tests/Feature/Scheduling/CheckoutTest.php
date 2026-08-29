<?php

namespace Tests\Feature\Scheduling;

use App\Models\Appointment;
use App\Models\Business;
use App\Models\PaymentMethod;
use App\Models\Resource;
use App\Models\Service;
use App\Models\User;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Cierre y cobro de una cita atendida: el momento en que la agenda se
 * convierte en dinero y en comision.
 */
class CheckoutTest extends TestCase
{
    use RefreshDatabase, SchedulingScenario;

    private Business $business;

    private User $admin;

    private PaymentMethod $efectivo;

    private Resource $maria;

    private Service $service;

    protected function setUp(): void
    {
        parent::setUp();

        PermissionCatalog::sync();

        $this->business = $this->makeBusiness(['slot_granularity_min' => 60]);
        $this->admin = User::create([
            'business_id' => $this->business->id,
            'name' => 'Admin',
            'email' => 'admin@prueba.test',
            'password' => Hash::make('password123'),
            'is_active' => true,
        ]);
        PermissionCatalog::applyRole($this->admin, PermissionCatalog::ROLE_ADMIN);

        $this->efectivo = PaymentMethod::create([
            'business_id' => $this->business->id,
            'name' => 'Efectivo',
            'counts_as_cash' => true,
        ]);

        $this->maria = $this->makeResource($this->business, 'Maria', '09:00:00', '18:00:00');
        $this->service = $this->makeService($this->business, 60, [$this->maria]);
        $this->service->update(['price' => 50000, 'commission_rate' => 0.30]);

        Sanctum::actingAs($this->admin);
    }

    private function agendar(): int
    {
        $fecha = $this->wednesday()->toDateString();
        $slot = $this->getJson("/api/v1/availability?service_id={$this->service->id}&date={$fecha}")->json('slots.0');

        return $this->postJson('/api/v1/appointments', [
            'service_id' => $this->service->id,
            'resource_id' => $slot['resource_id'],
            'starts_at' => $slot['starts_at'],
            'client_name' => 'Laura Gomez',
        ])->json('id');
    }

    public function test_cobrar_congela_total_y_comision(): void
    {
        $id = $this->agendar();

        $this->postJson("/api/v1/appointments/{$id}/checkout", [
            'payment_method_id' => $this->efectivo->id,
        ])
            ->assertOk()
            ->assertJsonPath('status', Appointment::STATUS_COMPLETED)
            ->assertJsonPath('is_paid', true)
            ->assertJsonPath('total', 50000)
            ->assertJsonPath('commission_total', 15000)   // 30% de 50.000
            ->assertJsonPath('items.0.commission_amount', 15000);
    }

    public function test_la_comision_se_calcula_sobre_lo_cobrado_no_sobre_el_precio_de_lista(): void
    {
        $id = $this->agendar();

        $this->postJson("/api/v1/appointments/{$id}/checkout", [
            'payment_method_id' => $this->efectivo->id,
            'discount_amount' => 10000,
            'discount_reason' => 'Cliente frecuente',
        ])
            ->assertOk()
            ->assertJsonPath('subtotal', 50000)
            ->assertJsonPath('total', 40000)
            // 30% de 40.000, no de 50.000: si la comision se calculara sobre
            // el precio de lista, el negocio pagaria comision por plata que
            // nunca entro.
            ->assertJsonPath('commission_total', 12000);
    }

    public function test_usa_el_porcentaje_propio_de_la_profesional_cuando_lo_tiene(): void
    {
        // Maria cobra 40% en este servicio, no el 30% general. Es el caso de
        // un servicio que solo ella presta.
        $this->service->resources()->updateExistingPivot($this->maria->id, [
            'commission_rate_override' => 0.40,
        ]);

        $id = $this->agendar();

        $this->postJson("/api/v1/appointments/{$id}/checkout", [
            'payment_method_id' => $this->efectivo->id,
        ])
            ->assertOk()
            ->assertJsonPath('commission_total', 20000);   // 40%, no 30%
    }

    public function test_el_precio_se_puede_ajustar_a_mano_al_cobrar(): void
    {
        $id = $this->agendar();
        $itemId = $this->getJson('/api/v1/appointments?date='.$this->wednesday()->toDateString())
            ->json('0.items.0.id');

        $this->postJson("/api/v1/appointments/{$id}/checkout", [
            'payment_method_id' => $this->efectivo->id,
            'item_prices' => [$itemId => 65000],
        ])
            ->assertOk()
            ->assertJsonPath('total', 65000)
            ->assertJsonPath('items.0.final_price', 65000)
            ->assertJsonPath('commission_total', 19500);
    }

    public function test_no_se_puede_cobrar_dos_veces(): void
    {
        $id = $this->agendar();
        $payload = ['payment_method_id' => $this->efectivo->id];

        $this->postJson("/api/v1/appointments/{$id}/checkout", $payload)->assertOk();
        $this->postJson("/api/v1/appointments/{$id}/checkout", $payload)->assertStatus(422);
    }

    public function test_no_se_puede_cobrar_una_cita_cancelada(): void
    {
        $id = $this->agendar();
        $this->postJson("/api/v1/appointments/{$id}/cancel")->assertOk();

        $this->postJson("/api/v1/appointments/{$id}/checkout", [
            'payment_method_id' => $this->efectivo->id,
        ])->assertStatus(422);
    }

    public function test_el_descuento_no_puede_superar_el_total(): void
    {
        $id = $this->agendar();

        $this->postJson("/api/v1/appointments/{$id}/checkout", [
            'payment_method_id' => $this->efectivo->id,
            'discount_amount' => 90000,
        ])->assertStatus(422);
    }

    public function test_deshacer_el_cobro_no_libera_el_horario(): void
    {
        $id = $this->agendar();
        $fecha = $this->wednesday()->toDateString();

        $this->postJson("/api/v1/appointments/{$id}/checkout", ['payment_method_id' => $this->efectivo->id])->assertOk();

        $huecosCobrada = count($this->getJson("/api/v1/availability?service_id={$this->service->id}&date={$fecha}")->json('slots'));

        $this->deleteJson("/api/v1/appointments/{$id}/checkout")
            ->assertOk()
            ->assertJsonPath('is_paid', false)
            ->assertJsonPath('total', null);

        // El servicio se presto igual: deshacer el cobro corrige la plata,
        // no borra la cita ni devuelve el horario a la disponibilidad.
        $huecosRevertida = count($this->getJson("/api/v1/availability?service_id={$this->service->id}&date={$fecha}")->json('slots'));
        $this->assertSame($huecosCobrada, $huecosRevertida);
    }

    public function test_el_descuento_se_reparte_entre_lineas_sin_perder_pesos(): void
    {
        // Dos servicios en la misma cita, con un descuento que no divide
        // exacto: la suma de las partes tiene que dar el total redondo.
        $otro = $this->makeService($this->business, 60, [$this->maria], name: 'Pedicure');
        $otro->update(['price' => 30000, 'commission_rate' => 0.30]);

        $fecha = $this->wednesday()->toDateString();
        $slot = $this->getJson("/api/v1/availability?service_id={$this->service->id}&date={$fecha}")->json('slots.0');

        $appointment = app(\App\Services\Scheduling\BookingService::class)->book(
            $this->business,
            [
                ['service_id' => $this->service->id, 'resource_id' => $this->maria->id, 'starts_at' => $slot['starts_at']],
                ['service_id' => $otro->id, 'resource_id' => $this->maria->id, 'starts_at' => \Carbon\CarbonImmutable::parse($slot['starts_at'])->addHour()],
            ],
            null,
            'Laura',
        );

        $response = $this->postJson("/api/v1/appointments/{$appointment->id}/checkout", [
            'payment_method_id' => $this->efectivo->id,
            'discount_amount' => 10000,
        ])->assertOk();

        // Comparaciones por valor y no por tipo: JSON serializa 80000.0 como
        // entero, y aca lo que importa es el monto.
        $this->assertEqualsWithDelta(80000, $response->json('subtotal'), 0.01);
        $this->assertEqualsWithDelta(70000, $response->json('total'), 0.01);

        // 30% de los 70.000 que realmente entraron.
        $this->assertEqualsWithDelta(21000, $response->json('commission_total'), 0.01);

        $suma = array_sum(array_column($response->json('items'), 'commission_amount'));
        $this->assertSame(21000.0, round($suma, 2), 'Las comisiones por linea deben sumar exactamente el total.');
    }
}
