<?php

namespace Tests\Feature\Scheduling;

use App\Models\Appointment;
use App\Models\Business;
use App\Models\Resource;
use App\Models\Service;
use App\Models\User;
use App\Services\Scheduling\AvailabilityService;
use App\Services\Scheduling\BookingService;
use App\Support\PermissionCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Cambios de un día en el horario de una persona.
 *
 * Alejandra hoy puede quedarse hasta las 9; el viernes pidió permiso desde el
 * mediodía; mañana trabaja todo el día aunque no le toque. El motor ya sabía
 * sumar y restar estas horas -- lo que faltaba era poder crearlas.
 */
class ScheduleExceptionApiTest extends TestCase
{
    use RefreshDatabase, SchedulingScenario;

    private Business $business;

    private Resource $alejandra;

    private Service $service;

    private string $day;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeClockBeforeWednesday();
        PermissionCatalog::sync();

        $this->business = $this->makeBusiness(['slot_granularity_min' => 60, 'min_booking_notice_min' => 0]);
        // Trabaja de 9 a 17, de lunes a sábado.
        $this->alejandra = $this->makeResource($this->business, 'Alejandra', '09:00:00', '17:00:00');
        $this->service = $this->makeService($this->business, 60, [$this->alejandra]);
        $this->day = $this->wednesday()->format('Y-m-d');

