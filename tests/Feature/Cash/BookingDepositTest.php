<?php

namespace Tests\Feature\Cash;

use App\Models\Appointment;
use App\Models\Business;
use App\Models\PaymentMethod;
use App\Models\Resource;
use App\Models\Service;
use App\Models\User;
use App\Support\Money\DepositCalculator;
use App\Support\PermissionCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Scheduling\SchedulingScenario;
use Tests\TestCase;

/**
 * El abono con que un cliente separa su cita.
 *
 * Existe por las deserciones: quien no dejó nada no pierde nada por no
 * aparecer. Pero es fricción, así que viene apagado y se enciende negocio por
 * negocio.
 *
 * Este API todavía NO cobra en línea -- no hay pasarela cableada. El cliente
 * transfiere y alguien del local confirma. Lo que se prueba acá es que el monto
 * se congele bien, que la plata quede en una cuenta, y que la caja del día no
 * se descuadre por contarla dos veces.
 */
class BookingDepositTest extends TestCase
{
    use RefreshDatabase, SchedulingScenario;

    private Business $business;

    private User $admin;

    private Resource $maria;

    private Service $service;

    private PaymentMethod $efectivo;

    private PaymentMethod $nequi;

    protected function setUp(): void
    {
        parent::setUp();

        // Mismo anclaje que CashRegisterTest: el cobro estampa
        // `checked_out_at` con el reloj, y sin anclar la venta cae un día
        // distinto al de la cita cuando la prueba corre un domingo.
        $this->travelTo(
            CarbonImmutable::now('America/Bogota')
                ->startOfDay()
                ->previous(CarbonImmutable::WEDNESDAY)
                ->setTime(8, 0),
        );

        PermissionCatalog::sync();

        $this->business = $this->makeBusiness([
            'slot_granularity_min' => 60,
            'min_booking_notice_min' => 0,
            'deposit_type' => DepositCalculator::TYPE_PERCENT,
            'deposit_value' => 30,
            'deposit_instructions' => 'Nequi 300 111 2233 a nombre de Luxury Nails.',
        ]);

        $this->business->update([
            'slug' => 'luxury-nails',
            'feature_flags' => array_merge($this->business->feature_flags ?? [], [
                'booking_deposit' => true,
            ]),
        ]);

        $this->admin = User::create([
            'business_id' => $this->business->id, 'name' => 'Dueña',
            'email' => 'admin@prueba.test', 'password' => Hash::make('password123'), 'is_active' => true,
        ]);
        PermissionCatalog::applyRole($this->admin, PermissionCatalog::ROLE_ADMIN);

        $this->efectivo = PaymentMethod::create([
            'business_id' => $this->business->id, 'name' => 'Efectivo', 'counts_as_cash' => true,
        ]);
        $this->nequi = PaymentMethod::create([
            'business_id' => $this->business->id, 'name' => 'Nequi', 'counts_as_cash' => false,
        ]);

        $this->maria = $this->makeResource($this->business, 'Maria', '09:00:00', '18:00:00');
        $this->service = $this->makeService($this->business, 60, [$this->maria]);
        $this->service->update([
            'name' => 'Manicure', 'price' => 100000, 'commission_rate' => 0.30,
            'is_bookable_online' => true,
        ]);
    }

    /** El día laboral al que está anclado el reloj. */
    private function hoy(): CarbonImmutable
    {
        return CarbonImmutable::now('America/Bogota')->startOfDay();
    }

    private function reservarEnLinea(string $hora = '10:00'): Appointment
    {
        $this->postJson('/api/v1/public/luxury-nails/appointments', [
            'service_id' => $this->service->id,
            'resource_id' => $this->maria->id,
            'starts_at' => $this->hoy()->format('Y-m-d')." {$hora}:00",
            'client_name' => 'Carolina Restrepo',
            'client_phone' => '3001234567',
            'client_email' => 'carolina@correo.test',
        ])->assertCreated();

        return Appointment::withoutGlobalScope('business')->latest('id')->first();
    }

