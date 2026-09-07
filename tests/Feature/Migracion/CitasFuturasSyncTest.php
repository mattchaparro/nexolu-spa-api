<?php

namespace Tests\Feature\Migracion;

use App\Models\Appointment;
use App\Models\AppointmentItem;
use App\Models\Business;
use App\Models\Resource;
use App\Models\ResourceOccupancy;
use App\Models\Service;
use App\Services\Migration\Importadores\ImportaCitasFuturas;
use App\Services\Migration\LegacyMap;
use App\Services\Migration\Reporte;
use App\Services\Scheduling\BookingService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Scheduling\SchedulingScenario;
use Tests\TestCase;

/**
 * Lo que le pasa a una cita FUTURA cuando cambia en el sistema viejo.
 *
 * Mientras los dos sistemas conviven, la agenda de verdad sigue siendo la
 * vieja: allá cancelan, allá reagendan. Si eso no llega acá, la app nueva
 * muestra una agenda que no existe -- y peor, sigue ocupando horarios que ya
 * están libres.
 *
 * Son tres casos y los tres duelen distinto:
 *
 *  - CANCELADA allá y viva acá: quien mire la agenda prepara un servicio que
 *    nadie va a recibir, y el cupo queda muerto para otra clienta.
 *  - MOVIDA allá y quieta acá: la clienta llega a las once y en la agenda
 *    nueva dice nueve.
 *  - PASADA pero todavía agendada allá: no se puede cancelar sola. Nadie la
 *    canceló; simplemente el día pasó sin que la cerraran.
 */
class CitasFuturasSyncTest extends TestCase
{
    use RefreshDatabase, SchedulingScenario;

    private Business $business;

    private Resource $maria;

    private Resource $lucia;

    private Service $service;

    private LegacyMap $map;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(
            CarbonImmutable::now('America/Bogota')->startOfDay()
                ->next(CarbonImmutable::MONDAY)->setTime(7, 0),
        );

        $this->business = $this->makeBusiness(['slot_granularity_min' => 60, 'min_booking_notice_min' => 0]);
        $this->maria = $this->makeResource($this->business, 'Maria', '08:00:00', '20:00:00');
        $this->lucia = $this->makeResource($this->business, 'Lucia', '08:00:00', '20:00:00');
        $this->service = $this->makeService($this->business, 60, [$this->maria, $this->lucia]);

        $this->prepararLegacyFalso();

