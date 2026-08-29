<?php

namespace Tests\Feature\Scheduling;

use App\Models\Appointment;
use App\Models\AppointmentStageEvent;
use App\Models\AppointmentWorkflow;
use App\Models\AppointmentWorkflowStage;
use App\Models\Business;
use App\Models\Client;
use App\Models\ClientPenalty;
use App\Models\PaymentMethod;
use App\Models\Resource;
use App\Models\ResourceOccupancy;
use App\Models\Service;
use App\Models\User;
use App\Services\Messaging\Contracts\MessagingChannel;
use App\Support\PermissionCatalog;
use App\Support\Scheduling\DefaultWorkflow;
use App\Support\Scheduling\StageActionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * La maquina de estados de una cita y lo que dispara cada etapa.
 *
 * Dos cosas se defienden aca: que ningun salto raro deje plata mal contada, y
 * que una automatizacion que falla no atasque el mostrador -- salvo cuando lo
 * que falla es plata, y ahi si tiene que frenar todo.
 */
class StageWorkflowTest extends TestCase
{
    use RefreshDatabase, SchedulingScenario;

    private Business $business;

    private User $admin;

    private Resource $maria;

    private Service $service;

    private AppointmentWorkflow $workflow;

    private PaymentMethod $efectivo;

    protected function setUp(): void
    {
        parent::setUp();

        PermissionCatalog::sync();

        $this->workflow = DefaultWorkflow::sync();

        $this->business = $this->makeBusiness();
        $this->business->update(['appointment_workflow_id' => $this->workflow->id]);

        $this->admin = User::create([
            'business_id' => $this->business->id, 'name' => 'Ana',
            'email' => 'ana@prueba.test', 'password' => Hash::make('password123'), 'is_active' => true,
        ]);
        PermissionCatalog::applyRole($this->admin, PermissionCatalog::ROLE_ADMIN);

        $this->maria = $this->makeResource($this->business, 'Maria', '00:00:00', '23:59:00', [1, 2, 3, 4, 5, 6, 7]);
        $this->service = $this->makeService($this->business, 60, [$this->maria]);
        $this->service->update(['price' => 50000, 'commission_rate' => 0.40]);

        $this->efectivo = PaymentMethod::create([
            'business_id' => $this->business->id, 'name' => 'Efectivo',
            'counts_as_cash' => true, 'is_active' => true, 'sort_order' => 1,
        ]);

        Sanctum::actingAs($this->admin->fresh());
    }

    private function stage(string $key): AppointmentWorkflowStage
    {
        return $this->workflow->stages->firstWhere('key', $key);
    }

    private function agendar(?Client $client = null): Appointment
    {
        $id = $this->postJson('/api/v1/appointments', [
            'service_id' => $this->service->id,
            'resource_id' => $this->maria->id,
            'starts_at' => $this->wednesday()->format('Y-m-d').' 10:00:00',
            'client_id' => $client?->id,
            'client_name' => $client?->name ?? 'Carolina',
            'client_phone' => $client ? null : '+573001112233',
        ])->assertCreated()->json('id');

        return Appointment::withoutGlobalScope('business')->findOrFail($id);
    }

    /** Un canal que dice que sí y anota a quién le escribió. */
    private function canalQueFunciona(): object
    {
        $fake = new class implements MessagingChannel
        {
            /** @var list<array{to:string, body:string}> */
            public array $sent = [];

            public function isConfigured(): bool
            {
                return true;
            }

            public function sendText(string $to, string $body, ?int $businessId = null, string $type = 'generico'): bool
            {
                $this->sent[] = ['to' => $to, 'body' => $body];

                return true;
            }

            public function sendTemplate(string $to, string $name, string $languageCode, array $components = [], ?int $businessId = null, string $type = 'generico'): bool
            {
                return true;
            }

            public function sendDocument(string $to, string $documentUrl, string $filename, string $caption = '', ?int $businessId = null, string $type = 'generico'): bool
            {
                return true;
            }

            public function sendFlow(string $to, string $flowId, string $screen, string $bodyText, string $cta, array $data, string $flowToken, ?int $businessId = null, string $type = 'generico'): bool
            {
                return true;
            }

            public function markAsReadWithTyping(string $to, string $messageId): bool
            {
                return true;
            }
        };

        $this->app->instance(MessagingChannel::class, $fake);

        return $fake;
    }

