<?php

namespace Tests\Feature\Scheduling;

use App\Models\Appointment;
use App\Models\Business;
use App\Models\Resource;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Services\Scheduling\AvailabilityService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Marcela no aguanta pedicures todos los días: si el miércoles tiene uno, el
 * martes y el jueves no se le ofrece otro. Los viernes sí, y el mismo
 * miércoles también (ese es su día de pedicures).
 */
class RestBetweenCategoriesTest extends TestCase
{
    use RefreshDatabase, SchedulingScenario;

    private Business $business;

    private Resource $marcela;

    private Resource $alejandra;

    private Service $pedicure;

    private Service $manicure;

    private AvailabilityService $availability;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeClockBeforeWednesday();

        $this->business = $this->makeBusiness();
        $this->marcela = $this->makeResource($this->business, 'Marcela');
        $this->alejandra = $this->makeResource($this->business, 'Alejandra');

        $pies = ServiceCategory::create(['business_id' => $this->business->id, 'name' => 'Pedicure', 'is_active' => true]);
        $manos = ServiceCategory::create(['business_id' => $this->business->id, 'name' => 'Manicure', 'is_active' => true]);

        $this->pedicure = $this->makeService($this->business, 60, [$this->marcela, $this->alejandra], name: 'Pedi Jelly Spa');
        $this->pedicure->update(['service_category_id' => $pies->id]);
        $this->manicure = $this->makeService($this->business, 60, [$this->marcela, $this->alejandra], name: 'Semipermanente');
        $this->manicure->update(['service_category_id' => $manos->id]);

        // Día de por medio entre pedicures, solo para Marcela.
        $this->marcela->update(['category_rest_days' => [['category_id' => $pies->id, 'rest_days' => 1]]]);

        $this->availability = app(AvailabilityService::class);
        $this->agendar($this->pedicure, $this->marcela, $this->wednesday()->setTime(10, 0));
    }

    public function test_los_dias_vecinos_no_le_ofrecen_pedicure(): void
    {
        $this->assertSame([], $this->horasDe($this->marcela, $this->pedicure, $this->wednesday()->subDay()));
        $this->assertSame([], $this->horasDe($this->marcela, $this->pedicure, $this->wednesday()->addDay()));
    }

    public function test_el_mismo_dia_y_dos_dias_despues_si(): void
    {
        $this->assertNotSame([], $this->horasDe($this->marcela, $this->pedicure, $this->wednesday()));
        $this->assertNotSame([], $this->horasDe($this->marcela, $this->pedicure, $this->wednesday()->addDays(2)));
    }

    public function test_manicure_no_tiene_regla_y_las_demas_tampoco(): void
    {
        $jueves = $this->wednesday()->addDay();

        $this->assertNotSame([], $this->horasDe($this->marcela, $this->manicure, $jueves));
        $this->assertNotSame([], $this->horasDe($this->alejandra, $this->pedicure, $jueves));

        // Sin elegir persona, el jueves el pedicure solo lo ofrece Alejandra.
        $quienes = collect($this->availability->slotsForService($this->business, $this->pedicure, $jueves))
            ->pluck('resource_name')->unique()->values()->all();
        $this->assertSame(['Alejandra'], $quienes);
    }

    public function test_en_un_combo_el_pedicure_cae_en_otra(): void
    {
        $jueves = $this->wednesday()->addDay();

        $slots = $this->availability->slotsForChain($this->business, [$this->manicure, $this->pedicure], $jueves);

        $this->assertNotEmpty($slots);
        foreach ($slots as $slot) {
            $pie = collect($slot['legs'])->firstWhere('service_id', $this->pedicure->id);
            $this->assertSame($this->alejandra->id, $pie['resource_id']);
        }
    }

    public function test_una_cita_cancelada_no_cuenta(): void
    {
        Appointment::query()->update(['status' => Appointment::STATUS_CANCELLED]);

        $this->assertNotSame([], $this->horasDe($this->marcela, $this->pedicure, $this->wednesday()->addDay()));
    }

    /** @return list<string> */
    private function horasDe(Resource $persona, Service $servicio, CarbonImmutable $dia): array
    {
        return $this->startTimes($this->availability->slotsForService($this->business, $servicio, $dia, $persona));
    }

    private function agendar(Service $servicio, Resource $persona, CarbonImmutable $inicio): void
    {
        $cita = Appointment::create([
            'business_id' => $this->business->id,
            'location_id' => $this->business->primaryLocation()?->id,
            'client_name' => 'Clienta',
            'starts_at' => $inicio,
            'ends_at' => $inicio->addHour(),
            'status' => Appointment::STATUS_CONFIRMED,
        ]);

        $cita->items()->create([
            'business_id' => $this->business->id,
            'service_id' => $servicio->id,
            'resource_id' => $persona->id,
            'starts_at' => $inicio,
            'ends_at' => $inicio->addHour(),
            'service_starts_at' => $inicio,
            'service_ends_at' => $inicio->addHour(),
            'price' => $servicio->price,
            'sort_order' => 0,
        ]);
    }
}
