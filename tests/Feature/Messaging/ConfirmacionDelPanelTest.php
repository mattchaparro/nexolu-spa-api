<?php

namespace Tests\Feature\Messaging;

use App\Models\Appointment;
use App\Models\AppointmentWorkflow;
use App\Models\Business;
use App\Models\Message;
use App\Models\Resource;
use App\Models\Service;
use App\Models\User;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Scheduling\SchedulingScenario;
use Tests\TestCase;

/**
 * Agendar desde el panel le avisa a la clienta.
 *
 * Nunca lo había hecho: Alejandro agendó un turno desde la aplicación nueva
 * y a la clienta no le llegó nada. La confirmación colgaba de mover la cita
 * a la etapa «Confirmada», y crear una cita no la mueve de etapa.
 */
class ConfirmacionDelPanelTest extends TestCase
{
    use RefreshDatabase, SchedulingScenario;

    private Business $business;

    private User $admin;

    private Resource $maria;

    private Service $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeClockBeforeWednesday();

        PermissionCatalog::sync();
        config()->set('services.comms_core.api_key', 'llave-comms');
        config()->set('services.comms_core.base_url', 'http://comms.test');
        Http::fake([
            'comms.test/*' => Http::response(['results' => [['channel' => 'whatsapp', 'status' => 'sent']]]),
        ]);

        $this->business = $this->makeBusiness(['slot_granularity_min' => 60, 'min_booking_notice_min' => 0]);
        $this->maria = $this->makeResource($this->business, 'Maria');
        $this->service = $this->makeService($this->business, 60, [$this->maria], name: 'Semipermanente');

        $this->admin = User::create([
            'business_id' => $this->business->id,
            'name' => 'Ana',
            'email' => 'ana@prueba.test',
            'password' => Hash::make('password123'),
            'is_active' => true,
        ]);
        PermissionCatalog::applyRole($this->admin, PermissionCatalog::ROLE_ADMIN);
        Sanctum::actingAs($this->admin->fresh());
    }

    /** @param array<string, mixed> $extra */
    private function agendar(array $extra = []): void
    {
        $this->postJson('/api/v1/appointments', [
            'service_id' => $this->service->id,
            'resource_id' => $this->maria->id,
            'starts_at' => $this->wednesday()->format('Y-m-d').' 11:00:00',
            'client_name' => 'Carolina',
            ...$extra,
        ])->assertCreated();
    }

    private function confirmaciones(): int
    {
        return Message::withoutGlobalScopes()->where('kind', Message::KIND_CONFIRMATION)->count();
    }

    public function test_agendar_desde_el_panel_le_confirma_a_la_clienta(): void
    {
        $this->agendar(['client_phone' => '3001112233']);

        $this->assertSame(1, $this->confirmaciones());

        $mensaje = Message::withoutGlobalScopes()->where('kind', Message::KIND_CONFIRMATION)->sole();
        $this->assertStringContainsString('Semipermanente', (string) $mensaje->body);
        // Con la plantilla puesta: fuera de la ventana de 24 horas es lo
        // único que Meta entrega.
        $this->assertSame('confirmacion_cita', $mensaje->template_name);
    }

    public function test_sin_telefono_no_hay_a_quien_confirmar_y_la_cita_igual_queda(): void
    {
        // Por teléfono o en el mostrador, sin número anotado: no es un error.
        $this->agendar();

        $this->assertSame(0, $this->confirmaciones());
        $this->assertSame(1, Appointment::withoutGlobalScopes()->count());
    }

    public function test_la_confirmacion_no_le_tapa_el_paso_a_la_cancelacion(): void
    {
        /*
         * El índice único deja un mensaje por (cita, tipo, destinatario). Si
         * la confirmación compartiera el tipo de los avisos de etapa,
         * cancelar esa misma cita después se descartaría como repetido.
         */
        $this->agendar(['client_phone' => '3001112233']);
        $cita = Appointment::withoutGlobalScopes()->sole();

        $this->postJson("/api/v1/appointments/{$cita->id}/cancel", ['reason' => 'Se enfermó Maria'])
            ->assertOk();

        $this->assertSame(1, $this->confirmaciones());
        $this->assertNotSame(
            Message::KIND_CONFIRMATION,
            Message::KIND_STAGE,
            'La confirmación necesita su propio tipo para no bloquear los avisos de etapa.',
        );
    }

    public function test_si_el_flujo_confirma_en_su_etapa_no_se_confirma_dos_veces(): void
    {
        /*
         * En el flujo estándar agendar deja la cita tentativa y el salón la
         * confirma después: la confirmación sale ahí. Mandarla también al
         * crear le daría dos a la misma clienta.
         */
        $flujo = AppointmentWorkflow::create(['name' => 'Estándar', 'is_default' => false, 'is_active' => true]);
        $flujo->stages()->create([
            'key' => 'agendada', 'label' => 'Agendada', 'sort_order' => 1,
            'maps_to_status' => Appointment::STATUS_PENDING, 'is_initial' => true, 'actions' => [],
        ]);
        $flujo->stages()->create([
            'key' => 'confirmada', 'label' => 'Confirmada', 'sort_order' => 2,
            'maps_to_status' => Appointment::STATUS_CONFIRMED, 'is_initial' => false, 'actions' => [],
        ]);
        $this->business->update(['appointment_workflow_id' => $flujo->id]);

        $this->agendar(['client_phone' => '3001112233']);

        $this->assertSame(0, $this->confirmaciones());
    }
}
