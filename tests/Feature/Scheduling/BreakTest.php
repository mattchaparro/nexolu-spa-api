<?php

namespace Tests\Feature\Scheduling;

use App\Models\Business;
use App\Models\Resource;
use App\Models\ResourceBreak;
use App\Models\ScheduleException;
use App\Models\Service;
use App\Models\User;
use App\Services\Scheduling\AvailabilityService;
use App\Services\Scheduling\BookingService;
use App\Services\Scheduling\Exceptions\OutsideWorkingHoursException;
use App\Support\PermissionCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Almuerzos y descansos.
 *
 * Lo que estas pruebas defienden es que el almuerzo no se negocia: no aparece
 * como hueco, ninguna hora extra lo reabre, y no se puede agendar encima ni
 * mandando la hora a mano ni arrastrando en el calendario.
 */
class BreakTest extends TestCase
{
    use RefreshDatabase, SchedulingScenario;

    private Business $business;

    private User $admin;

    private Resource $maria;

    private Service $service;

    protected function setUp(): void
    {
        parent::setUp();

        PermissionCatalog::sync();

        $this->business = $this->makeBusiness(['slot_granularity_min' => 30, 'min_booking_notice_min' => 0]);

        $this->admin = User::create([
            'business_id' => $this->business->id, 'name' => 'Ana',
            'email' => 'ana@prueba.test', 'password' => Hash::make('password123'), 'is_active' => true,
        ]);
        PermissionCatalog::applyRole($this->admin, PermissionCatalog::ROLE_ADMIN);

        // Miercoles, 09:00 a 17:00.
        $this->maria = $this->makeResource($this->business, 'Maria', '09:00:00', '17:00:00', [3]);
        $this->service = $this->makeService($this->business, 60, [$this->maria]);

        Sanctum::actingAs($this->admin->fresh());
    }

    private function almuerzo(array $overrides = []): ResourceBreak
    {
        return ResourceBreak::create(array_merge([
            'business_id' => $this->business->id,
            'resource_id' => $this->maria->id,
            'weekday' => null,
            'start_time' => '13:00:00',
            'end_time' => '14:00:00',
            'label' => 'Almuerzo',
            'effective_from' => '2020-01-01',
            'is_active' => true,
        ], $overrides));
    }

    /** @return list<string> */
    private function horas(): array
    {
        return $this->startTimes(app(AvailabilityService::class)->slotsForService(
            $this->business,
            $this->service,
            $this->wednesday(),
            now: $this->wednesday()->subDays(3),
        ));
    }

    /*
    |--------------------------------------------------------------------------
    | El hueco desaparece
    |--------------------------------------------------------------------------
    */

    public function test_sin_descanso_el_dia_esta_entero(): void
    {
        $this->assertContains('13:00', $this->horas());
        $this->assertContains('12:30', $this->horas());
    }

    public function test_el_almuerzo_parte_el_dia_en_dos(): void
    {
        $this->almuerzo();

        $horas = $this->horas();

        // Un servicio de 60 min no cabe entre las 12:30 y las 13:00.
        $this->assertNotContains('12:30', $horas);
        $this->assertNotContains('13:00', $horas);
        $this->assertNotContains('13:30', $horas);

        // Antes y despues, normal.
        $this->assertContains('12:00', $horas);
        $this->assertContains('14:00', $horas);
    }

    public function test_el_descanso_del_negocio_aplica_a_todas(): void
    {
        // El local que cierra a mediodia: una fila, no una por profesional.
        $lucia = $this->makeResource($this->business, 'Lucia', '09:00:00', '17:00:00', [3]);
        $this->service->resources()->attach($lucia->id);

        $this->almuerzo(['resource_id' => null]);

        $porRecurso = collect(app(AvailabilityService::class)->slotsForService(
            $this->business, $this->service, $this->wednesday(), now: $this->wednesday()->subDays(3),
        ))->groupBy('resource_id');

        foreach ($porRecurso as $slots) {
            $horas = $this->startTimes($slots->all());
            $this->assertNotContains('13:00', $horas);
        }
    }

    public function test_un_descanso_de_un_solo_dia_no_toca_los_demas(): void
    {
        // Solo los lunes. El miercoles no cambia nada.
        $this->almuerzo(['weekday' => 1]);

        $this->assertContains('13:00', $this->horas());
    }

    public function test_un_descanso_vencido_deja_de_aplicar(): void
    {
        $this->almuerzo(['effective_to' => $this->wednesday()->subDay()->toDateString()]);

        $this->assertContains('13:00', $this->horas());
    }

