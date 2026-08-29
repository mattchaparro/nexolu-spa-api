<?php

namespace Tests\Feature\Scheduling;

use App\Models\Appointment;
use App\Models\AppointmentItem;
use App\Models\ResourceOccupancy;
use App\Services\Scheduling\AvailabilityService;
use App\Services\Scheduling\BookingService;
use App\Services\Scheduling\Exceptions\SlotUnavailableException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Semantica del anti-solape.
 *
 * La carrera real entre dos transacciones simultaneas se prueba aparte, en
 * ConcurrentBookingTest, porque necesita commits de verdad en dos conexiones
 * y no puede correr dentro de la transaccion que envuelve estas pruebas.
 */
class DoubleBookingTest extends TestCase
{
    use RefreshDatabase, SchedulingScenario;

    private BookingService $booking;

    private AvailabilityService $availability;

    protected function setUp(): void
    {
        parent::setUp();
        $this->booking = app(BookingService::class);
        $this->availability = app(AvailabilityService::class);
    }

    public function test_reservar_el_mismo_hueco_dos_veces_falla_y_no_deja_basura(): void
    {
        $business = $this->makeBusiness();
        $resource = $this->makeResource($business);
        $service = $this->makeService($business, 60, [$resource]);
        $at = $this->wednesday()->setTime(10, 0);

        $this->booking->book($business, [[
            'service_id' => $service->id,
            'resource_id' => $resource->id,
            'starts_at' => $at,
        ]], null, 'Cliente A', '+573001112233');

        $appointmentsBefore = Appointment::count();
        $itemsBefore = AppointmentItem::count();

        try {
            $this->booking->book($business, [[
                'service_id' => $service->id,
                'resource_id' => $resource->id,
                'starts_at' => $at,
            ]], null, 'Cliente B', '+573004445566');

            $this->fail('La segunda reserva sobre el mismo hueco debio fallar.');
        } catch (SlotUnavailableException) {
            // Esperado.
        }

        // Lo importante no es solo que falle: es que la transaccion se
        // deshaga entera y no queden citas ni items huerfanos.
        $this->assertSame($appointmentsBefore, Appointment::count());
        $this->assertSame($itemsBefore, AppointmentItem::count());
    }

    public function test_un_solape_parcial_de_una_sola_unidad_tambien_falla(): void
    {
        $business = $this->makeBusiness(['slot_granularity_min' => 15]);
        $resource = $this->makeResource($business);
        $service = $this->makeService($business, 60, [$resource]);
        $at = $this->wednesday()->setTime(10, 0);

        $this->booking->book($business, [[
            'service_id' => $service->id,
            'resource_id' => $resource->id,
            'starts_at' => $at,
        ]], null, 'Cliente A');

        // Empieza 45 min despues: pisa una sola unidad de 15 min.
        $this->expectException(SlotUnavailableException::class);

        $this->booking->book($business, [[
            'service_id' => $service->id,
            'resource_id' => $resource->id,
            'starts_at' => $at->addMinutes(45),
        ]], null, 'Cliente B');
    }

    public function test_dos_citas_adyacentes_conviven(): void
    {
        $business = $this->makeBusiness();
        $resource = $this->makeResource($business);
        $service = $this->makeService($business, 60, [$resource]);
        $at = $this->wednesday()->setTime(10, 0);

        $this->booking->book($business, [[
            'service_id' => $service->id,
            'resource_id' => $resource->id,
            'starts_at' => $at,
        ]], null, 'Cliente A');

        // Empieza exactamente cuando termina la anterior. Debe pasar: es lo
        // que valida que los intervalos sean semiabiertos [inicio, fin).
        $second = $this->booking->book($business, [[
            'service_id' => $service->id,
            'resource_id' => $resource->id,
            'starts_at' => $at->addMinutes(60),
        ]], null, 'Cliente B');

        $this->assertSame(2, Appointment::count());
        $this->assertNotNull($second->id);
    }

    public function test_recursos_distintos_a_la_misma_hora_conviven(): void
    {
        $business = $this->makeBusiness();
        $maria = $this->makeResource($business, 'Maria');
        $ana = $this->makeResource($business, 'Ana');
        $service = $this->makeService($business, 60, [$maria, $ana]);
        $at = $this->wednesday()->setTime(10, 0);

        $this->booking->book($business, [[
            'service_id' => $service->id, 'resource_id' => $maria->id, 'starts_at' => $at,
        ]], null, 'Cliente A');

        $this->booking->book($business, [[
            'service_id' => $service->id, 'resource_id' => $ana->id, 'starts_at' => $at,
        ]], null, 'Cliente B');

        $this->assertSame(2, Appointment::count());
    }

