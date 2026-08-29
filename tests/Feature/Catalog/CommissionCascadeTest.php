<?php

namespace Tests\Feature\Catalog;

use App\Models\Business;
use App\Models\PaymentMethod;
use App\Models\Resource;
use App\Models\ResourceSchedule;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\User;
use App\Support\Money\CommissionResolver;
use App\Support\PermissionCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Scheduling\SchedulingScenario;
use Tests\TestCase;

/**
 * La comisión, de punta a punta.
 *
 * No basta con que el resolver acierte: lo que importa es que el número que
 * calcula sea el que se congela al cobrar y el que después se liquida. Entre
 * esos tres puntos es donde alguien termina cobrando de menos.
 */
class CommissionCascadeTest extends TestCase
{
    use RefreshDatabase, SchedulingScenario;

    private Business $business;

    private User $admin;

    private Resource $ana;

    private ServiceCategory $manicure;

    private Service $service;

    private PaymentMethod $efectivo;

    protected function setUp(): void
    {
        parent::setUp();

        PermissionCatalog::sync();

        $this->business = $this->makeBusiness();

        $this->admin = User::create([
            'business_id' => $this->business->id, 'name' => 'Dueña',
            'email' => 'admin@prueba.test', 'password' => Hash::make('password123'), 'is_active' => true,
        ]);
        PermissionCatalog::applyRole($this->admin, PermissionCatalog::ROLE_ADMIN);

        $this->ana = Resource::create([
            'business_id' => $this->business->id, 'type' => Resource::TYPE_STAFF,
            'name' => 'Ana', 'is_active' => true,
        ]);

        foreach (range(1, 7) as $weekday) {
            ResourceSchedule::create([
                'business_id' => $this->business->id, 'resource_id' => $this->ana->id,
                'weekday' => $weekday, 'start_time' => '00:00:00', 'end_time' => '23:59:00',
                'effective_from' => '2020-01-01',
            ]);
        }

        $this->manicure = ServiceCategory::create([
            'business_id' => $this->business->id, 'name' => 'Manicure', 'sort_order' => 0,
        ]);

        $this->service = $this->makeService($this->business, 60, [$this->ana]);
        $this->service->update([
            'name' => 'Manicure clásico',
            'price' => 100000,
            'commission_rate' => 0.30,
            'service_category_id' => $this->manicure->id,
        ]);

        $this->efectivo = PaymentMethod::create([
            'business_id' => $this->business->id, 'name' => 'Efectivo',
            'counts_as_cash' => true, 'is_active' => true, 'sort_order' => 1,
        ]);

        Sanctum::actingAs($this->admin->fresh());
    }

    /** Presta el servicio y lo cobra. Devuelve la cita ya cobrada. */
    private function cobrar(): array
    {
        return $this->postJson('/api/v1/walk-in', [
            'service_id' => $this->service->id,
            'resource_id' => $this->ana->id,
            'started_at' => CarbonImmutable::now($this->business->businessTimezone())
                ->subHour()->format('Y-m-d H:i:s'),
            'client_name' => 'Alguien',
            'payment_method_id' => $this->efectivo->id,
        ])->assertCreated()->json();
    }

    /*
    |--------------------------------------------------------------------------
    | El caso que se pidió: una persona al 50%
    |--------------------------------------------------------------------------
    */

    public function test_una_persona_al_50_cobra_el_50_aunque_el_servicio_diga_30(): void
    {
        $this->ana->update(['commission_rate' => 0.50]);

        $cita = $this->cobrar();

        // Sobre 100.000, el 50% de Ana y no el 30% del servicio.
        $this->assertEqualsWithDelta(50000, $cita['commission_total'], 0.01);
        $this->assertEqualsWithDelta(0.50, $cita['items'][0]['commission_rate'], 0.0001);
    }

    public function test_el_50_de_la_persona_aplica_a_cualquier_servicio_nuevo(): void
    {
        $this->ana->update(['commission_rate' => 0.50]);

        /*
         * El motivo de que el porcentaje viva en la persona: un servicio nuevo
         * que entre al catálogo mañana no puede cambiarle en silencio lo que
         * gana. Antes había que ponerle un acuerdo puntual en CADA servicio y
         * acordarse de repetirlo en cada alta -- que es justo cuando nadie se
         * acuerda.
         */
        $nuevo = $this->makeService($this->business, 30, [$this->ana]);
        $nuevo->update(['name' => 'Retoque', 'price' => 40000, 'commission_rate' => 0.20]);

        $this->service = $nuevo;
        $cita = $this->cobrar();

        $this->assertEqualsWithDelta(20000, $cita['commission_total'], 0.01);
    }

    public function test_un_acuerdo_puntual_gana_sobre_el_50_general(): void
    {
        $this->ana->update(['commission_rate' => 0.50]);
        // Ana es la única que hace este servicio y se pactó 60%.
        $this->service->resources()->updateExistingPivot($this->ana->id, [
            'commission_rate_override' => 0.60,
        ]);

        $cita = $this->cobrar();

        $this->assertEqualsWithDelta(60000, $cita['commission_total'], 0.01);
    }

