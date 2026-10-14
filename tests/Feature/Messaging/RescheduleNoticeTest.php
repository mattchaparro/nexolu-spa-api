<?php

namespace Tests\Feature\Messaging;

use App\Models\Appointment;
use App\Models\Business;
use App\Models\Client;
use App\Models\Message;
use App\Models\Resource;
use App\Models\Service;
use App\Models\User;
use App\Services\Scheduling\BookingService;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Scheduling\SchedulingScenario;
use Tests\TestCase;

/**
 * Mover la cita desde el panel le avisa a la clienta.
 *
 * Antes solo se enteraba el equipo (`equipo_movida`) y a ella no le llegaba
 * nada: se aparecía a la hora vieja o no se enteraba del cambio.
 */
class RescheduleNoticeTest extends TestCase
{
    use RefreshDatabase, SchedulingScenario;

    private Business $business;

    private Resource $maria;

    private Resource $lucia;

    private Service $service;

    private Appointment $cita;

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
        $this->business->update(['messaging_mode' => 'auto']);
        $this->maria = $this->makeResource($this->business, 'Maria');
        $this->lucia = $this->makeResource($this->business, 'Lucia');
        $this->service = $this->makeService($this->business, 60, [$this->maria, $this->lucia], name: 'Semipermanente');

        $admin = User::create([
            'business_id' => $this->business->id,
            'name' => 'Ana',
            'email' => 'ana@prueba.test',
            'password' => Hash::make('password123'),
            'is_active' => true,
        ]);
        PermissionCatalog::applyRole($admin, PermissionCatalog::ROLE_ADMIN);
        Sanctum::actingAs($admin->fresh());

        $carolina = Client::create([
            'business_id' => $this->business->id,
            'name' => 'Carolina', 'phone' => '+573001112233', 'is_active' => true,
        ]);

        $this->cita = app(BookingService::class)->book(
            $this->business,
            [[
                'service_id' => $this->service->id,
                'resource_id' => $this->maria->id,
                'starts_at' => $this->wednesday()->setTime(10, 0),
            ]],
            $carolina,
            'Carolina',
            '+573001112233',
            Appointment::SOURCE_ADMIN,
            null,
            false,
        );
    }

    /** @param array<string, mixed> $extra */
    private function mover(string $hora, array $extra = []): void
    {
        $this->patchJson("/api/v1/appointments/{$this->cita->id}/reschedule", [
            'starts_at' => $this->wednesday()->format('Y-m-d').' '.$hora.':00',
            ...$extra,
        ])->assertOk();
    }

    private function avisos()
    {
        return Message::withoutGlobalScopes()->where('kind', Message::KIND_RESCHEDULED)->get();
    }

    public function test_moverla_de_hora_le_avisa_a_la_clienta_con_el_antes_y_el_ahora(): void
    {
        $this->mover('13:00');

        $aviso = $this->avisos()->sole();

        $this->assertSame('573001112233', $aviso->to);
        $this->assertStringContainsString('Tu cita se reagendó', $aviso->body);
        $this->assertStringContainsString('Antes: '.ucfirst($this->wednesday()->locale('es')->isoFormat('dddd D [de] MMMM')).' a las 10:00 am', $aviso->body);
        $this->assertStringContainsString('*'.ucfirst($this->wednesday()->locale('es')->isoFormat('dddd D [de] MMMM')).' a las 1:00 pm*', $aviso->body);
        $this->assertStringContainsString('Semipermanente', $aviso->body);
        $this->assertStringContainsString('Maria', $aviso->body);
    }

    public function test_fuera_de_la_ventana_va_como_plantilla_con_los_datos_nuevos(): void
    {
        $this->mover('13:00');

        $aviso = $this->avisos()->sole();

        $this->assertSame('confirmacion_cita', $aviso->template_name);
        $this->assertContains('1:00 pm', $aviso->template_params);
    }

    public function test_cambiar_solo_la_persona_tambien_avisa(): void
    {
        $this->mover('10:00', ['resource_id' => $this->lucia->id]);

        $this->assertStringContainsString('Lucia', $this->avisos()->sole()->body);
    }

    public function test_reagendar_a_lo_mismo_no_avisa_nada(): void
    {
        $this->mover('10:00');

        $this->assertCount(0, $this->avisos());
    }

    public function test_dos_movidas_son_dos_avisos(): void
    {
        $this->mover('13:00');
        $this->mover('15:00');

        $this->assertCount(2, $this->avisos());
    }

    public function test_sin_avisar_no_sale_nada(): void
    {
        $this->mover('13:00', ['silent' => true]);

        $this->assertCount(0, $this->avisos());
    }

    public function test_sin_telefono_no_hay_a_quien_avisar(): void
    {
        $this->cita->forceFill(['client_phone' => null])->save();
        $this->cita->client->forceFill(['phone' => null])->save();

        $this->mover('13:00');

        $this->assertCount(0, $this->avisos());
    }
}