    /*
    |--------------------------------------------------------------------------
    | El flujo
    |--------------------------------------------------------------------------
    */

    public function test_una_cita_nace_en_la_etapa_inicial_del_negocio(): void
    {
        $cita = $this->agendar();

        $this->assertSame($this->stage('agendada')->id, $cita->stage_id);
        $this->assertSame(Appointment::STATUS_PENDING, $cita->status);
    }

    public function test_las_opciones_vienen_con_el_vocabulario_del_negocio(): void
    {
        $cita = $this->agendar();

        $opciones = $this->getJson("/api/v1/appointments/{$cita->id}/stages")->assertOk();

        $this->assertSame('Sin confirmar', $opciones->json('current.status_label'));

        $etiquetas = array_column($opciones->json('options'), 'label');

        // Los nombres del negocio, no los internos.
        $this->assertContains('Confirmada', $etiquetas);
        $this->assertContains('En la silla', $etiquetas);
        $this->assertContains('No asistió', $etiquetas);
        // Y no se ofrece quedarse donde ya está.
        $this->assertNotContains('Agendada', $etiquetas);
    }

    public function test_mover_de_etapa_cambia_el_estado_nucleo(): void
    {
        $cita = $this->agendar();

        $this->postJson("/api/v1/appointments/{$cita->id}/stage", [
            'stage_id' => $this->stage('confirmada')->id,
        ])->assertOk()->assertJsonPath('appointment.status', Appointment::STATUS_CONFIRMED);

        $cita->refresh();
        $this->assertSame($this->stage('confirmada')->id, $cita->stage_id);
        // El sello de tiempo lo pone la máquina, no cada llamador.
        $this->assertNotNull($cita->confirmed_at);
    }

    public function test_un_salto_ilegal_se_rechaza_con_su_motivo(): void
    {
        $cita = $this->agendar();

        $this->postJson("/api/v1/appointments/{$cita->id}/stage", [
            'stage_id' => $this->stage('cancelada')->id,
        ])->assertOk();

        // Cancelar liberó el horario; revivirla pondría dos citas encima.
        $this->postJson("/api/v1/appointments/{$cita->id}/stage", [
            'stage_id' => $this->stage('confirmada')->id,
        ])->assertStatus(422)->assertJsonFragment([
            'message' => 'Esta cita está cancelada y su horario quedó libre. Si la clienta vuelve, agéndala de nuevo.',
        ]);

        $this->assertSame(Appointment::STATUS_CANCELLED, $cita->fresh()->status);
    }

    public function test_no_se_mueve_a_una_etapa_de_otro_flujo(): void
    {
        $otroFlujo = AppointmentWorkflow::create(['name' => 'Otro', 'is_default' => false, 'is_active' => true]);
        $ajena = AppointmentWorkflowStage::create([
            'workflow_id' => $otroFlujo->id, 'key' => 'x', 'label' => 'Ajena',
            'maps_to_status' => Appointment::STATUS_CONFIRMED, 'sort_order' => 0,
        ]);

        $cita = $this->agendar();

        // Pondría la cita en un estado que su negocio no conoce.
        $this->postJson("/api/v1/appointments/{$cita->id}/stage", ['stage_id' => $ajena->id])
            ->assertNotFound();
    }

    public function test_sin_flujo_configurado_se_ofrecen_los_estados_nucleo(): void
    {
        $this->business->update(['appointment_workflow_id' => null]);

        $cita = $this->agendar();

        $opciones = $this->getJson("/api/v1/appointments/{$cita->id}/stages")->assertOk();
        $this->assertContains('Confirmada', array_column($opciones->json('options'), 'label'));

        // Y se puede mover mandando el estado directo: el negocio tiene que
        // poder confirmar aunque no haya configurado nada.
        $this->postJson("/api/v1/appointments/{$cita->id}/stage", [
            'status' => Appointment::STATUS_CONFIRMED,
        ])->assertOk();

        $this->assertSame(Appointment::STATUS_CONFIRMED, $cita->fresh()->status);
    }

    /*
    |--------------------------------------------------------------------------
    | Las acciones
    |--------------------------------------------------------------------------
    */