        $this->map = new LegacyMap($this->business->id);
        $this->map->anotar('service', 1, $this->service->id);
        $this->map->anotar('resource', 1, $this->maria->id);
        $this->map->anotar('resource', 2, $this->lucia->id);
    }

    /**
     * Las tres tablas que el paso consulta, con la forma del sistema viejo.
     *
     * En una base APARTE (SQLite en memoria) y no en la de pruebas: el sistema
     * viejo tiene una tabla `appointments` y la app tambien, con otra forma.
     * Compartir base las hace chocar.
     */
    private function prepararLegacyFalso(): void
    {
        config()->set('database.connections.legacy', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]);

        DB::purge('legacy');

        $schema = Schema::connection('legacy');

        $schema->create('employee_services', function ($table) {
            $table->id();
            $table->unsignedBigInteger('service_id')->nullable();
            $table->unsignedBigInteger('employee_id')->nullable();
            $table->unsignedBigInteger('client_id')->nullable();
            $table->string('client_name')->nullable();
            $table->string('client_cellphone')->nullable();
            $table->unsignedBigInteger('status_id')->default(4);
            $table->unsignedBigInteger('appointment_id')->nullable();
            $table->date('date')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        $schema->create('appointments', function ($table) {
            $table->id();
            $table->unsignedBigInteger('time_slot_id')->nullable();
        });

        $schema->create('time_slots', function ($table) {
            $table->id();
            $table->time('start_time')->nullable();
        });
    }

    /** Atajo: la conexion del sistema viejo. */
    private function legacy(string $tabla): \Illuminate\Database\Query\Builder
    {
        return DB::connection('legacy')->table($tabla);
    }

    /** Una cita agendada en el sistema viejo. */
    private function agendaEnElLegacy(int $id, string $hora, int $empleada = 1, ?string $fecha = null): void
    {
        $this->legacy('time_slots')->insert(['id' => $id, 'start_time' => $hora]);
        $this->legacy('appointments')->insert(['id' => $id, 'time_slot_id' => $id]);

        $this->legacy('employee_services')->insert([
            'id' => $id,
            'service_id' => 1,
            'employee_id' => $empleada,
            'client_name' => 'Carolina',
            'status_id' => 4,
            'appointment_id' => $id,
            'date' => $fecha ?? CarbonImmutable::now('America/Bogota')->addDay()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function correr(): Reporte
    {
        $reporte = new Reporte;

        (new ImportaCitasFuturas($this->business, $this->map, $reporte, false))->correr();

        return $reporte;
    }

    private function laCita(): ?Appointment
    {
        $id = $this->map->idNuevo('appointment', 1);

        return $id === null ? null : Appointment::withoutGlobalScope('business')->find($id);
    }

    public function test_trae_la_cita_agendada(): void
    {
        $this->agendaEnElLegacy(1, '10:00:00');

        $this->correr();

        $cita = $this->laCita();

        $this->assertNotNull($cita);
        $this->assertSame(Appointment::STATUS_PENDING, $cita->status);
        $this->assertSame('10:00', $cita->starts_at->setTimezone('America/Bogota')->format('H:i'));
    }

    public function test_cancelarla_alla_la_cancela_aca_y_libera_el_horario(): void
    {
        /*
         * El caso que el dueño preguntó. Sin esto, una cita cancelada a las
         * diez sigue ocupando el cupo y quien mire la agenda prepara un
         * servicio que nadie va a recibir.
         */
        $this->agendaEnElLegacy(1, '10:00:00');
        $this->correr();

        $cita = $this->laCita();
        $items = AppointmentItem::withoutGlobalScope('business')->where('appointment_id', $cita->id)->pluck('id');

        $this->assertGreaterThan(
            0,
            ResourceOccupancy::withoutGlobalScope('business')->whereIn('appointment_item_id', $items)->count(),
        );

        // La cancelan en el sistema viejo.
        $this->legacy('employee_services')->where('id', 1)->update(['status_id' => 6]);

        $this->correr();

        $this->assertSame('cancelled', $this->laCita()->status);

        $this->assertSame(
            0,
            ResourceOccupancy::withoutGlobalScope('business')->whereIn('appointment_item_id', $items)->count(),
            'El horario quedó bloqueado por una cita que ya nadie tiene.',
        );
    }

    public function test_borrarla_alla_tambien_la_cancela_aca(): void
    {
        // No siempre la cancelan: a veces la borran.
        $this->agendaEnElLegacy(1, '10:00:00');
        $this->correr();

        $this->legacy('employee_services')->where('id', 1)->update(['deleted_at' => now()]);

        $this->correr();

        $this->assertSame('cancelled', $this->laCita()->status);
    }

    public function test_moverla_de_hora_alla_la_mueve_aca(): void
    {
        /*
         * Sin esto la clienta llega a las once y la agenda nueva dice nueve.
         * La fila del sistema viejo es la misma, así que el paso la daba por
         * hecha y no volvía a mirarla.
         */
        $this->agendaEnElLegacy(1, '10:00:00');
        $this->correr();

        $this->legacy('time_slots')->where('id', 1)->update(['start_time' => '15:00:00']);

        $this->correr();

        $this->assertSame(
            '15:00',
            $this->laCita()->fresh()->starts_at->setTimezone('America/Bogota')->format('H:i'),
        );
    }

    public function test_cambiarle_la_persona_alla_la_cambia_aca(): void
    {
        $this->agendaEnElLegacy(1, '10:00:00');
        $this->correr();

        $this->legacy('employee_services')->where('id', 1)->update(['employee_id' => 2]);

        $this->correr();

        $this->assertSame(
            $this->lucia->id,
            (int) AppointmentItem::withoutGlobalScope('business')
                ->where('appointment_id', $this->laCita()->id)->value('resource_id'),
        );
    }

    public function test_una_cita_de_ayer_que_sigue_agendada_alla_no_se_cancela_sola(): void
    {
        /*
         * Nadie la canceló: el día pasó sin que la cerraran, que en un local
         * ocupado ocurre todo el tiempo. Cancelarla acá sería inventarse una
         * decisión que nadie tomó, y borraría de la agenda algo que sí pasó.
         */
        $this->agendaEnElLegacy(1, '10:00:00');
        $this->correr();

        // Pasa el tiempo: la cita queda en el pasado, pero allá sigue agendada.
        $this->travel(3)->days();

        $this->correr();

        $this->assertSame(Appointment::STATUS_PENDING, $this->laCita()->fresh()->status);
    }

    public function test_una_cita_ya_cobrada_aca_no_la_toca(): void
    {
        // Si ya se cobró, moverla o cancelarla sería reescribir una venta.
        $this->agendaEnElLegacy(1, '10:00:00');
        $this->correr();

        $cita = $this->laCita();
        $cita->update(['status' => 'completed', 'checked_out_at' => now(), 'total' => 50000]);

        $this->legacy('employee_services')->where('id', 1)->update(['status_id' => 6]);

        $this->correr();

        $this->assertSame('completed', $this->laCita()->fresh()->status);
    }
}