    /*
    |--------------------------------------------------------------------------
    | La categoría como último recurso
    |--------------------------------------------------------------------------
    */

    public function test_la_categoria_cubre_los_servicios_sin_porcentaje_propio(): void
    {
        $this->manicure->update(['commission_rate' => 0.35]);
        $this->service->update(['commission_rate' => null]);

        $cita = $this->cobrar();

        $this->assertEqualsWithDelta(35000, $cita['commission_total'], 0.01);
    }

    public function test_cambiar_la_categoria_mueve_todos_sus_servicios_a_la_vez(): void
    {
        // Es el caso que se pidió: 20 servicios de manicure, un solo cambio.
        $this->service->update(['commission_rate' => null]);

        $otros = collect(range(1, 3))->map(function (int $i) {
            $s = $this->makeService($this->business, 30, [$this->ana]);
            $s->update([
                'name' => "Manicure {$i}",
                'commission_rate' => null,
                'service_category_id' => $this->manicure->id,
            ]);

            return $s;
        });

        $this->manicure->update(['commission_rate' => 0.45]);

        foreach ($otros->push($this->service) as $servicio) {
            $this->assertEqualsWithDelta(
                0.45,
                $servicio->fresh()->commissionRateFor($this->ana),
                0.0001,
                "{$servicio->name} no tomó el porcentaje de su categoría",
            );
        }
    }

    public function test_un_servicio_sin_categoria_ni_porcentaje_no_paga_comision(): void
    {
        $this->service->update(['commission_rate' => null, 'service_category_id' => null]);

        $cita = $this->cobrar();

        $this->assertEqualsWithDelta(0, $cita['commission_total'], 0.01);
    }

    /*
    |--------------------------------------------------------------------------
    | Lo que se congela y lo que se liquida
    |--------------------------------------------------------------------------
    */

    public function test_el_porcentaje_se_congela_al_cobrar(): void
    {
        $this->ana->update(['commission_rate' => 0.50]);

        $cita = $this->cobrar();

        // Le bajan el porcentaje después de cobrado.
        $this->ana->update(['commission_rate' => 0.20]);

        $this->assertDatabaseHas('appointment_items', [
            'id' => $cita['items'][0]['id'],
            'commission_amount' => 50000.00,
        ]);
    }

    public function test_la_nomina_liquida_exactamente_lo_congelado(): void
    {
        $this->ana->update(['commission_rate' => 0.50]);
        $this->cobrar();

        $preview = $this->getJson("/api/v1/payroll/resources/{$this->ana->id}/preview")
            ->assertOk();

        // El mismo número que vio quien cobró. Si acá saliera otro, el
        // descuadre aparecería el día de pago y nadie sabría cuál era el bueno.
        $this->assertEqualsWithDelta(50000, $preview->json('commission_total'), 0.01);
        $this->assertEqualsWithDelta(0.50, $preview->json('items.0.commission_rate'), 0.0001);
    }

    public function test_el_descuento_reduce_la_comision(): void
    {
        $this->ana->update(['commission_rate' => 0.50]);

        // 100.000 con 20.000 de descuento: la comisión va sobre lo COBRADO.
        $cita = $this->postJson('/api/v1/walk-in', [
            'service_id' => $this->service->id,
            'resource_id' => $this->ana->id,
            'client_name' => 'Alguien',
            'payment_method_id' => $this->efectivo->id,
            'discount_amount' => 20000,
        ])->assertCreated()->json();

        $this->assertEqualsWithDelta(40000, $cita['commission_total'], 0.01);
    }

    /*
    |--------------------------------------------------------------------------
    | Poder explicar el número
    |--------------------------------------------------------------------------
    */

    public function test_el_servicio_dice_de_donde_sale_el_porcentaje(): void
    {
        $this->manicure->update(['commission_rate' => 0.35]);

        // Sin nada en la persona: manda el servicio.
        $this->assertSame(
            CommissionResolver::SOURCE_SERVICE,
            $this->service->fresh()->resolveCommissionFor($this->ana)['source'],
        );

        // Con la persona al 50%: manda la persona.
        $this->ana->update(['commission_rate' => 0.50]);
        $this->assertSame(
            CommissionResolver::SOURCE_PERSON,
            $this->service->fresh()->resolveCommissionFor($this->ana->fresh())['source'],
        );

        // Sin porcentaje propio del servicio ni de la persona: la categoría.
        $this->ana->update(['commission_rate' => null]);
        $this->service->update(['commission_rate' => null]);
        $this->assertSame(
            CommissionResolver::SOURCE_CATEGORY,
            $this->service->fresh()->resolveCommissionFor($this->ana->fresh())['source'],
        );
    }
}