    public function test_confirmar_le_manda_el_mensaje_a_la_clienta(): void
    {
        $canal = $this->canalQueFunciona();

        $cita = $this->agendar();

        $respuesta = $this->postJson("/api/v1/appointments/{$cita->id}/stage", [
            'stage_id' => $this->stage('confirmada')->id,
        ])->assertOk();

        $this->assertCount(1, $canal->sent);
        // La plantilla se rellenó con los datos de la cita.
        $this->assertStringContainsString('Carolina', $canal->sent[0]['body']);
        $this->assertStringContainsString('10:00 am', $canal->sent[0]['body']);
        $this->assertStringContainsString('Maria', $canal->sent[0]['body']);

        // Y quien movió la cita se entera de que salió, en la misma respuesta.
        $this->assertSame('ok', $respuesta->json('actions.0.status'));
    }

    public function test_sin_telefono_se_omite_el_mensaje_pero_la_cita_avanza(): void
    {
        $this->canalQueFunciona();

        $cita = $this->agendar();
        // El teléfono también en la ficha: agendar por nombre crea la clienta,
        // y la acción cae de vuelta a su número si la cita no lo trae.
        $cita->update(['client_phone' => null]);
        $cita->client?->update(['phone' => null]);

        $respuesta = $this->postJson("/api/v1/appointments/{$cita->id}/stage", [
            'stage_id' => $this->stage('confirmada')->id,
        ])->assertOk();

        $this->assertSame('skipped', $respuesta->json('actions.0.status'));
        $this->assertStringContainsString('teléfono', $respuesta->json('actions.0.detail'));
        // Lo importante: la cita SÍ quedó confirmada.
        $this->assertSame(Appointment::STATUS_CONFIRMED, $cita->fresh()->status);
    }

    public function test_un_canal_caido_no_atasca_el_mostrador(): void
    {
        $roto = new class extends \Exception {};

        $this->app->instance(MessagingChannel::class, new class($roto) implements MessagingChannel
        {
            public function __construct(private \Throwable $error) {}

            public function isConfigured(): bool
            {
                return true;
            }

            public function sendText(string $to, string $body, ?int $businessId = null, string $type = 'generico'): bool
            {
                throw new \RuntimeException('Timeout hablando con el proveedor');
            }

            public function sendTemplate(string $to, string $name, string $languageCode, array $components = [], ?int $businessId = null, string $type = 'generico'): bool
            {
                return true;
            }

            public function sendDocument(string $to, string $documentUrl, string $filename, string $caption = '', ?int $businessId = null, string $type = 'generico'): bool
            {
                return true;
            }

            public function sendFlow(string $to, string $flowId, string $screen, string $bodyText, string $cta, array $data, string $flowToken, ?int $businessId = null, string $type = 'generico'): bool
            {
                return true;
            }

            public function markAsReadWithTyping(string $to, string $messageId): bool
            {
                return true;
            }
        });

        $cita = $this->agendar();

        $respuesta = $this->postJson("/api/v1/appointments/{$cita->id}/stage", [
            'stage_id' => $this->stage('confirmada')->id,
        ])->assertOk();

        // La cita avanzó igual: negarse a confirmarla porque WhatsApp está
        // caído dejaría el mostrador parado por algo que no depende de nadie ahí.
        $this->assertSame(Appointment::STATUS_CONFIRMED, $cita->fresh()->status);

        // Pero el fallo quedó anotado, no descartado.
        $this->assertSame('failed', $respuesta->json('actions.0.status'));
        $this->assertStringContainsString('Timeout', $respuesta->json('actions.0.detail'));
    }

    public function test_una_accion_apagada_por_bandera_se_omite(): void
    {
        $this->canalQueFunciona();
        $flags = $this->business->feature_flags;
        $flags['reminders'] = false;
        $this->business->update(['feature_flags' => $flags]);

        $cita = $this->agendar();

        $respuesta = $this->postJson("/api/v1/appointments/{$cita->id}/stage", [
            'stage_id' => $this->stage('confirmada')->id,
        ])->assertOk();

        $this->assertSame('skipped', $respuesta->json('actions.0.status'));
        $this->assertStringContainsString('apagada', $respuesta->json('actions.0.detail'));
    }

