<?php

namespace Tests\Feature\Migracion;

use App\Models\Appointment;
use App\Models\AppointmentItem;
use App\Models\Business;
use App\Models\PaymentMethod;
use App\Models\Resource;
use App\Models\ResourceOccupancy;
use App\Models\Service;
use App\Services\Migration\Importadores\ImportaHistorial;
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
 * La sincronización que corre cada cinco minutos mientras los dos sistemas
 * conviven.
 *
 * Lo que se defiende acá es el peor error posible de esta migración: CONTAR
 * UNA VENTA DOS VECES.
 *
 * Pasa así. Una clienta llama a las diez y le agendan para las once en el
 * sistema viejo. La sincronización trae esa cita como pendiente. A las doce el
 * local la cobra allá, y la misma fila del sistema viejo pasa de "agendada" a
 * "finalizada". Si el paso del historial la tratara como una atención nueva,
 * crearía una SEGUNDA cita: el reporte del día contaría 100.000 donde entraron
 * 50.000, y la clienta sumaría dos sellos por una visita.
 *
 * De noche esto casi no pasaba -- la cita nacía ya cobrada. Sincronizando cada
 * cinco minutos pasa todos los días.
 */
class SincronizacionVivaTest extends TestCase
{
    use RefreshDatabase, SchedulingScenario;

    private Business $business;

    private Resource $maria;

    private Service $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(
            CarbonImmutable::now('America/Bogota')->startOfDay()
                ->next(CarbonImmutable::WEDNESDAY)->setTime(8, 0),
        );

        $this->business = $this->makeBusiness(['slot_granularity_min' => 60, 'min_booking_notice_min' => 0]);
        $this->maria = $this->makeResource($this->business, 'Maria', '08:00:00', '20:00:00');
        $this->service = $this->makeService($this->business, 60, [$this->maria]);
        $this->service->update(['name' => 'Manicure', 'price' => 50000]);

        PaymentMethod::create([
            'business_id' => $this->business->id, 'name' => 'Efectivo', 'counts_as_cash' => true,
        ]);