    public function test_un_descanso_desactivado_no_aplica(): void
    {
        $this->almuerzo(['is_active' => false]);

        $this->assertContains('13:00', $this->horas());
    }

    /*
    |--------------------------------------------------------------------------
    | No se puede pasar por encima
    |--------------------------------------------------------------------------
    */

    public function test_las_horas_extra_no_reabren_el_almuerzo(): void
    {
        $this->almuerzo();

        // Alguien intenta "abrir" el mediodia con una excepcion de horas
        // extra. No debe funcionar: la resta corre despues de la suma.
        ScheduleException::create([
            'business_id' => $this->business->id,
            'resource_id' => $this->maria->id,
            'starts_at' => $this->wednesday()->setTime(12, 0)->utc(),
            'ends_at' => $this->wednesday()->setTime(15, 0)->utc(),
            'kind' => ScheduleException::KIND_EXTRA_HOURS,
            'reason' => 'Turno extra',
        ]);

        $this->assertNotContains('13:00', $this->horas());
    }

    public function test_agendar_encima_del_almuerzo_se_rechaza(): void
    {
        $this->almuerzo();

        $this->postJson('/api/v1/appointments', [
            'service_id' => $this->service->id,
            'resource_id' => $this->maria->id,
            'starts_at' => $this->wednesday()->format('Y-m-d').' 13:00:00',
            'client_name' => 'Quien sea',
        ])->assertStatus(422);
    }

    public function test_el_buffer_tampoco_puede_meterse_en_el_almuerzo(): void
    {
        $this->almuerzo();
        // Servicio de 12:00 a 13:00 con 15 min de limpieza despues: la
        // profesional seguiria trabajando a las 13:05.
        $this->service->update(['buffer_after_min' => 15]);

        $this->postJson('/api/v1/appointments', [
            'service_id' => $this->service->id,
            'resource_id' => $this->maria->id,
            'starts_at' => $this->wednesday()->format('Y-m-d').' 12:00:00',
            'client_name' => 'Quien sea',
        ])->assertStatus(422);
    }