    public function test_marcar_inasistencia_lo_anota_en_la_ficha(): void
    {
        $cliente = Client::create([
            'business_id' => $this->business->id, 'name' => 'Carolina', 'phone' => '+573001112233',
        ]);

        $cita = $this->agendar($cliente);

        $this->postJson("/api/v1/appointments/{$cita->id}/stage", [
            'stage_id' => $this->stage('no_asistio')->id,
        ])->assertOk();

        $this->assertDatabaseHas('client_penalties', [
            'client_id' => $cliente->id,
            'appointment_id' => $cita->id,
            'kind' => ClientPenalty::KIND_NO_SHOW,
        ]);
    }

    public function test_marcar_inasistencia_dos_veces_no_son_dos_faltas(): void
    {
        $cliente = Client::create([
            'business_id' => $this->business->id, 'name' => 'Carolina', 'phone' => '+573001112233',
        ]);

        $cita = $this->agendar($cliente);
        $noAsistio = $this->stage('no_asistio')->id;

        $this->postJson("/api/v1/appointments/{$cita->id}/stage", ['stage_id' => $noAsistio])->assertOk();
        // Vuelve a confirmada y otra vez a no asistió.
        $this->postJson("/api/v1/appointments/{$cita->id}/stage", ['stage_id' => $this->stage('confirmada')->id])->assertOk();
        $this->postJson("/api/v1/appointments/{$cita->id}/stage", ['stage_id' => $noAsistio])->assertOk();

        $this->assertSame(1, ClientPenalty::withoutGlobalScope('business')
            ->where('appointment_id', $cita->id)->count());
    }

    public function test_una_etapa_puede_cobrar_al_entrar(): void
    {
        // El negocio que cobra al marcar "lista", sin pasar por caja.
        $lista = $this->stage('lista');
        $lista->update(['actions' => [[
            'type' => StageActionCatalog::MARK_PAID,
            'config' => ['payment_method_id' => $this->efectivo->id],
        ]]]);

        $cita = $this->agendar();

        $this->postJson("/api/v1/appointments/{$cita->id}/stage", ['stage_id' => $lista->id])
            ->assertOk();

        $cita->refresh();
        $this->assertNotNull($cita->checked_out_at);
        $this->assertEqualsWithDelta(50000, (float) $cita->total, 0.01);
        // Y la comisión quedó congelada, igual que si se hubiera cobrado en caja.
        $this->assertEqualsWithDelta(20000, (float) $cita->commission_total, 0.01);
    }

    public function test_si_el_cobro_automatico_falla_la_cita_no_queda_marcada(): void
    {
        // Sin ningún medio de pago activo: la acción crítica no puede cobrar.
        PaymentMethod::withoutGlobalScope('business')
            ->where('business_id', $this->business->id)->update(['is_active' => false]);

        $lista = $this->stage('lista');
        $lista->update(['actions' => [['type' => StageActionCatalog::MARK_PAID, 'config' => []]]]);

        $cita = $this->agendar();

        $this->postJson("/api/v1/appointments/{$cita->id}/stage", ['stage_id' => $lista->id])
            ->assertStatus(422)
            // Y dice qué falló, no "error del servidor": quien lo lee tiene
            // que poder ir a arreglarlo.
            ->assertJsonPath('message', 'Cobrar lo pendiente: La etapa no tiene un medio de pago configurado.');

        // Lo crítico: la cita NO quedó como cobrada. Una cita marcada "lista y
        // cobrada" sin cobro descuadra el cierre, y el descuadre aparece horas
        // después sin nada que lo explique.
        $cita->refresh();
        $this->assertSame(Appointment::STATUS_PENDING, $cita->status);
        $this->assertNull($cita->checked_out_at);
    }

    public function test_una_etapa_puede_liberar_el_horario(): void
    {
        // El negocio que prefiere liberar el hueco al marcar inasistencia,
        // por si entra alguien sin cita.
        $this->stage('no_asistio')->update(['actions' => [
            ['type' => StageActionCatalog::RELEASE_SLOT, 'config' => []],
        ]]);

        $cita = $this->agendar();
        $itemIds = $cita->items()->pluck('id');

        $this->assertGreaterThan(0, ResourceOccupancy::whereIn('appointment_item_id', $itemIds)->count());

        $this->postJson("/api/v1/appointments/{$cita->id}/stage", [
            'stage_id' => $this->stage('no_asistio')->id,
        ])->assertOk();

        $this->assertSame(0, ResourceOccupancy::whereIn('appointment_item_id', $itemIds)->count());
    }