    /*
    |--------------------------------------------------------------------------
    | La politica
    |--------------------------------------------------------------------------
    */

    public function test_la_pagina_publica_dice_cuanto_hay_que_abonar(): void
    {
        $deposito = $this->getJson('/api/v1/public/luxury-nails')->assertOk()->json('deposit');

        $this->assertSame('percent', $deposito['type']);
        $this->assertSame('30%', $deposito['label']);
        $this->assertSame('Nequi 300 111 2233 a nombre de Luxury Nails.', $deposito['instructions']);
    }

    public function test_con_la_bandera_apagada_no_se_pide_abono(): void
    {
        $this->business->update([
            'feature_flags' => array_merge($this->business->feature_flags, ['booking_deposit' => false]),
        ]);

        $this->assertNull($this->getJson('/api/v1/public/luxury-nails')->json('deposit'));

        $cita = $this->reservarEnLinea();
        $this->assertEqualsWithDelta(0, $cita->deposit_amount, 0.01);
    }

    public function test_encender_la_bandera_sin_configurar_monto_no_pide_nada(): void
    {
        /*
         * Prender el módulo y que de una empiece a pedirle plata por adelantado
         * a los clientes, con un monto que nadie eligió, es la clase de
         * sorpresa que el negocio descubre por las quejas.
         */
        $this->business->update(['scheduling_settings' => array_merge(
            $this->business->scheduling_settings ?? [],
            ['deposit_type' => DepositCalculator::TYPE_NONE, 'deposit_value' => 0],
        )]);

        $this->assertNull($this->business->fresh()->depositPolicy());
        $this->assertEqualsWithDelta(0, $this->reservarEnLinea()->deposit_amount, 0.01);
    }

    /*
    |--------------------------------------------------------------------------
    | Al reservar
    |--------------------------------------------------------------------------
    */

    public function test_reservar_en_linea_congela_el_abono_y_lo_dice(): void
    {
        $respuesta = $this->postJson('/api/v1/public/luxury-nails/appointments', [
            'service_id' => $this->service->id,
            'resource_id' => $this->maria->id,
            'starts_at' => $this->hoy()->format('Y-m-d').' 10:00:00',
            'client_name' => 'Carolina Restrepo',
            'client_phone' => '3001234567',
            'client_email' => 'carolina@correo.test',
        ])->assertCreated();

        $this->assertEqualsWithDelta(30000, $respuesta->json('deposit_amount'), 0.01);
        $this->assertStringContainsString('Nequi', $respuesta->json('deposit_instructions'));
        // El mensaje cambia: la cita NO está asegurada hasta que llegue el abono.
        $this->assertStringContainsString('abono', $respuesta->json('message'));
    }

    public function test_subir_la_politica_no_le_cambia_el_abono_a_quien_ya_reservo(): void
    {
        $cita = $this->reservarEnLinea();
        $this->assertEqualsWithDelta(30000, $cita->deposit_amount, 0.01);

        $this->business->update(['scheduling_settings' => array_merge(
            $this->business->scheduling_settings ?? [],
            ['deposit_value' => 60],
        )]);

        // Se le pidió lo que decía la pantalla el día que reservó.
        $this->assertEqualsWithDelta(30000, $cita->fresh()->deposit_amount, 0.01);
    }