    public function test_arrastrar_una_cita_al_almuerzo_se_rechaza(): void
    {
        $this->almuerzo();

        $cita = $this->postJson('/api/v1/appointments', [
            'service_id' => $this->service->id,
            'resource_id' => $this->maria->id,
            'starts_at' => $this->wednesday()->format('Y-m-d').' 10:00:00',
            'client_name' => 'Carolina',
        ])->assertCreated()->json('id');

        // Soltarla encima del almuerzo con el raton entra por reschedule.
        $this->patchJson("/api/v1/appointments/{$cita}/reschedule", [
            'starts_at' => $this->wednesday()->format('Y-m-d').' 13:00:00',
        ])->assertStatus(422);

        // Y la cita sigue donde estaba, no a medio mover.
        $this->assertDatabaseHas('appointments', [
            'id' => $cita,
            'starts_at' => $this->wednesday()->setTime(10, 0)->utc()->format('Y-m-d H:i:s'),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | La jornada tambien es una frontera
    |--------------------------------------------------------------------------
    */

    public function test_agendar_fuera_de_la_jornada_se_rechaza(): void
    {
        // Las 3 de la mañana. Antes esto se aceptaba: la lista de huecos no lo
        // ofrecia, pero la API tomaba cualquier hora.
        $this->postJson('/api/v1/appointments', [
            'service_id' => $this->service->id,
            'resource_id' => $this->maria->id,
            'starts_at' => $this->wednesday()->format('Y-m-d').' 03:00:00',
            'client_name' => 'Quien sea',
        ])->assertStatus(422);
    }

    public function test_agendar_un_dia_que_no_trabaja_se_rechaza(): void
    {
        // Maria solo trabaja miercoles.
        $this->postJson('/api/v1/appointments', [
            'service_id' => $this->service->id,
            'resource_id' => $this->maria->id,
            'starts_at' => $this->wednesday()->addDay()->format('Y-m-d').' 10:00:00',
            'client_name' => 'Quien sea',
        ])->assertStatus(422);
    }

    public function test_el_servicio_sin_cita_si_puede_caer_en_el_almuerzo(): void
    {
        $this->almuerzo();

        // No agenda: deja constancia de algo que ya pasó. Si un cliente llegó
        // a la una y Maria la atendió, negarse a registrarlo le quitaria su
        // comision y descuadraria el cierre.
        $this->postJson('/api/v1/walk-in', [
            'service_id' => $this->service->id,
            'resource_id' => $this->maria->id,
            'started_at' => $this->wednesday()->subWeek()->format('Y-m-d').' 13:00:00',
            'client_name' => 'Llegó sin avisar',
        ])->assertCreated();
    }

    /*
    |--------------------------------------------------------------------------
    | La pantalla
    |--------------------------------------------------------------------------
    */

    public function test_la_rejilla_del_calendario_muestra_el_hueco_partido(): void
    {
        $this->almuerzo();

        $ventanas = $this->getJson('/api/v1/agenda?from='.$this->wednesday()->format('Y-m-d'))
            ->assertOk()
            ->json('days.0.resources.0.windows');

        $this->assertSame(
            [['start' => '09:00', 'end' => '13:00'], ['start' => '14:00', 'end' => '17:00']],
            $ventanas,
        );
    }

    public function test_el_crud_de_descansos(): void
    {
        $creado = $this->postJson('/api/v1/breaks', [
            'resource_id' => $this->maria->id,
            'start_time' => '13:00',
            'end_time' => '14:00',
            'label' => 'Almuerzo',
        ])->assertCreated()->json('id');

        $this->assertNotContains('13:00', $this->horas());

        $this->putJson("/api/v1/breaks/{$creado}", [
            'resource_id' => $this->maria->id,
            'start_time' => '12:00',
            'end_time' => '13:00',
        ])->assertOk();

        $horas = $this->horas();
        $this->assertNotContains('12:00', $horas);
        $this->assertContains('14:00', $horas);

        $this->deleteJson("/api/v1/breaks/{$creado}")->assertOk();
        $this->assertContains('13:00', $this->horas());
    }

    public function test_el_listado_de_una_profesional_incluye_los_del_negocio(): void
    {
        $this->almuerzo(['resource_id' => null, 'label' => 'Cierre de mediodía']);
        $this->almuerzo(['start_time' => '16:00:00', 'end_time' => '16:15:00', 'label' => 'Break']);

        $rows = $this->getJson("/api/v1/breaks?resource_id={$this->maria->id}")->assertOk()->json();

        // Si el del negocio no apareciera, la pantalla diria que Maria solo
        // tiene su break de las cuatro y el motor la bloquearia a mediodia
        // igual, sin explicacion.
        $this->assertEqualsCanonicalizing(
            ['Cierre de mediodía', 'Break'],
            array_column($rows, 'label'),
        );
        $this->assertSame('business', $rows[0]['scope']);
    }

    public function test_no_se_le_pone_un_descanso_a_alguien_de_otro_negocio(): void
    {
        $otro = $this->makeBusiness();

        $ajenaId = \Illuminate\Support\Facades\DB::table('resources')->insertGetId([
            'business_id' => $otro->id, 'type' => Resource::TYPE_STAFF,
            'name' => 'Ajena', 'is_active' => true, 'sort_order' => 0,
            'payroll_mode' => 'commission', 'base_amount' => 0, 'base_period' => 'month',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->postJson('/api/v1/breaks', [
            'resource_id' => $ajenaId,
            'start_time' => '13:00',
            'end_time' => '14:00',
        ])->assertNotFound();
    }

    public function test_una_profesional_no_edita_los_descansos(): void
    {
        $staff = User::create([
            'business_id' => $this->business->id, 'name' => 'Maria',
            'email' => 'maria@prueba.test', 'password' => Hash::make('password123'), 'is_active' => true,
        ]);
        PermissionCatalog::applyRole($staff, PermissionCatalog::ROLE_STAFF);

        Sanctum::actingAs($staff->fresh());

        // Puede verlos -- es su jornada -- pero no moverse el almuerzo sola.
        $this->getJson('/api/v1/breaks')->assertOk();
        $this->postJson('/api/v1/breaks', [
            'start_time' => '13:00', 'end_time' => '14:00',
        ])->assertForbidden();
    }

    /*
    |--------------------------------------------------------------------------
    | El servicio, directo
    |--------------------------------------------------------------------------
    */

    public function test_el_servicio_lanza_su_propia_excepcion(): void
    {
        $this->almuerzo();

        $this->expectException(OutsideWorkingHoursException::class);

        app(BookingService::class)->book(
            $this->business,
            [[
                'service_id' => $this->service->id,
                'resource_id' => $this->maria->id,
                'starts_at' => CarbonImmutable::parse(
                    $this->wednesday()->format('Y-m-d').' 13:00:00',
                    $this->business->businessTimezone(),
                ),
            ]],
            null,
            'Quien sea',
        );
    }
}
