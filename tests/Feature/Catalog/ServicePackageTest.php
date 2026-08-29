<?php

namespace Tests\Feature\Catalog;

use App\Models\Appointment;
use App\Models\Business;
use App\Models\PaymentMethod;
use App\Models\Resource;
use App\Models\Service;
use App\Models\ServicePackage;
use App\Models\User;
use App\Support\Money\PackagePricing;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Scheduling\SchedulingScenario;
use Tests\TestCase;

/**
 * Agendar varios servicios, y combos.
 *
 * El modelo ya soportaba varias líneas por cita y el cobro ya repartía el
 * descuento entre ellas; lo que faltaba era poder CREARLAS. Estas pruebas
 * cubren la cadena entera: encontrar dónde cabe, agendarla, moverla y cobrarla.
 */
class ServicePackageTest extends TestCase
{
    use RefreshDatabase, SchedulingScenario;

    private Business $business;

    private User $admin;

    private Resource $ana;

    private Resource $lucia;

    private Service $manicure;

    private Service $pedicure;

    private PaymentMethod $efectivo;

    protected function setUp(): void
    {
        parent::setUp();

        PermissionCatalog::sync();

        $this->business = $this->makeBusiness(['slot_granularity_min' => 30, 'min_booking_notice_min' => 0]);

        $this->admin = User::create([
            'business_id' => $this->business->id, 'name' => 'Dueña',
            'email' => 'admin@prueba.test', 'password' => Hash::make('password123'), 'is_active' => true,
        ]);
        PermissionCatalog::applyRole($this->admin, PermissionCatalog::ROLE_ADMIN);

        // Miércoles, 09:00 a 17:00.
        $this->ana = $this->makeResource($this->business, 'Ana', '09:00:00', '17:00:00', [3]);
        $this->lucia = $this->makeResource($this->business, 'Lucia', '09:00:00', '17:00:00', [3]);

        $this->manicure = $this->makeService($this->business, 60, [$this->ana, $this->lucia]);
        $this->manicure->update(['name' => 'Manicure', 'price' => 100000, 'commission_rate' => 0.40]);

        $this->pedicure = $this->makeService($this->business, 60, [$this->ana, $this->lucia]);
        $this->pedicure->update(['name' => 'Pedicure', 'price' => 60000, 'commission_rate' => 0.40]);

        $this->efectivo = PaymentMethod::create([
            'business_id' => $this->business->id, 'name' => 'Efectivo',
            'counts_as_cash' => true, 'is_active' => true, 'sort_order' => 1,
        ]);

        Sanctum::actingAs($this->admin->fresh());
    }

    private function combo(string $type = PackagePricing::TYPE_FIXED, ?float $value = 20000): ServicePackage
    {
        $package = ServicePackage::create([
            'business_id' => $this->business->id,
            'name' => 'Manos y pies',
            'slug' => 'manos-y-pies',
            'discount_type' => $type,
            'discount_value' => $value,
            'is_active' => true,
            'is_bookable_online' => true,
        ]);

        $package->services()->sync([
            $this->manicure->id => ['sort_order' => 0],
            $this->pedicure->id => ['sort_order' => 1],
        ]);

        return $package->fresh('services');
    }

    private function cadena(array $params): \Illuminate\Testing\TestResponse
    {
        return $this->getJson('/api/v1/availability/chain?'.http_build_query($params))->assertOk();
    }

    /*
    |--------------------------------------------------------------------------
    | Dónde cabe una cadena
    |--------------------------------------------------------------------------
    */

    public function test_la_disponibilidad_de_una_cadena_reserva_hueco_para_todo(): void
    {
        $respuesta = $this->cadena([
            'service_ids' => [$this->manicure->id, $this->pedicure->id],
            'date' => $this->wednesday()->format('Y-m-d'),
        ]);

        $this->assertSame(120, $respuesta->json('total_minutes'));

        $primero = $respuesta->json('slots.0');
        $this->assertSame('9:00 am', $primero['label']);
        // Dos tramos: el segundo empieza cuando termina el primero.
        $this->assertCount(2, $primero['legs']);
        $this->assertSame('09:00', $primero['legs'][0]['label']);
        $this->assertSame('10:00', $primero['legs'][1]['label']);

        // El último hueco tiene que dejar entrar las dos horas antes de cerrar.
        $ultimo = collect($respuesta->json('slots'))->last();
        $this->assertSame('3:00 pm', $ultimo['label']);
    }

