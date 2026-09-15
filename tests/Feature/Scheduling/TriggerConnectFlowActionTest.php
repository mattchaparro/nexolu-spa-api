<?php

namespace Tests\Feature\Scheduling;

use App\Models\Appointment;
use App\Models\AppointmentStageEvent;
use App\Models\AppointmentWorkflowStage;
use App\Services\Scheduling\Actions\StageActionContext;
use App\Services\Scheduling\Actions\StageActionResult;
use App\Services\Scheduling\Actions\TriggerConnectFlowAction;
use App\Services\WhatsApp\NexoluConnectFlows;
use App\Support\PermissionCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * La accion de etapa "Iniciar un flujo de WhatsApp": dispara
 * POST /v1/flows/trigger de Nexolu Connect con las MISMAS variables de las
 * plantillas del spa, y se degrada con motivo (no rompe la transicion)
 * cuando falta telefono, flujo o configuracion.
 */
class TriggerConnectFlowActionTest extends TestCase
{
    use RefreshDatabase, SchedulingScenario;

    protected function setUp(): void
    {
        parent::setUp();
        PermissionCatalog::sync();
        config([
            'services.comms_core.api_key' => 'test-comms-key',
            'services.comms_core.base_url' => 'http://comms.test',
        ]);
    }

    private function appointment(): Appointment
    {
        $business = $this->makeBusiness();
        $maria = $this->makeResource($business, 'Maria');
        $this->makeService($business, 60, [$maria]);

        return Appointment::create([
            'business_id' => $business->id,
            'location_id' => $business->locations()->first()->id,
            'client_name' => 'Carolina Restrepo',
            'client_phone' => '573001112233',
            'starts_at' => CarbonImmutable::parse('2026-09-17 15:00:00', 'America/Bogota'),
            'ends_at' => CarbonImmutable::parse('2026-09-17 16:00:00', 'America/Bogota'),
            'status' => 'confirmada',
        ])->refresh();
    }

    private function runAction(Appointment $appointment, array $config = ['flow' => 'post_agenda']): StageActionResult
    {
        $action = new TriggerConnectFlowAction(app(NexoluConnectFlows::class));
        $stage = new AppointmentWorkflowStage(['key' => 'confirmada', 'label' => 'Confirmada']);

        return $action->execute(new StageActionContext(
            $appointment,
            $stage,
            $config,
            null,
            AppointmentStageEvent::ACTOR_USER,
        ));
    }

    public function test_dispara_el_flujo_con_las_variables_de_la_cita(): void
    {
        Http::fake(['comms.test/*' => Http::response(['session_id' => 's1', 'status' => 'active'], 200)]);
        $appointment = $this->appointment();

        $result = $this->runAction($appointment);

        $this->assertSame('ok', $result->status);
        Http::assertSent(function ($request) use ($appointment) {
            return str_contains($request->url(), '/v1/flows/trigger')
                && $request['flow'] === 'post_agenda'
                && $request['to'] === '573001112233'
                && $request['business_id'] === (string) $appointment->business_id
                && $request['contact_name'] === 'Carolina'
                && $request['variables']['cliente'] === 'Carolina'
                && $request['variables']['fecha'] === 'jueves 17 de septiembre'
                && $request['variables']['hora'] !== '';
        });
    }

    public function test_sin_telefono_se_omite_sin_romper_la_transicion(): void
    {
        Http::fake();
        $appointment = $this->appointment();
        $appointment->update(['client_phone' => null]);

        $result = $this->runAction($appointment->refresh());

        $this->assertSame('skipped', $result->status);
        Http::assertNothingSent();
    }

    public function test_sin_flujo_configurado_se_omite(): void
    {
        Http::fake();

        $result = $this->runAction($this->appointment(), ['flow' => '  ']);

        $this->assertSame('skipped', $result->status);
        Http::assertNothingSent();
    }

    public function test_sin_connect_configurado_se_omite_con_motivo(): void
    {
        config(['services.comms_core.api_key' => null]);
        Http::fake();

        $result = $this->runAction($this->appointment());

        $this->assertSame('skipped', $result->status);
        $this->assertStringContainsString('Connect', (string) $result->detail);
        Http::assertNothingSent();
    }

    public function test_un_rechazo_de_connect_falla_sin_lanzar(): void
    {
        Http::fake(['comms.test/*' => Http::response(['detail' => "El flujo 'post_agenda' no existe."], 404)]);

        $result = $this->runAction($this->appointment());

        $this->assertSame('failed', $result->status);
    }
}
