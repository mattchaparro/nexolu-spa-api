<?php

namespace Tests\Feature\Scheduling;

use App\Models\ResourceOccupancy;
use App\Services\Scheduling\BookingService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * La carrera de verdad: dos transacciones simultaneas peleando por el mismo
 * hueco.
 *
 * Esta clase usa DatabaseMigrations y no RefreshDatabase a proposito. El
 * envoltorio transaccional de RefreshDatabase dejaria los datos sin commitear,
 * y la segunda conexion no los veria -- no habria carrera que probar.
 *
 * Es la prueba que justifica que resource_occupancy exista. Un chequeo previo
 * del tipo "esta libre?" siempre deja una ventana entre la lectura y la
 * escritura; aca se comprueba que la base cierra esa ventana por su cuenta.
 */
class ConcurrentBookingTest extends TestCase
{
    use DatabaseMigrations, SchedulingScenario;

    private const OTHER = 'concurrent';

    protected function setUp(): void
    {
        parent::setUp();

        // Segunda conexion al mismo esquema: es "el otro proceso".
        config([
            'database.connections.'.self::OTHER => config('database.connections.mysql'),
        ]);
    }

    public function test_dos_transacciones_simultaneas_no_pueden_tomar_el_mismo_hueco(): void
    {
        $business = $this->makeBusiness(['slot_granularity_min' => 60]);
        $resource = $this->makeResource($business, start: '09:00:00', end: '18:00:00');
        $service = $this->makeService($business, 60, [$resource]);
        $booking = app(BookingService::class);

        // Dos citas en horarios libres distintos, para tener dos items reales
        // a los que apuntar. Ambas quedan commiteadas.
        $itemA = $booking->book($business, [[
            'service_id' => $service->id,
            'resource_id' => $resource->id,
            'starts_at' => $this->wednesday()->setTime(9, 0),
        ]], null, 'Cliente A')->items->first();

        $itemB = $booking->book($business, [[
            'service_id' => $service->id,
            'resource_id' => $resource->id,
            'starts_at' => $this->wednesday()->setTime(11, 0),
        ]], null, 'Cliente B')->items->first();

        // Un hueco libre que ambos van a intentar reclamar a la vez.
        $contested = $this->wednesday()->setTime(15, 0)->utc();

        $row = fn (int $itemId) => [
            'business_id' => $business->id,
            'resource_id' => $resource->id,
            'appointment_item_id' => $itemId,
            'slot_start' => $contested->format('Y-m-d H:i:s'),
        ];

        // --- Proceso 1: reclama y NO commitea todavia ---
        DB::connection('mysql')->beginTransaction();
        DB::connection('mysql')->table('resource_occupancy')->insert($row($itemA->id));

        // --- Proceso 2: intenta el mismo hueco mientras el 1 sigue abierto ---
        $other = DB::connection(self::OTHER);
        // Sin esto, el segundo proceso esperaria los 50s de
        // innodb_lock_wait_timeout por defecto antes de rendirse.
        $other->statement('SET SESSION innodb_lock_wait_timeout = 2');

        $secondFailed = false;
        try {
            $other->table('resource_occupancy')->insert($row($itemB->id));
        } catch (QueryException) {
            // InnoDB bloquea al segundo escritor sobre el indice unico hasta
            // que el primero resuelva. Al vencer el timeout, falla.
            $secondFailed = true;
        }

        $this->assertTrue(
            $secondFailed,
            'El segundo proceso logro escribir el mismo hueco mientras el primero lo tenia reclamado.',
        );

        DB::connection('mysql')->commit();

        // Ya commiteado el primero, el segundo sigue sin poder: ahora la
        // causa es la violacion del indice unico, no el bloqueo.
        $duplicate = null;
        try {
            $other->table('resource_occupancy')->insert($row($itemB->id));
        } catch (QueryException $e) {
            $duplicate = $e;
        }

        $this->assertNotNull($duplicate, 'El indice unico no rechazo la fila duplicada.');
        $this->assertSame(
            '23000',
            (string) $duplicate->getCode(),
            'BookingService::isUniqueViolation() espera SQLSTATE 23000; si MySQL devuelve otro, '
            .'una doble reserva se propagaria como error 500 en vez de como 409.',
        );

        // Una sola fila sobrevive para ese hueco.
        $this->assertSame(1, ResourceOccupancy::where('slot_start', $contested)->count());
    }

    protected function tearDown(): void
    {
        // Si una asercion falla con la transaccion abierta, dejarla colgada
        // bloquea las pruebas siguientes.
        while (DB::connection('mysql')->transactionLevel() > 0) {
            DB::connection('mysql')->rollBack();
        }

        parent::tearDown();
    }
}