        $this->prepararLegacyFalso();
    }

    /**
     * Una tabla `employee_services` con la forma del sistema viejo.
     *
     * La conexión `legacy` se apunta a la misma base de pruebas: no hace falta
     * una segunda base para comprobar la lógica, y sí hace falta que la forma
     * de la fila sea la de allá.
     */
    private function prepararLegacyFalso(): void
    {
        config()->set('database.connections.legacy', config('database.connections.'.config('database.default')));
        DB::purge('legacy');

        Schema::dropIfExists('employee_services');

        Schema::create('employee_services', function ($table) {
            $table->id();
            $table->double('price')->default(0);
            $table->integer('final_price')->default(0);
            $table->double('commission')->default(0);
            $table->double('commission_percentage')->default(0);
            $table->boolean('discount_applied')->default(false);
            $table->integer('discount_percentage')->nullable();
            $table->unsignedBigInteger('loyalty_card_reward_id')->nullable();
            $table->unsignedBigInteger('promotion_id')->nullable();
            $table->string('client_name')->nullable();
            $table->string('client_cellphone')->nullable();
            $table->unsignedBigInteger('service_id')->nullable();
            $table->unsignedBigInteger('employee_id')->nullable();
            $table->unsignedBigInteger('client_id')->nullable();
            $table->unsignedBigInteger('payment_method_id')->nullable();
            $table->unsignedBigInteger('status_id')->default(4);
            $table->unsignedBigInteger('appointment_id')->nullable();
            $table->date('date')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /** La cita que ya existe acá, traída cuando estaba agendada. */
    private function citaPendiente(): Appointment
    {
        return app(BookingService::class)->book(
            $this->business,
            [[
                'service_id' => $this->service->id,
                'resource_id' => $this->maria->id,
                'starts_at' => CarbonImmutable::now('America/Bogota')->setTime(10, 0),
            ]],
            null,
            'Carolina',
            '3001234567',
        );
    }

    /** La misma fila del sistema viejo, ya cobrada. */
    private function cobradaEnElLegacy(int $legacyId = 999): void
    {
        DB::table('employee_services')->insert([
            'id' => $legacyId,
            'price' => 50000,
            'final_price' => 45000,
            'commission' => 25000,
            'commission_percentage' => 50,
            'discount_applied' => true,
            'discount_percentage' => 10,
            'service_id' => 1,
            'employee_id' => 1,
            'status_id' => 2,
            'date' => CarbonImmutable::now('America/Bogota')->toDateString(),
            'started_at' => CarbonImmutable::now('America/Bogota')->setTime(10, 5),
            'finished_at' => CarbonImmutable::now('America/Bogota')->setTime(11, 10),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function correrHistorial(LegacyMap $map): Reporte
    {
        $reporte = new Reporte;

        (new ImportaHistorial($this->business, $map, $reporte, false))->correr();

        return $reporte;
    }

    public function test_cobrar_una_cita_agendada_no_crea_una_segunda(): void
    {
        $cita = $this->citaPendiente();
        $this->cobradaEnElLegacy();

        $map = new LegacyMap($this->business->id);
        $map->anotar('appointment', 999, $cita->id);
        $map->anotar('service', 1, $this->service->id);
        $map->anotar('resource', 1, $this->maria->id);

        $antes = Appointment::withoutGlobalScope('business')->count();

        $this->correrHistorial($map);

        $this->assertSame(
            $antes,
            Appointment::withoutGlobalScope('business')->count(),
            'Se creó una segunda cita: la venta se contaría dos veces.',
        );
    }

    public function test_la_cita_queda_terminada_con_lo_que_de_verdad_se_pago(): void
    {
        $cita = $this->citaPendiente();
        $this->cobradaEnElLegacy();

        $map = new LegacyMap($this->business->id);
        $map->anotar('appointment', 999, $cita->id);
        $map->anotar('service', 1, $this->service->id);
        $map->anotar('resource', 1, $this->maria->id);

        $this->correrHistorial($map);

        $fresca = $cita->fresh();

        $this->assertSame('completed', $fresca->status);
        $this->assertEqualsWithDelta(45000, (float) $fresca->total, 0.01);
        $this->assertEqualsWithDelta(50000, (float) $fresca->subtotal, 0.01);
        $this->assertEqualsWithDelta(5000, (float) $fresca->discount_amount, 0.01);
        $this->assertEqualsWithDelta(25000, (float) $fresca->commission_total, 0.01);
        $this->assertNotNull($fresca->checked_out_at);
    }

    public function test_al_terminarla_se_libera_el_horario(): void
    {
        /*
         * Una cita del pasado no tiene por qué seguir bloqueando un cupo. Si
         * la ocupación no se soltara, el horario de las diez quedaría muerto
         * para siempre y nadie sabría por qué.
         */
        $cita = $this->citaPendiente();
        $items = AppointmentItem::withoutGlobalScope('business')->where('appointment_id', $cita->id)->pluck('id');

        $this->assertGreaterThan(
            0,
            ResourceOccupancy::withoutGlobalScope('business')->whereIn('appointment_item_id', $items)->count(),
        );

        $this->cobradaEnElLegacy();

        $map = new LegacyMap($this->business->id);
        $map->anotar('appointment', 999, $cita->id);
        $map->anotar('service', 1, $this->service->id);
        $map->anotar('resource', 1, $this->maria->id);

        $this->correrHistorial($map);

        $this->assertSame(
            0,
            ResourceOccupancy::withoutGlobalScope('business')->whereIn('appointment_item_id', $items)->count(),
        );
    }

    public function test_una_cita_ya_terminada_no_se_vuelve_a_escribir(): void
    {
        /*
         * La sincronización pasa cada cinco minutos por la misma fila. Si la
         * reescribiera cada vez, cualquier ajuste hecho a mano acá -- una
         * propina, una corrección del cobro -- se perdería en menos de cinco
         * minutos y nadie entendería por qué.
         */
        $cita = $this->citaPendiente();
        $this->cobradaEnElLegacy();

        $map = new LegacyMap($this->business->id);
        $map->anotar('appointment', 999, $cita->id);
        $map->anotar('service', 1, $this->service->id);
        $map->anotar('resource', 1, $this->maria->id);

        $this->correrHistorial($map);

        $cita->fresh()->update(['total' => 60000]);

        $this->correrHistorial($map);

        $this->assertEqualsWithDelta(60000, (float) $cita->fresh()->total, 0.01);
    }
}
