<?php

namespace Tests\Feature\Scheduling;

use App\Models\ScheduleException;
use App\Services\Scheduling\AvailabilityService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AvailabilityTest extends TestCase
{
    use RefreshDatabase, SchedulingScenario;

    private AvailabilityService $availability;

    protected function setUp(): void
    {
        parent::setUp();
        $this->availability = app(AvailabilityService::class);
    }

    public function test_recurso_sin_horario_ese_dia_no_tiene_huecos(): void
    {
        $business = $this->makeBusiness();
        // Solo trabaja lunes; se consulta un miercoles.
        $resource = $this->makeResource($business, weekdays: [1]);
        $service = $this->makeService($business, 60, [$resource]);

        $slots = $this->availability->slotsForService($business, $service, $this->wednesday());

        $this->assertSame([], $slots);
    }

    public function test_los_huecos_respetan_la_granularidad_del_negocio(): void
    {
        $business = $this->makeBusiness(['slot_granularity_min' => 30]);
        $resource = $this->makeResource($business, start: '09:00:00', end: '11:00:00');
        $service = $this->makeService($business, 60, [$resource]);

        $slots = $this->availability->slotsForService($business, $service, $this->wednesday());

        // 09:00-11:00 con servicio de 60 min cada 30: 09:00, 09:30, 10:00.
        $this->assertSame(['09:00', '09:30', '10:00'], $this->startTimes($slots));
    }

    public function test_un_servicio_no_cabe_en_una_ventana_mas_corta_que_su_duracion(): void
    {
        $business = $this->makeBusiness();
        $resource = $this->makeResource($business, start: '09:00:00', end: '10:00:00');
        $service = $this->makeService($business, 90, [$resource]);

        $slots = $this->availability->slotsForService($business, $service, $this->wednesday());

        $this->assertSame([], $slots);
    }

    public function test_los_buffers_ocupan_al_recurso_pero_no_se_muestran(): void
    {
        $business = $this->makeBusiness(['slot_granularity_min' => 30]);
        $resource = $this->makeResource($business, start: '09:00:00', end: '11:00:00');
        // 30 min de servicio + 15 antes + 15 despues = 60 min ocupados.
        $service = $this->makeService($business, 30, [$resource], bufferBefore: 15, bufferAfter: 15);

        $slots = $this->availability->slotsForService($business, $service, $this->wednesday());

        // El primer hueco arranca 09:00 ocupado, pero el servicio visible
        // empieza 09:15. La ultima ventana de 60 min empieza 10:00 -> 10:15.
        $this->assertSame(['09:15', '09:45', '10:15'], $this->startTimes($slots));

        // Y el fin visible respeta la duracion, no la ocupacion.
        $this->assertSame(
            '09:45',
            $slots[0]['ends_at']->setTimezone('America/Bogota')->format('H:i'),
        );
    }

    public function test_la_anticipacion_minima_recorta_los_huecos_de_hoy(): void
    {
        $business = $this->makeBusiness([
            'slot_granularity_min' => 30,
            'min_booking_notice_min' => 120,
        ]);
        $resource = $this->makeResource($business, start: '09:00:00', end: '13:00:00');
        $service = $this->makeService($business, 30, [$resource]);

        $today = $this->wednesday();
        $now = $today->setTime(9, 0); // 09:00 hora Bogota

        $slots = $this->availability->slotsForService($business, $service, $today, now: $now);

        // Con 120 min de anticipacion, nada antes de las 11:00.
        $this->assertSame(['11:00', '11:30', '12:00', '12:30'], $this->startTimes($slots));
    }

    public function test_mas_alla_del_horizonte_no_hay_disponibilidad(): void
    {
        $business = $this->makeBusiness(['max_booking_horizon_days' => 7]);
        $resource = $this->makeResource($business);
        $service = $this->makeService($business, 60, [$resource]);

        $now = $this->wednesday()->setTime(8, 0);
        $farAway = $this->wednesday()->addDays(30);

        $slots = $this->availability->slotsForService($business, $service, $farAway, now: $now);

        $this->assertSame([], $slots);
    }

    public function test_una_excepcion_de_bloqueo_parte_la_ventana_en_dos(): void
    {
        $business = $this->makeBusiness(['slot_granularity_min' => 60]);
        $resource = $this->makeResource($business, start: '09:00:00', end: '13:00:00');
        $service = $this->makeService($business, 60, [$resource]);

        $day = $this->wednesday();

        ScheduleException::create([
            'business_id' => $business->id,
            'resource_id' => $resource->id,
            'starts_at' => $day->setTime(11, 0)->utc(),
            'ends_at' => $day->setTime(12, 0)->utc(),
            'kind' => ScheduleException::KIND_BLOCK,
            'reason' => 'Almuerzo',
        ]);

        $slots = $this->availability->slotsForService($business, $service, $day);

        // 09:00-11:00 deja 09:00 y 10:00; 12:00-13:00 deja 12:00.
        $this->assertSame(['09:00', '10:00', '12:00'], $this->startTimes($slots));
    }

    public function test_una_excepcion_de_horas_extra_suma_disponibilidad(): void
    {
        $business = $this->makeBusiness(['slot_granularity_min' => 60]);
        // Solo trabaja lunes: el miercoles no tendria horario propio.
        $resource = $this->makeResource($business, weekdays: [1]);
        $service = $this->makeService($business, 60, [$resource]);

        $day = $this->wednesday();

        ScheduleException::create([
            'business_id' => $business->id,
            'resource_id' => $resource->id,
            'starts_at' => $day->setTime(14, 0)->utc(),
            'ends_at' => $day->setTime(16, 0)->utc(),
            'kind' => ScheduleException::KIND_EXTRA_HOURS,
            'reason' => 'Turno extra',
        ]);

        $slots = $this->availability->slotsForService($business, $service, $day);

        $this->assertSame(['14:00', '15:00'], $this->startTimes($slots));
    }

    public function test_una_excepcion_sin_recurso_aplica_a_todo_el_negocio(): void
    {
        $business = $this->makeBusiness(['slot_granularity_min' => 60]);
        $maria = $this->makeResource($business, 'Maria', '09:00:00', '12:00:00');
        $ana = $this->makeResource($business, 'Ana', '09:00:00', '12:00:00');
        $service = $this->makeService($business, 60, [$maria, $ana]);

        $day = $this->wednesday();

        ScheduleException::create([
            'business_id' => $business->id,
            'resource_id' => null, // festivo: todo el negocio
            'starts_at' => $day->setTime(0, 0)->utc(),
            'ends_at' => $day->addDay()->setTime(0, 0)->utc(),
            'kind' => ScheduleException::KIND_HOLIDAY,
            'reason' => 'Festivo',
        ]);

        $slots = $this->availability->slotsForService($business, $service, $day);

        $this->assertSame([], $slots);
    }

    public function test_con_varios_recursos_los_huecos_salen_ordenados_por_hora(): void
    {
        $business = $this->makeBusiness(['slot_granularity_min' => 60]);
        $maria = $this->makeResource($business, 'Maria', '09:00:00', '11:00:00');
        $ana = $this->makeResource($business, 'Ana', '10:00:00', '12:00:00');
        $service = $this->makeService($business, 60, [$maria, $ana]);

        $slots = $this->availability->slotsForService($business, $service, $this->wednesday());

        $this->assertSame(['09:00', '10:00', '10:00', '11:00'], $this->startTimes($slots));
    }

    /**
     * La prueba que justifica que el negocio no viva en UTC.
     *
     * Un horario de 09:00 a 17:00 hora Bogota debe devolver huecos en horas
     * locales correctas, y persistir en UTC (cinco horas mas).
     */
    public function test_la_zona_horaria_del_negocio_se_respeta(): void
    {
        $business = $this->makeBusiness(['slot_granularity_min' => 60]);
        $resource = $this->makeResource($business, start: '09:00:00', end: '12:00:00');
        $service = $this->makeService($business, 60, [$resource]);

        $slots = $this->availability->slotsForService($business, $service, $this->wednesday());

        $this->assertSame(['09:00', '10:00', '11:00'], $this->startTimes($slots));

        // El mismo instante, visto en UTC, son las 14:00.
        $this->assertSame(
            '14:00',
            $slots[0]['starts_at']->setTimezone('UTC')->format('H:i'),
        );
    }

    public function test_un_recurso_inactivo_no_ofrece_huecos(): void
    {
        $business = $this->makeBusiness();
        $resource = $this->makeResource($business);
        $service = $this->makeService($business, 60, [$resource]);

        $resource->update(['is_active' => false]);

        $slots = $this->availability->slotsForService($business, $service->fresh(), $this->wednesday());

        $this->assertSame([], $slots);
    }

    public function test_se_puede_filtrar_por_un_recurso_concreto(): void
    {
        $business = $this->makeBusiness(['slot_granularity_min' => 60]);
        $maria = $this->makeResource($business, 'Maria', '09:00:00', '11:00:00');
        $ana = $this->makeResource($business, 'Ana', '09:00:00', '11:00:00');
        $service = $this->makeService($business, 60, [$maria, $ana]);

        $slots = $this->availability->slotsForService(
            $business,
            $service,
            $this->wednesday(),
            onlyResource: $ana,
        );

        $this->assertCount(2, $slots);
        $this->assertSame([$ana->id, $ana->id], array_column($slots, 'resource_id'));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }
}