    public function test_una_cadena_no_cabe_si_solo_hay_hueco_para_el_primero(): void
    {
        // Se ocupa a las dos personas de 10:00 en adelante, dejando sólo la
        // franja de 09:00 a 10:00 libre: alcanza para un servicio, no para dos.
        foreach ([$this->ana, $this->lucia] as $persona) {
            $this->postJson('/api/v1/appointments', [
                'service_id' => $this->manicure->id,
                'resource_id' => $persona->id,
                'starts_at' => $this->wednesday()->format('Y-m-d').' 10:00:00',
                'client_name' => 'Ocupada',
            ])->assertCreated();
        }

        $horas = array_column(
            $this->cadena([
                'service_ids' => [$this->manicure->id, $this->pedicure->id],
                'date' => $this->wednesday()->format('Y-m-d'),
            ])->json('slots'),
            'label',
        );

        // Encadenar la respuesta de un servicio suelto habría ofrecido las
        // 09:00, que está libre para el manicure y no para el pedicure.
        $this->assertNotContains('9:00 am', $horas);
        $this->assertContains('11:00 am', $horas);
    }

    public function test_la_cadena_puede_repartirse_entre_dos_personas(): void
    {
        // Ana ocupada de 10:00 a 11:00: el pedicure lo toma Lucia y la cadena
        // sigue cabiendo a las 09:00.
        $this->postJson('/api/v1/appointments', [
            'service_id' => $this->manicure->id,
            'resource_id' => $this->ana->id,
            'starts_at' => $this->wednesday()->format('Y-m-d').' 10:00:00',
            'client_name' => 'Ocupada',
        ])->assertCreated();

        $slot = collect($this->cadena([
            'service_ids' => [$this->manicure->id, $this->pedicure->id],
            'date' => $this->wednesday()->format('Y-m-d'),
        ])->json('slots'))->firstWhere('label', '9:00 am');

        $this->assertNotNull($slot);
        $this->assertSame('Ana', $slot['legs'][0]['resource_name']);
        $this->assertSame('Lucia', $slot['legs'][1]['resource_name']);
    }

    public function test_el_orden_pedido_es_el_orden_agendado(): void
    {
        // `whereIn` devuelve en orden de base de datos: sin ordenar a mano,
        // pedir "pedicure y después manicure" agendaría al revés.
        $legs = $this->cadena([
            'service_ids' => [$this->pedicure->id, $this->manicure->id],
            'date' => $this->wednesday()->format('Y-m-d'),
        ])->json('slots.0.legs');

        $this->assertSame('Pedicure', $legs[0]['service_name']);
        $this->assertSame('Manicure', $legs[1]['service_name']);
    }

    public function test_un_buffer_separa_los_servicios(): void
    {
        // 15 min de limpieza tras el manicure: el pedicure no empieza a las
        // 10:00 sino a las 10:15, aunque sea otra persona la que lo haga.
        $this->manicure->update(['buffer_after_min' => 15]);

        $legs = $this->cadena([
            'service_ids' => [$this->manicure->id, $this->pedicure->id],
            'date' => $this->wednesday()->format('Y-m-d'),
        ])->json('slots.0.legs');

        $this->assertSame('09:00', $legs[0]['label']);
        $this->assertSame('10:15', $legs[1]['label']);
    }

    /*
    |--------------------------------------------------------------------------
    | Agendar varios servicios
    |--------------------------------------------------------------------------
    */