    public function test_una_cita_del_mostrador_no_pide_abono(): void
    {
        // Acá hay alguien del local decidiendo; el sistema no tiene por qué
        // exigirle un adelanto a quien llamó por teléfono.
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/v1/appointments', [
            'service_id' => $this->service->id,
            'resource_id' => $this->maria->id,
            'starts_at' => $this->hoy()->format('Y-m-d').' 11:00:00',
            'client_name' => 'Por teléfono',
        ])->assertCreated();

        $cita = Appointment::withoutGlobalScope('business')->latest('id')->first();
        $this->assertEqualsWithDelta(0, $cita->deposit_amount, 0.01);
    }

    /*
    |--------------------------------------------------------------------------
    | Registrar que llego
    |--------------------------------------------------------------------------
    */

    public function test_registrar_el_abono_lo_deja_en_una_cuenta(): void
    {
        $cita = $this->reservarEnLinea();
        Sanctum::actingAs($this->admin);

        $respuesta = $this->postJson("/api/v1/appointments/{$cita->id}/deposit", [
            'payment_method_id' => $this->nequi->id,
            'reference' => 'M12345',
        ])->assertOk();

        $this->assertEqualsWithDelta(30000, $respuesta->json('deposit_paid'), 0.01);

        $fresca = $cita->fresh();
        $this->assertNotNull($fresca->deposit_paid_at);
        $this->assertSame($this->nequi->id, $fresca->deposit_payment_method_id);
        $this->assertSame('M12345', $fresca->deposit_reference);
    }

    public function test_no_se_registra_dos_veces_el_mismo_abono(): void
    {
        $cita = $this->reservarEnLinea();
        Sanctum::actingAs($this->admin);

        $payload = ['payment_method_id' => $this->nequi->id];
        $this->postJson("/api/v1/appointments/{$cita->id}/deposit", $payload)->assertOk();
        $this->postJson("/api/v1/appointments/{$cita->id}/deposit", $payload)->assertStatus(422);
    }

    public function test_se_puede_recibir_menos_de_lo_que_se_pidio(): void
    {
        // El cliente mandó lo que tenía y el local lo aceptó igual.
        $cita = $this->reservarEnLinea();
        Sanctum::actingAs($this->admin);

        $this->postJson("/api/v1/appointments/{$cita->id}/deposit", [
            'payment_method_id' => $this->nequi->id, 'amount' => 20000,
        ])->assertOk();

        $this->assertEqualsWithDelta(20000, $cita->fresh()->deposit_amount, 0.01);
    }

    public function test_deshacer_el_abono_no_borra_lo_que_se_pidio(): void
    {
        $cita = $this->reservarEnLinea();
        Sanctum::actingAs($this->admin);

        $this->postJson("/api/v1/appointments/{$cita->id}/deposit", [
            'payment_method_id' => $this->nequi->id,
        ])->assertOk();

        $this->deleteJson("/api/v1/appointments/{$cita->id}/deposit")->assertOk();

        $fresca = $cita->fresh();
        $this->assertNull($fresca->deposit_paid_at);
        // Sigue debiendo el abono: deshacer dice que NO llegó, no que no se pida.
        $this->assertEqualsWithDelta(30000, $fresca->deposit_amount, 0.01);
    }

    public function test_registrar_un_abono_pide_el_permiso_de_cobrar(): void
    {
        /*
         * Es la misma compuerta que cobrar, a propósito: un abono es plata que
         * entra y tiene que quedar en una cuenta. Quien puede recibir el pago
         * de un servicio puede recibir su adelanto; quien no, ninguno de los
         * dos.
         */
        $cita = $this->reservarEnLinea();

        $staff = User::create([
            'business_id' => $this->business->id, 'name' => 'Maria',
            'email' => 'maria@prueba.test', 'password' => Hash::make('password123'), 'is_active' => true,
        ]);
        PermissionCatalog::applyRole($staff, PermissionCatalog::ROLE_STAFF);
        $staff->revokePermissionTo('caja.cobrar');
        Sanctum::actingAs($staff->fresh());

        $this->postJson("/api/v1/appointments/{$cita->id}/deposit", [
            'payment_method_id' => $this->nequi->id,
        ])->assertForbidden();
    }

    public function test_no_se_registra_el_abono_de_una_cita_cancelada(): void
    {
        $cita = $this->reservarEnLinea();
        Sanctum::actingAs($this->admin);

        $this->postJson("/api/v1/appointments/{$cita->id}/cancel", ['reason' => 'No puede'])->assertOk();

        $this->postJson("/api/v1/appointments/{$cita->id}/deposit", [
            'payment_method_id' => $this->nequi->id,
        ])->assertStatus(422);
    }

    public function test_no_se_toca_el_abono_de_una_cita_ya_cobrada(): void
    {
        // Deshacer el abono despues del cobro dejaria la venta cuadrando contra
        // una plata que el sistema dice que nunca llego.
        $cita = $this->reservarEnLinea();
        Sanctum::actingAs($this->admin);

        $this->postJson("/api/v1/appointments/{$cita->id}/deposit", [
            'payment_method_id' => $this->nequi->id,
        ])->assertOk();
        $this->postJson("/api/v1/appointments/{$cita->id}/checkout", [
            'payment_method_id' => $this->efectivo->id,
        ])->assertOk();

        $this->deleteJson("/api/v1/appointments/{$cita->id}/deposit")->assertStatus(422);
    }

    /*
    |--------------------------------------------------------------------------
    | Al cobrar
    |--------------------------------------------------------------------------
    */

    public function test_al_cobrar_solo_se_pide_lo_que_falta(): void
    {
        $cita = $this->reservarEnLinea();
        Sanctum::actingAs($this->admin);

        $this->postJson("/api/v1/appointments/{$cita->id}/deposit", [
            'payment_method_id' => $this->nequi->id,
        ])->assertOk();

        $cobrada = $this->postJson("/api/v1/appointments/{$cita->id}/checkout", [
            'payment_method_id' => $this->efectivo->id,
        ])->assertOk();

        // La VENTA sigue siendo de 100.000: el abono no es un descuento.
        $this->assertEqualsWithDelta(100000, $cobrada->json('total'), 0.01);
        // Pero en el mostrador solo dejó el resto.
        $this->assertEqualsWithDelta(70000, $cobrada->json('amount_due'), 0.01);
        // Y la comisión se calcula sobre la venta, no sobre lo que faltaba.
        $this->assertEqualsWithDelta(30000, $cobrada->json('commission_total'), 0.01);
    }

    public function test_la_caja_del_dia_no_cuenta_el_abono_dos_veces(): void
    {
        $cita = $this->reservarEnLinea();
        Sanctum::actingAs($this->admin);

        $this->postJson("/api/v1/appointments/{$cita->id}/deposit", [
            'payment_method_id' => $this->nequi->id,
        ])->assertOk();

        $this->postJson("/api/v1/appointments/{$cita->id}/checkout", [
            'payment_method_id' => $this->efectivo->id,
        ])->assertOk();

        $fecha = $this->hoy()->toDateString();
        $cierre = $this->getJson("/api/v1/cash/closing/preview?date={$fecha}")->assertOk();

        // 30.000 por Nequi + 70.000 en efectivo. Ni 130.000 ni 200.000.
        $this->assertEqualsWithDelta(100000, $cierre->json('total_charged'), 0.01);
        $this->assertEqualsWithDelta(70000, $cierre->json('total_cash'), 0.01);

        $porMetodo = collect($cierre->json('payment_breakdown'))->keyBy('label');
        $this->assertEqualsWithDelta(70000, $porMetodo['Efectivo']['total'], 0.01);
        $this->assertEqualsWithDelta(30000, $porMetodo['Nequi']['total'], 0.01);
    }

    public function test_el_abono_entra_el_dia_que_llego_y_no_el_de_la_cita(): void
    {
        /*
         * La plata llegó el día que llegó. Contarla el día del servicio dejaría
         * el cierre de hoy largo y el de la semana entrante corto, sin nada que
         * lo explique.
         */
        $cita = $this->reservarEnLinea();
        Sanctum::actingAs($this->admin);

        $this->postJson("/api/v1/appointments/{$cita->id}/deposit", [
            'payment_method_id' => $this->nequi->id,
        ])->assertOk();

        $hoy = $this->hoy()->toDateString();

        // Sin cobrar todavía: el abono ya está en la caja de hoy.
        $cierre = $this->getJson("/api/v1/cash/closing/preview?date={$hoy}")->assertOk();
        $this->assertEqualsWithDelta(30000, $cierre->json('total_charged'), 0.01);
        // Por Nequi, así que el cajón no lo tiene.
        $this->assertEqualsWithDelta(0, $cierre->json('total_cash'), 0.01);
    }
}
