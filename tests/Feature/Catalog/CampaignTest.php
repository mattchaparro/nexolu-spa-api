<?php

namespace Tests\Feature\Catalog;

use App\Models\Business;
use App\Models\DiscountCampaign;
use App\Models\PaymentMethod;
use App\Models\Resource;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\User;
use App\Support\Money\CampaignCalculator;
use App\Support\Money\CommissionPolicy;
use App\Support\PermissionCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Scheduling\SchedulingScenario;
use Tests\TestCase;

/**
 * Campañas de temporada: el mes de la madre, la semana de pestañas.
 *
 * Lo que se defiende: que dos promociones no se encimen sin que nadie lo haya
 * decidido, y que la comisión de quien atiende no pague la publicidad del
 * local.
 */
class CampaignTest extends TestCase
{
    use RefreshDatabase, SchedulingScenario;

    private Business $business;

    private User $admin;

    private Resource $maria;

    private Service $manicure;

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
        $this->manicure = $this->makeService($this->business, 60, [$this->maria]);
        $this->manicure->update(['name' => 'Manicure', 'price' => 50000, 'commission_rate' => 0.30]);

        Sanctum::actingAs($this->admin->fresh());
    }

    private function hoy(): CarbonImmutable
    {
        return CarbonImmutable::now('America/Bogota')->startOfDay();
    }

    private function campana(array $overrides = []): DiscountCampaign
    {
        return DiscountCampaign::create(array_merge([
            'business_id' => $this->business->id,
            'name' => 'Mes de la madre',
            'discount_type' => CampaignCalculator::TYPE_PERCENT,
            'discount_value' => 20,
            'applies_to' => CampaignCalculator::APPLIES_ALL,
            'starts_on' => $this->hoy()->subDays(3)->toDateString(),
            'ends_on' => $this->hoy()->addDays(3)->toDateString(),
            'is_active' => true,
        ], $overrides));
    }

    private function cobrar(string $hora = '10:00'): \Illuminate\Testing\TestResponse
    {
        $id = $this->postJson('/api/v1/appointments', [
            'service_id' => $this->manicure->id,
            'resource_id' => $this->maria->id,
            'starts_at' => $this->hoy()->format('Y-m-d')." {$hora}:00",
            'client_name' => 'Carolina',
        ])->assertCreated()->json('id');

        return $this->postJson("/api/v1/appointments/{$id}/checkout", [
            'payment_method_id' => $this->efectivo->id,
        ])->assertOk();
    }

    public function test_una_campana_vigente_se_aplica_sola(): void
    {
        $this->campana();

        $cobrada = $this->cobrar();

        $this->assertEqualsWithDelta(10000, $cobrada->json('discount_amount'), 0.01);
        $this->assertEqualsWithDelta(40000, $cobrada->json('total'), 0.01);
        $this->assertStringContainsString('Mes de la madre', $cobrada->json('discount_reason'));
    }

    public function test_una_campana_vencida_no_se_aplica(): void
    {
        $this->campana([
            'starts_on' => $this->hoy()->subDays(30)->toDateString(),
            'ends_on' => $this->hoy()->subDay()->toDateString(),
        ]);

        $this->assertEqualsWithDelta(0, $this->cobrar()->json('discount_amount'), 0.01);
    }

    public function test_una_campana_apagada_no_se_aplica(): void
    {
        $this->campana(['is_active' => false]);

        $this->assertEqualsWithDelta(0, $this->cobrar()->json('discount_amount'), 0.01);
    }

    public function test_la_campana_no_le_baja_la_comision_a_quien_atiende(): void
    {
        /*
         * Es el único origen que el negocio decide por su cuenta para traer
         * gente que de otro modo no habría venido. Bajarle la comisión por una
         * promoción que nadie le consultó es cobrarle a ella la publicidad del
         * local.
         */
        $this->campana();

        $cobrada = $this->cobrar();

        // Se cobran 40.000 pero la comisión va sobre los 50.000 de lista.
        $this->assertEqualsWithDelta(40000, $cobrada->json('total'), 0.01);
        $this->assertEqualsWithDelta(15000, $cobrada->json('commission_total'), 0.01);
    }

    public function test_el_negocio_puede_decidir_que_la_campana_si_baje_la_comision(): void
    {
        $this->business->update(['commission_settings' => [
            'commission_base_campaign' => CommissionPolicy::BASE_CHARGED,
        ]]);
        Sanctum::actingAs($this->admin->fresh());

        $this->campana();

        // 30% sobre 40.000.
        $this->assertEqualsWithDelta(12000, $this->cobrar()->json('commission_total'), 0.01);
    }

    public function test_solo_alcanza_a_los_servicios_de_su_alcance(): void
    {
        $pestanas = $this->makeService($this->business, 60, [$this->maria]);
        $pestanas->update(['name' => 'Pestañas', 'price' => 80000]);

        $campana = $this->campana(['applies_to' => CampaignCalculator::APPLIES_SERVICES]);
        $campana->services()->sync([$pestanas->id]);

        // El manicure queda fuera: la campaña era de pestañas.
        $this->assertEqualsWithDelta(0, $this->cobrar()->json('discount_amount'), 0.01);
    }

    public function test_alcanza_una_categoria_entera(): void
    {
        $categoria = ServiceCategory::create([
            'business_id' => $this->business->id, 'name' => 'Manos', 'sort_order' => 0,
        ]);
        $this->manicure->update(['service_category_id' => $categoria->id]);

        $campana = $this->campana(['applies_to' => CampaignCalculator::APPLIES_CATEGORIES]);
        $campana->categories()->sync([$categoria->id]);

        $this->assertEqualsWithDelta(10000, $this->cobrar()->json('discount_amount'), 0.01);
    }

    public function test_dos_campanas_no_se_suman_gana_la_mayor(): void
    {
        /*
         * Encimar promociones da un descuento que nadie decidió, y en un
         * negocio de margen chico eso se nota rápido. Se aplica la mejor.
         */
        $this->campana(['name' => 'Chica', 'discount_value' => 10]);
        $this->campana(['name' => 'Grande', 'discount_value' => 30]);

        $cobrada = $this->cobrar();

        $this->assertEqualsWithDelta(15000, $cobrada->json('discount_amount'), 0.01);
        $this->assertStringContainsString('Grande', $cobrada->json('discount_reason'));
    }

    public function test_un_descuento_a_mano_manda_sobre_la_campana(): void
    {
        $this->campana();

        $id = $this->postJson('/api/v1/appointments', [
            'service_id' => $this->manicure->id,
            'resource_id' => $this->maria->id,
            'starts_at' => $this->hoy()->format('Y-m-d').' 11:00:00',
            'client_name' => 'Carolina',
        ])->assertCreated()->json('id');

        $cobrada = $this->postJson("/api/v1/appointments/{$id}/checkout", [
            'payment_method_id' => $this->efectivo->id,
            'discount_amount' => 5000,
            'discount_reason' => 'Acuerdo puntual',
        ])->assertOk();

        // Lo escrito manda, no se suma a la campaña.
        $this->assertEqualsWithDelta(5000, $cobrada->json('discount_amount'), 0.01);
    }

    public function test_una_garantia_no_entra_en_la_campana(): void
    {
        // Ya vale cero: descontarle algo más solo agregaría ruido a la cuenta.
        $this->campana();

        $id = $this->postJson('/api/v1/appointments', [
            'service_id' => $this->manicure->id,
            'resource_id' => $this->maria->id,
            'starts_at' => $this->hoy()->format('Y-m-d').' 12:00:00',
            'client_name' => 'Carolina',
            'is_warranty' => true,
            'warranty_for_resource_id' => $this->maria->id,
        ])->assertCreated()->json('id');

        $cobrada = $this->postJson("/api/v1/appointments/{$id}/checkout", [
            'payment_method_id' => $this->efectivo->id,
        ])->assertOk();

        $this->assertEqualsWithDelta(0, $cobrada->json('discount_amount'), 0.01);
        $this->assertEqualsWithDelta(0, $cobrada->json('total'), 0.01);
    }

    public function test_sin_la_funcion_contratada_no_hay_campanas(): void
    {
        $this->business->update([
            'feature_flags' => array_merge($this->business->feature_flags ?? [], ['promotions' => false]),
        ]);
        Sanctum::actingAs($this->admin->fresh());

        $this->campana();

        $this->assertEqualsWithDelta(0, $this->cobrar()->json('discount_amount'), 0.01);
    }
}