    public function test_agendar_una_visita_de_dos_servicios(): void
    {
        $slot = $this->cadena([
            'service_ids' => [$this->manicure->id, $this->pedicure->id],
            'date' => $this->wednesday()->format('Y-m-d'),
        ])->json('slots.0');

        $cita = $this->postJson('/api/v1/appointments', [
            'items' => array_map(fn (array $leg) => [
                'service_id' => $leg['service_id'],
                'resource_id' => $leg['resource_id'],
                'starts_at' => $leg['starts_at'],
            ], $slot['legs']),
            'client_name' => 'Carolina',
        ])->assertCreated();

        $this->assertCount(2, $cita->json('items'));
        // La cita abarca desde el primero hasta el último.
        $this->assertStringContainsString('09:00', $cita->json('items.0.starts_at'));
        $this->assertStringContainsString('10:00', $cita->json('items.1.starts_at'));
    }

    public function test_mover_una_cita_de_varios_servicios_la_mueve_entera(): void
    {
        $slot = $this->cadena([
            'service_ids' => [$this->manicure->id, $this->pedicure->id],
            'date' => $this->wednesday()->format('Y-m-d'),
        ])->json('slots.0');

        $id = $this->postJson('/api/v1/appointments', [
            'items' => array_map(fn (array $leg) => [
                'service_id' => $leg['service_id'],
                'resource_id' => $leg['resource_id'],
                'starts_at' => $leg['starts_at'],
            ], $slot['legs']),
            'client_name' => 'Carolina',
        ])->assertCreated()->json('id');

        // De 09:00 a 13:00: las dos líneas se corren cuatro horas.
        $movida = $this->patchJson("/api/v1/appointments/{$id}/reschedule", [
            'starts_at' => $this->wednesday()->format('Y-m-d').' 13:00:00',
        ])->assertOk();

        $this->assertStringContainsString('13:00', $movida->json('items.0.starts_at'));
        // La separación se conserva: si no, el pedicure quedaría antes que el
        // manicure o encima de él.
        $this->assertStringContainsString('14:00', $movida->json('items.1.starts_at'));
    }

    public function test_no_se_cambia_de_persona_una_cita_encadenada(): void
    {
        $slot = $this->cadena([
            'service_ids' => [$this->manicure->id, $this->pedicure->id],
            'date' => $this->wednesday()->format('Y-m-d'),
        ])->json('slots.0');

        $id = $this->postJson('/api/v1/appointments', [
            'items' => array_map(fn (array $leg) => [
                'service_id' => $leg['service_id'],
                'resource_id' => $leg['resource_id'],
                'starts_at' => $leg['starts_at'],
            ], $slot['legs']),
            'client_name' => 'Carolina',
        ])->assertCreated()->json('id');

        // "Muévela a Lucia" no dice a cuál de los dos servicios se refiere.
        $this->patchJson("/api/v1/appointments/{$id}/reschedule", [
            'starts_at' => $this->wednesday()->format('Y-m-d').' 13:00:00',
            'resource_id' => $this->lucia->id,
        ])->assertStatus(422);
    }

    /*
    |--------------------------------------------------------------------------
    | Combos
    |--------------------------------------------------------------------------
    */

    public function test_crear_un_combo_calcula_su_precio(): void
    {
        $respuesta = $this->postJson('/api/v1/service-packages', [
            'name' => 'Manos y pies',
            'service_ids' => [$this->manicure->id, $this->pedicure->id],
            'discount_type' => PackagePricing::TYPE_PRICE,
            'discount_value' => 140000,
        ])->assertCreated();

        // Con delta y no `assertSame`: JSON serializa un float entero como
        // int, y 160000 nunca va a ser idéntico a 160000.0.
        $this->assertEqualsWithDelta(160000, $respuesta->json('list_total'), 0.01);
        $this->assertEqualsWithDelta(20000, $respuesta->json('discount'), 0.01);
        $this->assertEqualsWithDelta(140000, $respuesta->json('total'), 0.01);
        $this->assertSame(120, $respuesta->json('total_minutes'));
    }

    public function test_un_combo_de_un_solo_servicio_se_rechaza(): void
    {
        // Eso es un servicio con otro precio, y para eso se edita el servicio.
        $this->postJson('/api/v1/service-packages', [
            'name' => 'Falso combo',
            'service_ids' => [$this->manicure->id],
            'discount_type' => PackagePricing::TYPE_PERCENT,
            'discount_value' => 10,
        ])->assertStatus(422);
    }