    /*
    |--------------------------------------------------------------------------
    | La bitácora
    |--------------------------------------------------------------------------
    */

    public function test_queda_registrado_quien_movio_la_cita(): void
    {
        $this->canalQueFunciona();

        $cita = $this->agendar();
        $this->postJson("/api/v1/appointments/{$cita->id}/stage", [
            'stage_id' => $this->stage('confirmada')->id,
        ])->assertOk();

        $historia = $this->getJson("/api/v1/appointments/{$cita->id}/history")->assertOk()->json();

        // Agendar también deja evento: hoy la única huella de un cambio de
        // estado era el estado nuevo, así que si una cita aparecía cancelada
        // nadie sabía quién ni cuándo.
        $ultimo = $historia[0];
        $this->assertSame('Confirmada', $ultimo['to']);
        $this->assertSame('Sin confirmar', $ultimo['from']);
        $this->assertSame('Ana', $ultimo['by']);
        $this->assertSame('Avisarle a la clienta', $ultimo['actions'][0]['label']);
        $this->assertSame('ok', $ultimo['actions'][0]['status']);
    }

    public function test_cobrar_por_caja_tambien_pasa_por_la_maquina(): void
    {
        $cita = $this->agendar();

        $this->postJson("/api/v1/appointments/{$cita->id}/checkout", [
            'payment_method_id' => $this->efectivo->id,
        ])->assertOk();

        $cita->refresh();
        $this->assertSame(Appointment::STATUS_COMPLETED, $cita->status);
        // Y cayó en la etapa que el negocio llama así.
        $this->assertSame($this->stage('lista')->id, $cita->stage_id);

        $this->assertDatabaseHas('appointment_stage_events', [
            'appointment_id' => $cita->id,
            'to_status' => Appointment::STATUS_COMPLETED,
        ]);
    }

    public function test_deshacer_un_cobro_vuelve_a_confirmada_y_queda_registrado(): void
    {
        $cita = $this->agendar();

        $this->postJson("/api/v1/appointments/{$cita->id}/checkout", [
            'payment_method_id' => $this->efectivo->id,
        ])->assertOk();

        $this->deleteJson("/api/v1/appointments/{$cita->id}/checkout")->assertOk();

        $cita->refresh();
        $this->assertSame(Appointment::STATUS_CONFIRMED, $cita->status);
        $this->assertSame($this->stage('confirmada')->id, $cita->stage_id);

        $eventos = AppointmentStageEvent::withoutGlobalScope('business')
            ->where('appointment_id', $cita->id)->orderBy('id')->get();

        $this->assertSame(
            [Appointment::STATUS_COMPLETED, Appointment::STATUS_CONFIRMED],
            $eventos->pluck('to_status')->all(),
        );
    }

    public function test_cancelar_pasa_por_la_maquina_y_libera_el_horario(): void
    {
        $canal = $this->canalQueFunciona();

        $cita = $this->agendar();
        $itemIds = $cita->items()->pluck('id');

        $this->postJson("/api/v1/appointments/{$cita->id}/cancel", ['reason' => 'La clienta avisó'])
            ->assertOk();

        $cita->refresh();
        $this->assertSame(Appointment::STATUS_CANCELLED, $cita->status);
        $this->assertNotNull($cita->cancelled_at);
        // Liberar es la garantía del núcleo, no una automatización opcional.
        $this->assertSame(0, ResourceOccupancy::whereIn('appointment_item_id', $itemIds)->count());
        // Y el aviso de la etapa "cancelada" salió.
        $this->assertCount(1, $canal->sent);
        $this->assertStringContainsString('cancelada', $canal->sent[0]['body']);
    }

    public function test_una_profesional_no_mueve_citas_de_etapa(): void
    {
        $cita = $this->agendar();

        $staff = User::create([
            'business_id' => $this->business->id, 'name' => 'Maria',
            'email' => 'maria@prueba.test', 'password' => Hash::make('password123'), 'is_active' => true,
        ]);
        PermissionCatalog::applyRole($staff, PermissionCatalog::ROLE_STAFF);

        Sanctum::actingAs($staff->fresh());

        $this->postJson("/api/v1/appointments/{$cita->id}/stage", [
            'stage_id' => $this->stage('cancelada')->id,
        ])->assertForbidden();
    }
}