        Sanctum::actingAs($this->usuario('admin@prueba.test', PermissionCatalog::ROLE_ADMIN));
    }

    private function usuario(string $email, string $rol): User
    {
        $user = User::create([
            'business_id' => $this->business->id,
            'name' => 'Persona',
            'email' => $email,
            'password' => Hash::make('password123'),
            'is_active' => true,
        ]);
        PermissionCatalog::applyRole($user, $rol);

        return $user->fresh();
    }

    /** @return list<string> */
    private function horas(string $day): array
    {
        return $this->startTimes(app(AvailabilityService::class)->slotsForService(
            $this->business,
            $this->service,
            CarbonImmutable::parse($day, 'America/Bogota'),
        ));
    }

    private function crear(array $body)
    {
        return $this->postJson("/api/v1/resources/{$this->alejandra->id}/exceptions", $body);
    }

    public function test_hoy_se_queda_hasta_las_nueve(): void
    {
        $this->assertNotContains('18:00', $this->horas($this->day));

        $this->crear(['kind' => 'extra_hours', 'date' => $this->day, 'start_time' => '17:00', 'end_time' => '21:00'])
            ->assertCreated()
            ->assertJsonPath('kind', 'extra_hours')
            ->assertJsonPath('start_time', '17:00')
            ->assertJsonPath('end_time', '21:00');

        $horas = $this->horas($this->day);
        $this->assertContains('17:00', $horas);
        $this->assertContains('20:00', $horas);
        $this->assertNotContains('21:00', $horas);
        // Sin horas repetidas: el turno extra se junta con el horario.
        $this->assertSame($horas, array_values(array_unique($horas)));
    }

    public function test_permiso_desde_el_mediodia(): void
    {
        $this->assertContains('14:00', $this->horas($this->day));

        $this->crear(['kind' => 'block', 'date' => $this->day, 'start_time' => '12:00', 'end_time' => '17:00', 'reason' => 'Permiso'])
            ->assertCreated()
            ->assertJsonPath('kind', 'block');

        $horas = $this->horas($this->day);
        $this->assertContains('11:00', $horas);
        $this->assertNotContains('12:00', $horas);
        $this->assertNotContains('14:00', $horas);
    }

    public function test_trabaja_todo_el_dia_aunque_no_le_toque(): void
    {
        // El domingo no trabaja.
        $domingo = $this->wednesday()->addDays(4)->format('Y-m-d');
        $this->assertSame([], $this->horas($domingo));

        $this->crear(['kind' => 'extra_hours', 'date' => $domingo, 'start_time' => '09:00', 'end_time' => '18:00'])
            ->assertCreated();

        $this->assertContains('09:00', $this->horas($domingo));
        $this->assertContains('17:00', $this->horas($domingo));
    }

    public function test_quitar_el_cambio_devuelve_el_horario_normal(): void
    {
        $id = $this->crear(['kind' => 'block', 'date' => $this->day, 'all_day' => true])
            ->assertCreated()->json('id');
        $this->assertSame([], $this->horas($this->day));

        $this->deleteJson("/api/v1/resources/{$this->alejandra->id}/exceptions/{$id}")->assertNoContent();

        $this->assertContains('10:00', $this->horas($this->day));
    }

    public function test_varios_dias_seguidos_son_vacaciones(): void
    {
        $hasta = $this->wednesday()->addDays(2)->format('Y-m-d');

        $r = $this->crear(['kind' => 'block', 'date' => $this->day, 'end_date' => $hasta, 'all_day' => true])
            ->assertCreated()
            ->assertJsonPath('kind', 'vacation')
            ->assertJsonPath('all_day', true)
            ->assertJsonPath('end_date', $hasta);

        $this->assertSame([], $this->horas($this->day));
        $this->assertSame([], $this->horas($hasta));
        // El día siguiente al último ya trabaja.
        $this->assertContains('10:00', $this->horas($this->wednesday()->addDays(3)->format('Y-m-d')));
        $this->assertNotNull($r->json('id'));
    }

    public function test_avisa_de_las_citas_que_quedan_dentro_de_lo_que_se_quita(): void
    {
        app(BookingService::class)->book($this->business, [[
            'service_id' => $this->service->id,
            'resource_id' => $this->alejandra->id,
            'starts_at' => $this->wednesday()->setTime(14, 0),
        ]], null, 'Carolina', '+573001112233');

        $r = $this->crear(['kind' => 'block', 'date' => $this->day, 'start_time' => '12:00', 'end_time' => '17:00'])
            ->assertCreated();

        $this->assertCount(1, $r->json('conflicts'));
        $this->assertSame('Carolina', $r->json('conflicts.0.client_name'));
        // La cita no se toca: reagendarla es una decisión de quien administra.
        $this->assertSame(1, Appointment::count());
    }

    public function test_el_listado_trae_lo_que_viene(): void
    {
        $this->crear(['kind' => 'extra_hours', 'date' => $this->day, 'start_time' => '17:00', 'end_time' => '21:00']);
        $this->crear(['kind' => 'block', 'date' => $this->wednesday()->addDay()->format('Y-m-d'), 'all_day' => true]);

        $lista = $this->getJson("/api/v1/resources/{$this->alejandra->id}/exceptions")->assertOk()->json();

        $this->assertCount(2, $lista);
        $this->assertSame(['extra_hours', 'block'], array_column($lista, 'kind'));
    }

    public function test_validaciones(): void
    {
        $this->crear(['kind' => 'block', 'date' => $this->wednesday()->subDays(5)->format('Y-m-d'), 'all_day' => true])
            ->assertStatus(422)->assertJsonValidationErrors('date');

        $this->crear(['kind' => 'extra_hours', 'date' => $this->day, 'start_time' => '20:00', 'end_time' => '18:00'])
            ->assertStatus(422);

        $this->crear(['kind' => 'extra_hours', 'date' => $this->day, 'all_day' => true])
            ->assertStatus(422);

        $this->crear(['kind' => 'block', 'date' => $this->day, 'start_time' => '12:00'])
            ->assertStatus(422);
    }

    public function test_solo_quien_gestiona_horarios(): void
    {
        Sanctum::actingAs($this->usuario('maria@prueba.test', PermissionCatalog::ROLE_STAFF));

        $this->crear(['kind' => 'block', 'date' => $this->day, 'all_day' => true])->assertForbidden();
        $this->getJson("/api/v1/resources/{$this->alejandra->id}/exceptions")->assertForbidden();
    }

    public function test_no_se_borra_el_cambio_de_otra_persona(): void
    {
        $lucia = $this->makeResource($this->business, 'Lucia');
        $id = $this->postJson("/api/v1/resources/{$lucia->id}/exceptions", [
            'kind' => 'block', 'date' => $this->day, 'all_day' => true,
        ])->assertCreated()->json('id');

        $this->deleteJson("/api/v1/resources/{$this->alejandra->id}/exceptions/{$id}")->assertNotFound();
    }
}
