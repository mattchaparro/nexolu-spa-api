<?php

namespace Tests\Feature\Clients;

use App\Models\Appointment;
use App\Models\Business;
use App\Models\LoyaltyReward;
use App\Models\LoyaltyStamp;
use App\Models\PaymentMethod;
use App\Models\Resource;
use App\Models\Service;
use App\Models\User;
use App\Support\Money\LoyaltyCalculator;
use App\Support\PermissionCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Scheduling\SchedulingScenario;
use Tests\TestCase;

/**
 * La tarjeta de sellos, de punta a punta.
 *
 * Cada prueba de acá defiende contra algo que el sistema viejo sufrió de
 * verdad -- el comando `gamification:recalculate` de Blue Souls existía para
 * parchear justo estos casos.
 */
class LoyaltyTest extends TestCase
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

        // El cobro estampa `checked_out_at` con el reloj: anclado a un
        // miércoles, la venta cae el mismo día de la cita corra cuando corra.
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
        $this->service = $this->makeService($this->business, 60, [$this->maria]);
        $this->service->update(['name' => 'Manicure', 'price' => 50000, 'commission_rate' => 0.30]);

        Sanctum::actingAs($this->admin->fresh());
    }

    private function hoy(): CarbonImmutable
    {
        return CarbonImmutable::now('America/Bogota')->startOfDay();
    }

    private function crearPrograma(array $overrides = []): array
    {
        return $this->postJson('/api/v1/loyalty/program', array_merge([
            'name' => 'Tarjeta Luxury',
            'stamps_required' => 3,
            'reward_type' => LoyaltyCalculator::REWARD_DISCOUNT_PERCENT,
            'reward_value' => 100,
        ], $overrides))->assertOk()->json('program');
    }

    /** Agenda, cobra, y devuelve el id de la cita. */
    private function visita(string $hora, ?int $clientId = null): int
    {
        $id = $this->postJson('/api/v1/appointments', [
            'service_id' => $this->service->id,
            'resource_id' => $this->maria->id,
            'starts_at' => $this->hoy()->format('Y-m-d')." {$hora}:00",
            'client_id' => $clientId,
            'client_name' => $clientId === null ? 'Carolina' : null,
            'client_phone' => $clientId === null ? '3001234567' : null,
        ])->assertCreated()->json('id');

        $this->postJson("/api/v1/appointments/{$id}/checkout", [
            'payment_method_id' => $this->efectivo->id,
        ])->assertOk();

        return $id;
    }

    private function clienteId(): int
    {
        return \App\Models\Client::withoutGlobalScope('business')->latest('id')->first()->id;
    }

    /*
    |--------------------------------------------------------------------------
    | El programa
    |--------------------------------------------------------------------------
    */

    public function test_una_tarjeta_de_una_visita_no_se_deja_configurar(): void
    {
        // Regalar en cada visita no es fidelización, es una rebaja permanente
        // que nadie decidió.
        $this->postJson('/api/v1/loyalty/program', [
            'name' => 'Mala', 'stamps_required' => 1,
            'reward_type' => LoyaltyCalculator::REWARD_DISCOUNT_PERCENT, 'reward_value' => 100,
        ])->assertStatus(422);
    }

    public function test_un_premio_sin_valor_no_se_deja_configurar(): void
    {
        $this->postJson('/api/v1/loyalty/program', [
            'name' => 'Sin premio', 'stamps_required' => 5,
            'reward_type' => LoyaltyCalculator::REWARD_DISCOUNT_AMOUNT, 'reward_value' => 0,
        ])->assertStatus(422);
    }

    public function test_un_servicio_gratis_exige_decir_cual(): void
    {
        $this->postJson('/api/v1/loyalty/program', [
            'name' => 'Servicio', 'stamps_required' => 5,
            'reward_type' => LoyaltyCalculator::REWARD_FREE_SERVICE,
        ])->assertStatus(422);
    }

    public function test_apagar_el_programa_no_borra_los_sellos_ganados(): void
    {
        $this->crearPrograma();
        $this->visita('10:00');

        $this->deleteJson('/api/v1/loyalty/program')->assertOk();

        // Borrar el programa se llevaría por delante la tarjeta de gente que
        // ya hizo las visitas, y eso se descubre en el mostrador.
        $this->assertSame(1, LoyaltyStamp::withoutGlobalScope('business')->count());
    }

    /*
    |--------------------------------------------------------------------------
    | Ganar sellos
    |--------------------------------------------------------------------------
    */

    public function test_cobrar_una_visita_suma_un_sello(): void
    {
        $this->crearPrograma();
        $this->visita('10:00');

        $card = $this->getJson("/api/v1/clients/{$this->clienteId()}/loyalty")->assertOk();

        $this->assertSame(1, $card->json('stamps'));
        $this->assertSame(2, $card->json('remaining'));
        $this->assertFalse($card->json('complete'));
    }

    public function test_una_cita_no_puede_dar_dos_sellos(): void
    {
        /*
         * Deshacer un cobro y volver a cobrarlo pasa de verdad. Es el caso que
         * en el sistema viejo desincronizaba el contador y obligaba a correr
         * `gamification:recalculate`. Acá lo rechaza la base.
         */
        $this->crearPrograma();
        $id = $this->visita('10:00');

        $this->deleteJson("/api/v1/appointments/{$id}/checkout")->assertOk();
        $this->postJson("/api/v1/appointments/{$id}/checkout", [
            'payment_method_id' => $this->efectivo->id,
        ])->assertOk();

        $this->assertSame(1, LoyaltyStamp::withoutGlobalScope('business')->count());
    }

    public function test_sin_programa_no_se_suma_nada(): void
    {
        $this->visita('10:00');

        $this->assertSame(0, LoyaltyStamp::withoutGlobalScope('business')->count());
    }

    public function test_una_visita_por_debajo_del_minimo_no_suma(): void
    {
        // En el sistema viejo un retoque barato daba el mismo sello que un
        // juego completo: la tarjeta se llenaba con lo barato.
        $this->crearPrograma(['min_ticket' => 80000]);
        $this->visita('10:00');

        $this->assertSame(0, LoyaltyStamp::withoutGlobalScope('business')->count());
    }

    public function test_una_cita_sin_ficha_de_cliente_no_suma(): void
    {
        // Crear fichas fantasma para no perder el sello llenaría la base de
        // clientes que no existen.
        $this->crearPrograma();

        $id = $this->postJson('/api/v1/appointments', [
            'service_id' => $this->service->id,
            'resource_id' => $this->maria->id,
            'starts_at' => $this->hoy()->format('Y-m-d').' 11:00:00',
            'client_name' => 'Sin ficha',
        ])->assertCreated()->json('id');

        // El mostrador siempre resuelve una ficha, así que se desata a mano
        // para ejercitar la guarda: es el estado de una cita vieja o migrada
        // sin cliente, y no puede tumbar el cobro.
        Appointment::withoutGlobalScope('business')->where('id', $id)->update(['client_id' => null]);

        $this->postJson("/api/v1/appointments/{$id}/checkout", [
            'payment_method_id' => $this->efectivo->id,
        ])->assertOk();

        $this->assertSame(0, LoyaltyStamp::withoutGlobalScope('business')->count());
    }

    /*
    |--------------------------------------------------------------------------
    | Ganar el premio
    |--------------------------------------------------------------------------
    */

    public function test_al_llenar_la_tarjeta_se_gana_el_premio_y_se_reinicia(): void
    {
        $this->crearPrograma(['stamps_required' => 3]);

        $this->visita('10:00');
        $cliente = $this->clienteId();
        $this->visita('11:00', $cliente);
        $this->visita('12:00', $cliente);

        $card = $this->getJson("/api/v1/clients/{$cliente}/loyalty")->assertOk();

        // La tarjeta arranca de cero: los tres sellos pagaron el premio. Sin
        // reinicio, una clienta de años lo desbloquea todo y el programa deja
        // de motivarla.
        $this->assertSame(0, $card->json('stamps'));
        $this->assertCount(1, $card->json('rewards'));
        $this->assertSame('100% de descuento', $card->json('rewards.0.label'));
    }

    public function test_el_premio_se_congela_al_ganarlo(): void
    {
        $this->crearPrograma(['stamps_required' => 2, 'reward_value' => 50]);
        $this->visita('10:00');
        $cliente = $this->clienteId();
        $this->visita('11:00', $cliente);

        // El negocio baja el premio al día siguiente.
        $this->crearPrograma(['stamps_required' => 2, 'reward_value' => 10]);

        // A quien ya llenó su tarjeta se le entrega lo que decía el día que la
        // llenó, igual que el precio de una cita cobrada.
        $premio = LoyaltyReward::withoutGlobalScope('business')->latest('id')->first();
        $this->assertEqualsWithDelta(50, $premio->reward_value, 0.01);
    }

    /*
    |--------------------------------------------------------------------------
    | Canjear
    |--------------------------------------------------------------------------
    */

    public function test_canjear_el_premio_descuenta_del_cobro(): void
    {
        $this->crearPrograma(['stamps_required' => 2, 'reward_value' => 100]);
        $this->visita('10:00');
        $cliente = $this->clienteId();
        $this->visita('11:00', $cliente);

        $premio = LoyaltyReward::withoutGlobalScope('business')->latest('id')->first();

        $id = $this->postJson('/api/v1/appointments', [
            'service_id' => $this->service->id,
            'resource_id' => $this->maria->id,
            'starts_at' => $this->hoy()->format('Y-m-d').' 13:00:00',
            'client_id' => $cliente,
        ])->assertCreated()->json('id');

        $cobrada = $this->postJson("/api/v1/appointments/{$id}/checkout", [
            'payment_method_id' => $this->efectivo->id,
            'loyalty_reward_id' => $premio->id,
        ])->assertOk();

        // 100% sobre 50.000: la visita sale gratis.
        $this->assertEqualsWithDelta(50000, $cobrada->json('discount_amount'), 0.01);
        $this->assertEqualsWithDelta(0, $cobrada->json('total'), 0.01);
        $this->assertSame(LoyaltyReward::STATUS_USED, $premio->fresh()->status);
    }

    public function test_un_premio_no_se_canjea_dos_veces(): void
    {
        $this->crearPrograma(['stamps_required' => 2, 'reward_value' => 100]);
        $this->visita('10:00');
        $cliente = $this->clienteId();
        $this->visita('11:00', $cliente);

        $premio = LoyaltyReward::withoutGlobalScope('business')->latest('id')->first();
        $premio->update(['status' => LoyaltyReward::STATUS_USED]);

        $id = $this->postJson('/api/v1/appointments', [
            'service_id' => $this->service->id,
            'resource_id' => $this->maria->id,
            'starts_at' => $this->hoy()->format('Y-m-d').' 13:00:00',
            'client_id' => $cliente,
        ])->assertCreated()->json('id');

        $this->postJson("/api/v1/appointments/{$id}/checkout", [
            'payment_method_id' => $this->efectivo->id,
            'loyalty_reward_id' => $premio->id,
        ])->assertNotFound();
    }

    public function test_no_se_canjea_el_premio_de_otra_persona(): void
    {
        // Sin validar contra el cliente de la cita, mandar un id ajeno
        // canjearía el premio de alguien más.
        $this->crearPrograma(['stamps_required' => 2, 'reward_value' => 100]);
        $this->visita('10:00');
        $carolina = $this->clienteId();
        $this->visita('11:00', $carolina);

        $premio = LoyaltyReward::withoutGlobalScope('business')->latest('id')->first();

        $id = $this->postJson('/api/v1/appointments', [
            'service_id' => $this->service->id,
            'resource_id' => $this->maria->id,
            'starts_at' => $this->hoy()->format('Y-m-d').' 13:00:00',
            'client_name' => 'Otra Persona',
            'client_phone' => '3009998877',
        ])->assertCreated()->json('id');

        $this->postJson("/api/v1/appointments/{$id}/checkout", [
            'payment_method_id' => $this->efectivo->id,
            'loyalty_reward_id' => $premio->id,
        ])->assertNotFound();

        $this->assertSame(LoyaltyReward::STATUS_AVAILABLE, $premio->fresh()->status);
    }

    public function test_deshacer_el_cobro_devuelve_el_premio(): void
    {
        $this->crearPrograma(['stamps_required' => 2, 'reward_value' => 100]);
        $this->visita('10:00');
        $cliente = $this->clienteId();
        $this->visita('11:00', $cliente);

        $premio = LoyaltyReward::withoutGlobalScope('business')->latest('id')->first();

        $id = $this->postJson('/api/v1/appointments', [
            'service_id' => $this->service->id,
            'resource_id' => $this->maria->id,
            'starts_at' => $this->hoy()->format('Y-m-d').' 13:00:00',
            'client_id' => $cliente,
        ])->assertCreated()->json('id');

        $this->postJson("/api/v1/appointments/{$id}/checkout", [
            'payment_method_id' => $this->efectivo->id,
            'loyalty_reward_id' => $premio->id,
        ])->assertOk();

        $this->deleteJson("/api/v1/appointments/{$id}/checkout")->assertOk();

        // Deshacer un cobro corrige la plata; no le quita a la clienta un
        // premio que ya se había ganado.
        $this->assertSame(LoyaltyReward::STATUS_AVAILABLE, $premio->fresh()->status);
    }

    /*
    |--------------------------------------------------------------------------
    | El plan
    |--------------------------------------------------------------------------
    */

    public function test_sin_la_funcion_contratada_no_hay_fidelizacion(): void
    {
        $this->business->update([
            'feature_flags' => array_merge($this->business->feature_flags ?? [], ['loyalty' => false]),
        ]);
        Sanctum::actingAs($this->admin->fresh());

        // 403 y no 404: el módulo existe, este negocio no lo contrató.
        $this->getJson('/api/v1/loyalty/program')->assertForbidden();

        // Y no se suman sellos por debajo.
        $this->visita('10:00');
        $this->assertSame(0, LoyaltyStamp::withoutGlobalScope('business')->count());
    }
}