    public function test_agendar_un_combo_y_cobrarlo_aplica_su_descuento(): void
    {
        $combo = $this->combo(PackagePricing::TYPE_FIXED, 20000);

        $slot = $this->cadena([
            'package_id' => $combo->id,
            'date' => $this->wednesday()->format('Y-m-d'),
        ])->json('slots.0');

        $id = $this->postJson('/api/v1/appointments', [
            'service_package_id' => $combo->id,
            'items' => array_map(fn (array $leg) => [
                'service_id' => $leg['service_id'],
                'resource_id' => $leg['resource_id'],
                'starts_at' => $leg['starts_at'],
            ], $slot['legs']),
            'client_name' => 'Carolina',
        ])->assertCreated()->json('id');

        // Sin mandar descuento: el del combo se aplica solo. Quien cobra tres
        // días después no tiene por qué acordarse de que era un combo.
        $cobrada = $this->postJson("/api/v1/appointments/{$id}/checkout", [
            'payment_method_id' => $this->efectivo->id,
        ])->assertOk();

        $this->assertEqualsWithDelta(160000, $cobrada->json('subtotal'), 0.01);
        $this->assertEqualsWithDelta(20000, $cobrada->json('discount_amount'), 0.01);
        $this->assertEqualsWithDelta(140000, $cobrada->json('total'), 0.01);
    }

    public function test_el_descuento_del_combo_baja_la_comision_de_los_dos(): void
    {
        $combo = $this->combo(PackagePricing::TYPE_FIXED, 20000);

        $slot = $this->cadena([
            'package_id' => $combo->id,
            'date' => $this->wednesday()->format('Y-m-d'),
        ])->json('slots.0');

        $id = $this->postJson('/api/v1/appointments', [
            'service_package_id' => $combo->id,
            'items' => array_map(fn (array $leg) => [
                'service_id' => $leg['service_id'],
                'resource_id' => $leg['resource_id'],
                'starts_at' => $leg['starts_at'],
            ], $slot['legs']),
            'client_name' => 'Carolina',
        ])->assertCreated()->json('id');

        $cobrada = $this->postJson("/api/v1/appointments/{$id}/checkout", [
            'payment_method_id' => $this->efectivo->id,
        ])->assertOk();

        /*
         * La comisión va sobre lo COBRADO, no sobre el precio de lista. El
         * descuento se reparte proporcionalmente: 12.500 al manicure (100k de
         * 160k) y 7.500 al pedicure. Al 40%: 35.000 y 21.000.
         *
         * Si el combo hubiera reescrito los precios de línea en vez de dejar
         * un descuento, el equipo cobraría comisión sobre plata que el negocio
         * no recibió.
         */
        $this->assertEqualsWithDelta(56000, $cobrada->json('commission_total'), 0.01);
        $this->assertEqualsWithDelta(35000, $cobrada->json('items.0.commission_amount'), 0.01);
        $this->assertEqualsWithDelta(21000, $cobrada->json('items.1.commission_amount'), 0.01);
    }

    public function test_un_descuento_escrito_a_mano_manda_sobre_el_del_combo(): void
    {
        $combo = $this->combo(PackagePricing::TYPE_FIXED, 20000);

        $slot = $this->cadena([
            'package_id' => $combo->id,
            'date' => $this->wednesday()->format('Y-m-d'),
        ])->json('slots.0');

        $id = $this->postJson('/api/v1/appointments', [
            'service_package_id' => $combo->id,
            'items' => array_map(fn (array $leg) => [
                'service_id' => $leg['service_id'],
                'resource_id' => $leg['resource_id'],
                'starts_at' => $leg['starts_at'],
            ], $slot['legs']),
            'client_name' => 'Carolina',
        ])->assertCreated()->json('id');

        // No se suman: 20.000 del combo más 30.000 a mano descontaría 50.000
        // sin que nadie lo haya decidido.
        $cobrada = $this->postJson("/api/v1/appointments/{$id}/checkout", [
            'payment_method_id' => $this->efectivo->id,
            'discount_amount' => 30000,
            'discount_reason' => 'Cliente frecuente',
        ])->assertOk();

        $this->assertEqualsWithDelta(30000, $cobrada->json('discount_amount'), 0.01);
    }