    public function test_cancelar_libera_el_hueco(): void
    {
        $business = $this->makeBusiness(['slot_granularity_min' => 60]);
        $resource = $this->makeResource($business, start: '09:00:00', end: '12:00:00');
        $service = $this->makeService($business, 60, [$resource]);
        $at = $this->wednesday()->setTime(10, 0);

        $appointment = $this->booking->book($business, [[
            'service_id' => $service->id,
            'resource_id' => $resource->id,
            'starts_at' => $at,
        ]], null, 'Cliente A');

        $this->assertNotContains('10:00', $this->startTimes(
            $this->availability->slotsForService($business, $service, $this->wednesday()),
        ));

        $this->booking->cancel($appointment, null, 'El cliente no puede');

        $this->assertSame(0, ResourceOccupancy::count());
        $this->assertContains('10:00', $this->startTimes(
            $this->availability->slotsForService($business, $service, $this->wednesday()),
        ));
        $this->assertSame(Appointment::STATUS_CANCELLED, $appointment->fresh()->status);
    }

    /**
     * Mover una cita dentro de su propio rango.
     *
     * BookingService::reschedule() borra la ocupacion ANTES de reclamar la
     * nueva. Sin ese orden, el indice unico rechazaria filas que este mismo
     * item ya posee. Esta prueba existe para que nadie "optimice" ese borrado.
     */
    public function test_reagendar_dentro_del_propio_rango_no_choca_consigo_mismo(): void
    {
        $business = $this->makeBusiness(['slot_granularity_min' => 15]);
        $resource = $this->makeResource($business);
        $service = $this->makeService($business, 120, [$resource]);
        $at = $this->wednesday()->setTime(10, 0);

        $appointment = $this->booking->book($business, [[
            'service_id' => $service->id,
            'resource_id' => $resource->id,
            'starts_at' => $at,
        ]], null, 'Cliente A');

        $moved = $this->booking->reschedule($appointment, $at->addMinutes(30));

        $this->assertSame(
            '10:30',
            $moved->starts_at->setTimezone('America/Bogota')->format('H:i'),
        );
        // 120 min a 15 min por unidad = 8 filas, ni una mas.
        $this->assertSame(8, ResourceOccupancy::count());
    }

    public function test_reagendar_a_un_hueco_ocupado_falla(): void
    {
        $business = $this->makeBusiness();
        $resource = $this->makeResource($business);
        $service = $this->makeService($business, 60, [$resource]);
        $at = $this->wednesday()->setTime(10, 0);

        $first = $this->booking->book($business, [[
            'service_id' => $service->id, 'resource_id' => $resource->id, 'starts_at' => $at,
        ]], null, 'Cliente A');

        $this->booking->book($business, [[
            'service_id' => $service->id, 'resource_id' => $resource->id, 'starts_at' => $at->addMinutes(60),
        ]], null, 'Cliente B');

        $this->expectException(SlotUnavailableException::class);

        $this->booking->reschedule($first, $at->addMinutes(60));
    }

    public function test_la_ocupacion_cubre_los_buffers_no_solo_el_servicio(): void
    {
        $business = $this->makeBusiness(['slot_granularity_min' => 15]);
        $resource = $this->makeResource($business);
        $service = $this->makeService($business, 30, [$resource], bufferBefore: 15, bufferAfter: 15);
        $at = $this->wednesday()->setTime(10, 0);

        $this->booking->book($business, [[
            'service_id' => $service->id,
            'resource_id' => $resource->id,
            'starts_at' => $at,
        ]], null, 'Cliente A');

        // 15 + 30 + 15 = 60 min ocupados = 4 unidades de 15.
        $this->assertSame(4, ResourceOccupancy::count());

        // Y el buffer previo protege: reservar 15 min antes debe fallar.
        $this->expectException(SlotUnavailableException::class);

        $this->booking->book($business, [[
            'service_id' => $service->id,
            'resource_id' => $resource->id,
            'starts_at' => $at->subMinutes(30),
        ]], null, 'Cliente B');
    }
}
