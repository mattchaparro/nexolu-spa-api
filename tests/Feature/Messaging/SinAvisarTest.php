<?php

namespace Tests\Feature\Messaging;

use App\Models\Appointment;
use App\Models\AppointmentWorkflow;
use App\Models\Business;
use App\Models\Message;
use App\Models\PaymentMethod;
use App\Models\Resource;
use App\Models\ResourceOccupancy;
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
 * El trabajo de oficina no le escribe a nadie.
 *
 * Pasada la medianoche del 25 de septiembre hubo que subir seis servicios del
 * día anterior que nadie había cargado. Cada cobro manda el gracias con la
 * encuesta: sin una forma de callarlo, a seis clientas les habría llegado a
 * las doce y media de la noche. Y una cita cargada por error no se cancela
 * --nadie canceló nada--: se borra, y tampoco avisa.
 */
class SinAvisarTest extends TestCase
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

        $this->business = $this->makeBusiness([
            'slot_granularity_min' => 60,
            'min_booking_notice_min' => 0,
            'notify_team_whatsapp' => true,
        ]);
        $this->business->update(['messaging_mode' => 'auto']);
        $this->maria = $this->makeResource($this->business, 'Maria');
        $this->maria->update(['phone' => '3142305988']);
        $this->service = $this->makeService($this->business, 60, [$this->maria], name: 'Semipermanente');

        // El flujo de Luxury: al completar se le escribe a la clienta.
        $flujo = AppointmentWorkflow::create(['name' => 'Luxury', 'is_default' => false, 'is_active' => true]);
        $flujo->stages()->create([
            'key' => 'agendada', 'label' => 'Agendada', 'sort_order' => 1,
            'maps_to_status' => Appointment::STATUS_PENDING, 'is_initial' => true, 'actions' => [],
        ]);
        $flujo->stages()->create([
            'key' => 'completada', 'label' => 'Completada', 'sort_order' => 2,
            'maps_to_status' => Appointment::STATUS_COMPLETED, 'is_initial' => false,
            'actions' => [['type' => 'notify_client', 'config' => ['template' => 'Gracias por venir, {cliente}.']]],
        ]);
        $flujo->stages()->create([
            'key' => 'cancelada', 'label' => 'Cancelada', 'sort_order' => 3,
            'maps_to_status' => Appointment::STATUS_CANCELLED, 'is_initial' => false,
            'actions' => [['type' => 'notify_client', 'config' => ['template' => 'Tu cita quedó cancelada.']]],
        ]);
        $this->business->update(['appointment_workflow_id' => $flujo->id]);

        $this->admin = $this->usuario('ana@prueba.test', PermissionCatalog::ROLE_ADMIN);
        Sanctum::actingAs($this->admin->fresh());
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

        return $user;
    }

    /** @param array<string, mixed> $extra */
    private function agendar(array $extra = []): Appointment
    {
        $this->postJson('/api/v1/appointments', [
            'service_id' => $this->service->id,
            'resource_id' => $this->maria->id,
            'starts_at' => $this->wednesday()->format('Y-m-d').' 11:00:00',
            'client_name' => 'Carolina',
            'client_phone' => '3001112233',
            ...$extra,
        ])->assertCreated();

        return Appointment::withoutGlobalScopes()->latest('id')->firstOrFail();
    }

    private function mensajes(): int
    {
        return Message::withoutGlobalScopes()->count();
    }

    private function efectivo(): PaymentMethod
    {
        return PaymentMethod::withoutGlobalScopes()->firstOrCreate(
            ['business_id' => $this->business->id, 'name' => 'Efectivo'],
            ['counts_as_cash' => true, 'is_active' => true, 'sort_order' => 0],
        );
    }

    public function test_agendar_normal_si_avisa(): void
    {
        // El control: sin la casilla, la clienta y Maria se enteran.
        $this->agendar();

        $this->assertGreaterThan(0, $this->mensajes());
    }

    public function test_agendar_sin_avisar_no_le_escribe_a_nadie(): void
    {
        $this->agendar(['silent' => true]);

        $this->assertSame(1, Appointment::withoutGlobalScopes()->count());
        $this->assertSame(0, $this->mensajes());
    }

    public function test_cobrar_normal_si_manda_el_gracias(): void
    {
        $cita = $this->agendar(['silent' => true]);

        $this->postJson("/api/v1/appointments/{$cita->id}/checkout", [
            'payment_method_id' => $this->efectivo()->id,
        ])->assertOk();

        $this->assertGreaterThan(0, $this->mensajes());
    }

    public function test_cobrar_sin_avisar_no_manda_el_gracias(): void
    {
        $cita = $this->agendar(['silent' => true]);

        $this->postJson("/api/v1/appointments/{$cita->id}/checkout", [
            'payment_method_id' => $this->efectivo()->id,
            'silent' => true,
        ])->assertOk();

        $this->assertSame(Appointment::STATUS_COMPLETED, $cita->fresh()->status);
        $this->assertSame(0, $this->mensajes());
    }

    public function test_sin_el_permiso_no_se_puede_pedir_silencio(): void
    {
        /*
         * Un error y no un «igual se avisa»: quien marcó la casilla cree que
         * no salió nada.
         */
        Sanctum::actingAs($this->usuario('sofia@prueba.test', PermissionCatalog::ROLE_RECEPTION)->fresh());

        $this->postJson('/api/v1/appointments', [
            'service_id' => $this->service->id,
            'resource_id' => $this->maria->id,
            'starts_at' => $this->wednesday()->format('Y-m-d').' 11:00:00',
            'client_name' => 'Carolina',
            'client_phone' => '3001112233',
            'silent' => true,
        ])->assertForbidden();

        $this->assertSame(0, Appointment::withoutGlobalScopes()->count());
    }

    public function test_eliminar_borra_libera_el_horario_y_no_avisa(): void
    {
        $cita = $this->agendar();
        $antes = $this->mensajes();
        $items = $cita->items()->pluck('id');

        $this->deleteJson("/api/v1/appointments/{$cita->id}", ['reason' => 'Repetida'])->assertNoContent();

        $this->assertSame($antes, $this->mensajes(), 'Eliminar le escribió a alguien.');
        $this->assertNull(Appointment::find($cita->id));
        $this->assertSame(
            'Eliminada: Repetida',
            Appointment::withoutGlobalScopes()->withTrashed()->find($cita->id)->cancellation_reason,
        );
        $this->assertSame(
            0,
            ResourceOccupancy::withoutGlobalScope('business')->whereIn('appointment_item_id', $items)->count(),
        );
    }

    public function test_una_cita_cobrada_no_se_elimina(): void
    {
        // Esa plata ya está en la caja y en la comisión de alguien.
        $cita = $this->agendar(['silent' => true]);
        $this->postJson("/api/v1/appointments/{$cita->id}/checkout", [
            'payment_method_id' => $this->efectivo()->id,
            'silent' => true,
        ])->assertOk();

        $this->deleteJson("/api/v1/appointments/{$cita->id}")->assertStatus(422);

        $this->assertNotNull(Appointment::find($cita->id));
    }

    public function test_eliminar_es_solo_del_admin(): void
    {
        $cita = $this->agendar(['silent' => true]);
        Sanctum::actingAs($this->usuario('sofia@prueba.test', PermissionCatalog::ROLE_RECEPTION)->fresh());

        $this->deleteJson("/api/v1/appointments/{$cita->id}")->assertForbidden();

        $this->assertNotNull(Appointment::find($cita->id));
    }
}