    public function test_no_se_puede_llevar_el_descuento_del_combo_con_menos_servicios(): void
    {
        $combo = $this->combo(PackagePricing::TYPE_FIXED, 20000);

        // Sólo el manicure, marcándolo como el combo entero: sería llevarse
        // 20.000 de rebaja sobre 100.000 en vez de sobre 160.000.
        $this->postJson('/api/v1/appointments', [
            'service_package_id' => $combo->id,
            'items' => [[
                'service_id' => $this->manicure->id,
                'resource_id' => $this->ana->id,
                'starts_at' => $this->wednesday()->format('Y-m-d').' 09:00:00',
            ]],
            'client_name' => 'Vivo',
        ])->assertStatus(422);
    }

    public function test_borrar_un_combo_lo_desactiva_y_no_toca_las_citas(): void
    {
        $combo = $this->combo();

        $slot = $this->cadena([
            'package_id' => $combo->id,
            'date' => $this->wednesday()->format('Y-m-d'),
        ])->json('slots.0');

        $id = $this->postJson('/api/v1/appointments', [
            'service_package_id' => $combo->id,
            'items' => array_map(fn (array $leg) => [
                'service_id' => $leg['service_id'],
                'resource_id' => $leg['resource_id'],
                'starts_at' => $leg['starts_at'],
            ], $slot['legs']),
            'client_name' => 'Carolina',
        ])->assertCreated()->json('id');

        $this->deleteJson("/api/v1/service-packages/{$combo->id}")->assertOk();

        // Sigue existiendo: borrarlo dejaría un cobro sin explicación de por
        // qué se rebajó.
        $this->assertDatabaseHas('service_packages', ['id' => $combo->id, 'is_active' => false]);
        $this->assertSame(
            $combo->id,
            Appointment::withoutGlobalScope('business')->find($id)->service_package_id,
        );
    }

    public function test_un_combo_de_otro_negocio_no_se_puede_usar(): void
    {
        $otro = $this->makeBusiness();

        // Por DB directo: `BelongsToBusiness` reasigna el business_id al del
        // usuario autenticado al crear, así que un ServicePackage::create()
        // acá terminaría dentro del negocio de la dueña y no probaría nada.
        $ajenoId = \Illuminate\Support\Facades\DB::table('service_packages')->insertGetId([
            'business_id' => $otro->id, 'name' => 'Ajeno', 'slug' => 'ajeno',
            'discount_type' => PackagePricing::TYPE_PERCENT, 'discount_value' => 90,
            'is_active' => true, 'is_bookable_online' => true, 'sort_order' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->getJson("/api/v1/availability/chain?package_id={$ajenoId}&date="
            .$this->wednesday()->format('Y-m-d'))
            ->assertNotFound();

        // Y tampoco se puede agendar con él.
        $this->postJson('/api/v1/appointments', [
            'service_package_id' => $ajenoId,
            'items' => [[
                'service_id' => $this->manicure->id,
                'resource_id' => $this->ana->id,
                'starts_at' => $this->wednesday()->format('Y-m-d').' 09:00:00',
            ]],
            'client_name' => 'Vivo',
        ])->assertNotFound();
    }

    public function test_una_persona_del_equipo_no_crea_combos(): void
    {
        $staff = User::create([
            'business_id' => $this->business->id, 'name' => 'Ana',
            'email' => 'ana@prueba.test', 'password' => Hash::make('password123'), 'is_active' => true,
        ]);
        PermissionCatalog::applyRole($staff, PermissionCatalog::ROLE_STAFF);

        Sanctum::actingAs($staff->fresh());

        $this->postJson('/api/v1/service-packages', [
            'name' => 'Mío', 'service_ids' => [1, 2],
            'discount_type' => PackagePricing::TYPE_PERCENT, 'discount_value' => 90,
        ])->assertForbidden();
    }
}
