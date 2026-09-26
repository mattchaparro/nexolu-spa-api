<?php

namespace Tests\Feature\Whatsapp;

use App\Jobs\SyncClientToConnectJob;
use App\Models\Appointment;
use App\Models\AppointmentItem;
use App\Models\Business;
use App\Models\Client;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Scheduling\SchedulingScenario;
use Tests\TestCase;

/**
 * Las difusiones se arman en Connect con lo que el Spa le cuenta de cada
 * clienta. Lo que se defiende: que quien no acepta promociones llegue
 * marcado así, y que la última visita sea la COBRADA, con quien la atendió.
 */
class ConnectContactSyncTest extends TestCase
{
    use RefreshDatabase, SchedulingScenario;

    private Business $luxury;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-09-26 10:00', 'America/Bogota'));
        config()->set('services.comms_core.api_key', 'llave-spa');
        config()->set('services.comms_core.base_url', 'http://comms.test');

        $this->luxury = $this->makeBusiness();
    }

    private function visita(Client $clienta, string $cuando, ?string $atendio = null, bool $cobrada = true): void
    {
        $cita = Appointment::create([
            'business_id' => $this->luxury->id,
            'location_id' => $this->luxury->locations()->first()->id,
            'client_id' => $clienta->id,
            'client_name' => $clienta->name,
            'starts_at' => CarbonImmutable::parse($cuando, 'America/Bogota'),
            'ends_at' => CarbonImmutable::parse($cuando, 'America/Bogota')->addHour(),
            'status' => Appointment::STATUS_COMPLETED,
        ]);
        if ($cobrada) {
            $cita->forceFill(['checked_out_at' => CarbonImmutable::parse($cuando, 'America/Bogota')->addHour()])->save();
        }

        if ($atendio !== null) {
            $recurso = $this->makeResource($this->luxury, $atendio);
            AppointmentItem::create([
                'business_id' => $this->luxury->id,
                'appointment_id' => $cita->id,
                'service_id' => $this->makeService($this->luxury)->id,
                'resource_id' => $recurso->id,
                'starts_at' => $cita->starts_at,
                'ends_at' => $cita->ends_at,
                'service_starts_at' => $cita->starts_at,
                'service_ends_at' => $cita->ends_at,
                'price' => 50000,
                'final_price' => 50000,
                'sort_order' => 0,
            ]);
        }
    }

    public function test_publica_promociones_ultima_visita_cobrada_y_quien_atendio(): void
    {
        Queue::fake();
        Http::fake(['comms.test/*' => Http::response(['created' => 2, 'updated' => 0])]);

        $ana = Client::create(['business_id' => $this->luxury->id, 'name' => 'Ana', 'last_name' => 'Ruiz', 'phone' => '300 111 2233', 'accepts_marketing' => true]);
        $luisa = Client::create(['business_id' => $this->luxury->id, 'name' => 'Luisa', 'phone' => '3004445566', 'accepts_marketing' => false]);
        Client::create(['business_id' => $this->luxury->id, 'name' => 'Sin teléfono', 'accepts_marketing' => true]);

        $this->visita($ana, '2026-07-10 10:00', 'Marcela');
        $this->visita($ana, '2026-09-01 15:00', 'Alejandra');
        $this->visita($ana, '2026-09-20 15:00', null, cobrada: false); // agendada, no atendida

        $this->artisan('connect:sincronizar-clientas')->assertSuccessful();

        Http::assertSent(function (Request $request) {
            if ($request->method() !== 'PUT' || $request->url() !== 'http://comms.test/v1/contacts/bulk') {
                return false;
            }
            $contactos = collect($request['contacts'])->keyBy('phone');

            return $request['business_id'] === (string) $this->luxury->id
                && $contactos->count() === 2
                && $contactos['573001112233']['name'] === 'Ana Ruiz'
                && $contactos['573001112233']['fields'] === [
                    'acepta_promociones' => true,
                    'ultima_visita' => '2026-09-01',
                    'visitas' => 2,
                    'ultima_atencion_con' => 'Alejandra',
                ]
                && $contactos['573004445566']['fields']['acepta_promociones'] === false
                && $contactos['573004445566']['fields']['visitas'] === 0;
        });
    }

    public function test_quien_deja_de_aceptar_promociones_sale_de_una(): void
    {
        Queue::fake();

        $ana = Client::create(['business_id' => $this->luxury->id, 'name' => 'Ana', 'phone' => '3001112233', 'accepts_marketing' => true]);
        $ana->update(['notes' => 'vip']);
        Queue::assertNotPushed(SyncClientToConnectJob::class);

        $ana->update(['accepts_marketing' => false]);
        Queue::assertPushed(SyncClientToConnectJob::class, fn ($job) => $job->clientId === $ana->id);
    }
}
