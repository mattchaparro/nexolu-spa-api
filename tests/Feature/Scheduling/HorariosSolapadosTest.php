<?php

namespace Tests\Feature\Scheduling;

use App\Models\Business;
use App\Models\Resource;
use App\Models\ResourceBreak;
use App\Models\ResourceSchedule;
use App\Models\Service;
use App\Services\Scheduling\AvailabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Cuando el horario de alguien está cargado varias veces.
 *
 * Le pasó a Alejandra: el viernes le quedaron tres filas encimadas
 * --09:00-17:00, 09:00-15:00 y 15:00-17:00--, y como cada una se recortaba
 * por su lado, restarle el almuerzo dejaba pedazos que no eran la jornada
 * de nadie. En la práctica perdía media tarde: el bot le ofrecía las 9:00 y
 * las 10:30 y después nada hasta las 3.
 *
 * Los horarios duplicados se limpian, pero el motor no puede depender de
 * que los datos lleguen limpios: los junta antes de calcular.
 */
class HorariosSolapadosTest extends TestCase
{
    use RefreshDatabase, SchedulingScenario;

    private Business $business;

    private Resource $persona;

    private Service $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeClockBeforeWednesday();

        $this->business = $this->makeBusiness([
            'slot_granularity_min' => 30,
            'min_booking_notice_min' => 0,
        ]);

        // Miércoles, 09:00 a 17:00.
        $this->persona = $this->makeResource($this->business, 'Alejandra', '09:00:00', '17:00:00', [3]);
        $this->service = $this->makeService($this->business, 90, [$this->persona]);
    }

    /** @return list<string> */
    private function horas(): array
    {
        return $this->startTimes(app(AvailabilityService::class)->slotsForService(
            $this->business,
            $this->service,
            $this->wednesday(),
        ));
    }

    private function otroHorario(string $desde, string $hasta): void
    {
        ResourceSchedule::create([
            'business_id' => $this->business->id,
            'resource_id' => $this->persona->id,
            'weekday' => 3,
            'start_time' => $desde,
            'end_time' => $hasta,
            'effective_from' => '2020-01-01',
        ]);
    }

    public function test_el_horario_cargado_tres_veces_da_lo_mismo_que_una(): void
    {
        $solaUna = $this->horas();

        // Las otras dos filas que tenía Alejandra, encimadas sobre la suya.
        $this->otroHorario('09:00:00', '15:00:00');
        $this->otroHorario('15:00:00', '17:00:00');

        $this->assertSame(
            $solaUna,
            $this->horas(),
            'Cargar el mismo horario tres veces no puede cambiar las horas que se ofrecen.',
        );
    }

    public function test_dos_turnos_que_se_tocan_son_una_sola_jornada(): void
    {
        /*
         * Quien tiene 09:00-13:00 y 13:00-17:00 trabaja corrido, no dos
         * veces. Sin juntarlos, un servicio de noventa minutos no se podría
         * ofrecer a las 12:30 -- cruzaría el límite entre dos ventanas que
         * en realidad no existe.
         */
        $persona = $this->makeResource($this->business, 'Marcela', '09:00:00', '13:00:00', [3]);
        $this->service->resources()->attach($persona->id);

        ResourceSchedule::create([
            'business_id' => $this->business->id,
            'resource_id' => $persona->id,
            'weekday' => 3,
            'start_time' => '13:00:00',
            'end_time' => '17:00:00',
            'effective_from' => '2020-01-01',
        ]);

        $suyas = collect(app(AvailabilityService::class)->slotsForService($this->business, $this->service, $this->wednesday()))
            ->where('resource_id', $persona->id)
            ->map(fn ($s) => $s['starts_at']->setTimezone('America/Bogota')->format('H:i'))
            ->values()
            ->all();

        // 09:00 a 17:00 corrido, de noventa en noventa.
        $this->assertSame(['09:00', '10:30', '12:00', '13:30', '15:00'], $suyas);
    }

    public function test_el_almuerzo_se_resta_una_vez_y_no_tres(): void
    {
        $this->otroHorario('09:00:00', '15:00:00');
        $this->otroHorario('15:00:00', '17:00:00');

        ResourceBreak::create([
            'business_id' => $this->business->id,
            'resource_id' => $this->persona->id,
            'weekday' => null,
            'start_time' => '13:00:00',
            'end_time' => '14:00:00',
            'label' => 'Almuerzo',
            'effective_from' => '2020-01-01',
            'is_active' => true,
        ]);

        $horas = $this->horas();

        // La mañana llega hasta donde cabe antes de la una, y la tarde
        // arranca a las dos: 14:00 y 15:30, no las 15:00 sueltas que salían
        // cuando cada ventana se recortaba por su lado.
        $this->assertSame(['09:00', '10:30', '14:00', '15:30'], $horas);
    }
}
