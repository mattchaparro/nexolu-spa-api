<?php

namespace Tests\Feature\Clients;

use App\Models\Business;
use App\Models\Client;
use App\Models\ClientPhoto;
use App\Models\PaymentMethod;
use App\Models\Resource;
use App\Models\Service;
use App\Models\User;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Scheduling\SchedulingScenario;
use Tests\TestCase;

/**
 * La ficha del cliente: lo que la profesional consulta antes de atender.
 */
class ClientProfileTest extends TestCase
{
    use RefreshDatabase, SchedulingScenario;

    private Business $business;

    private User $admin;

    private Resource $maria;

    private Service $service;

    private PaymentMethod $efectivo;

    protected function setUp(): void
    {
        parent::setUp();

        PermissionCatalog::sync();
        Storage::fake('public');

        $this->business = $this->makeBusiness(['slot_granularity_min' => 60]);
        $this->admin = User::create([
            'business_id' => $this->business->id,
            'name' => 'Admin',
            'email' => 'admin@prueba.test',
            'password' => Hash::make('password123'),
            'is_active' => true,
        ]);
        $this->admin->assignRole(PermissionCatalog::ROLE_ADMIN);

        $this->maria = $this->makeResource($this->business, 'Maria', '09:00:00', '18:00:00');
        $this->service = $this->makeService($this->business, 60, [$this->maria], name: 'Manicure');
        $this->service->update(['price' => 50000, 'commission_rate' => 0.30]);

        $this->efectivo = PaymentMethod::create([
            'business_id' => $this->business->id,
            'name' => 'Efectivo',
            'counts_as_cash' => true,
        ]);

        Sanctum::actingAs($this->admin);
    }

    private function client(): Client
    {
        return Client::create([
            'business_id' => $this->business->id,
            'name' => 'Laura',
            'last_name' => 'Gomez',
            'phone' => '573001112233',
        ]);
    }

    /** Agenda, cobra y devuelve el id de la cita. */
    private function atender(Client $client, string $hora = '10:00'): int
    {
        $fecha = $this->wednesday()->toDateString();

        $id = $this->postJson('/api/v1/appointments', [
            'service_id' => $this->service->id,
            'resource_id' => $this->maria->id,
            'starts_at' => "{$fecha} {$hora}:00",
            'client_id' => $client->id,
            'client_name' => $client->fullName(),
        ])->assertCreated()->json('id');

        $this->postJson("/api/v1/appointments/{$id}/checkout", [
            'payment_method_id' => $this->efectivo->id,
        ])->assertOk();

        return $id;
    }

    public function test_agendar_a_alguien_nuevo_le_crea_su_ficha(): void
    {
        $fecha = $this->wednesday()->toDateString();

        $this->postJson('/api/v1/appointments', [
            'service_id' => $this->service->id,
            'resource_id' => $this->maria->id,
            'starts_at' => "{$fecha} 10:00:00",
            'client_name' => 'Valentina Torres',
            'client_phone' => '3009998877',
        ])->assertCreated();

        // Sin esto el nombre quedaba suelto en la cita, el listado de clientes
        // vacio y el historial nunca se acumulaba.
        $creada = Client::where('phone', '573009998877')->first();
        $this->assertNotNull($creada);
        $this->assertSame('Valentina', $creada->name);
        $this->assertSame('Torres', $creada->last_name);

        // Y la segunda cita con el mismo telefono reusa la ficha en vez de
        // duplicar a la persona.
        $this->postJson('/api/v1/appointments', [
            'service_id' => $this->service->id,
            'resource_id' => $this->maria->id,
            'starts_at' => "{$fecha} 12:00:00",
            'client_name' => 'valentina torres',
            'client_phone' => '+57 300 999 8877',
        ])->assertCreated();

        $this->assertSame(1, Client::where('phone', '573009998877')->count());
        $this->assertCount(2, $this->getJson("/api/v1/clients/{$creada->id}")->json('history'));
    }

    public function test_la_ficha_resume_visitas_gasto_y_preferencias(): void
    {
        $client = $this->client();
        $this->atender($client, '10:00');
        $this->atender($client, '12:00');

        $response = $this->getJson("/api/v1/clients/{$client->id}")->assertOk();

        $this->assertSame(2, $response->json('stats.visits'));
        $this->assertEqualsWithDelta(100000, $response->json('stats.total_spent'), 0.01);
        // Sirve para saber si vale la pena un descuento, y de cuanto.
        $this->assertEqualsWithDelta(50000, $response->json('stats.average_ticket'), 0.01);
        $this->assertSame('Manicure', $response->json('stats.favorite_service'));
        $this->assertSame('Maria', $response->json('stats.favorite_resource'));
        $this->assertNotNull($response->json('stats.last_visit'));
    }

    public function test_el_historial_incluye_cancelaciones_e_inasistencias(): void
    {
        $client = $this->client();
        $this->atender($client, '10:00');

        $fecha = $this->wednesday()->toDateString();
        $cancelada = $this->postJson('/api/v1/appointments', [
            'service_id' => $this->service->id,
            'resource_id' => $this->maria->id,
            'starts_at' => "{$fecha} 14:00:00",
            'client_id' => $client->id,
            'client_name' => $client->fullName(),
        ])->json('id');

        $this->postJson("/api/v1/appointments/{$cancelada}/cancel")->assertOk();

        // Se muestran a proposito: quien cancela varias veces es informacion
        // que el mostrador necesita antes de darle la hora pico.
        $historial = $this->getJson("/api/v1/clients/{$client->id}")->json('history');

        $this->assertCount(2, $historial);
        $this->assertContains('cancelled', array_column($historial, 'status'));
        $this->assertContains('completed', array_column($historial, 'status'));
    }

    public function test_una_cita_futura_aparece_como_proxima(): void
    {
        $client = $this->client();
        $fecha = $this->wednesday()->toDateString();

        $this->postJson('/api/v1/appointments', [
            'service_id' => $this->service->id,
            'resource_id' => $this->maria->id,
            'starts_at' => "{$fecha} 15:00:00",
            'client_id' => $client->id,
            'client_name' => $client->fullName(),
        ])->assertCreated();

        $this->getJson("/api/v1/clients/{$client->id}")
            ->assertOk()
            ->assertJsonPath('stats.visits', 0)
            ->assertJsonPath('stats.next_appointment.label', $this->wednesday()->format('d/m/Y').' 15:00');
    }

    public function test_subir_una_foto_del_trabajo(): void
    {
        $client = $this->client();
        $citaId = $this->atender($client);
        $itemId = $this->getJson("/api/v1/clients/{$client->id}")->json('history.0.items.0.id');

        $this->postJson("/api/v1/clients/{$client->id}/photos", [
            'photo' => UploadedFile::fake()->image('trabajo.jpg'),
            'caption' => 'Rojo vino, forma almendra',
            'appointment_item_id' => $itemId,
        ])->assertCreated();

        $ficha = $this->getJson("/api/v1/clients/{$client->id}")->assertOk();

        $this->assertCount(1, $ficha->json('photos'));
        $this->assertSame('Rojo vino, forma almendra', $ficha->json('photos.0.caption'));
        // La foto sabe de que servicio salio: es lo que se consulta antes de
        // atender la proxima vez.
        $this->assertSame('Manicure', $ficha->json('photos.0.service_name'));

        Storage::disk('public')->assertExists(ClientPhoto::first()->image_path);
    }

    public function test_una_foto_no_se_puede_colgar_de_la_cita_de_otro_cliente(): void
    {
        $laura = $this->client();
        $otra = Client::create([
            'business_id' => $this->business->id, 'name' => 'Carolina', 'phone' => '573004445566',
        ]);

        $this->atender($otra, '10:00');
        $itemAjeno = $this->getJson("/api/v1/clients/{$otra->id}")->json('history.0.items.0.id');

        $this->postJson("/api/v1/clients/{$laura->id}/photos", [
            'photo' => UploadedFile::fake()->image('trabajo.jpg'),
            'appointment_item_id' => $itemAjeno,
        ])->assertCreated();

        // La foto se guarda, pero suelta: no puede quedar colgada de un
        // trabajo que no es de este cliente.
        $this->assertNull(ClientPhoto::where('client_id', $laura->id)->first()->appointment_item_id);
    }

    public function test_borrar_una_foto_borra_el_archivo(): void
    {
        $client = $this->client();

        $id = $this->postJson("/api/v1/clients/{$client->id}/photos", [
            'photo' => UploadedFile::fake()->image('trabajo.jpg'),
        ])->json('id');

        $path = ClientPhoto::find($id)->image_path;

        $this->deleteJson("/api/v1/clients/photos/{$id}")->assertOk();

        Storage::disk('public')->assertMissing($path);
        $this->assertNull(ClientPhoto::find($id));
    }

    public function test_editar_la_ficha_normaliza_el_telefono_y_evita_duplicados(): void
    {
        $laura = $this->client();
        $otra = Client::create([
            'business_id' => $this->business->id, 'name' => 'Carolina', 'phone' => '573004445566',
        ]);

        $this->patchJson("/api/v1/clients/{$laura->id}", [
            'phone' => '300 999 8877',
            'care_notes' => 'Alergica al acrilico',
        ])->assertOk()->assertJsonPath('phone', '573009998877');

        $this->assertSame('Alergica al acrilico', Client::find($laura->id)->care_notes);

        // El mismo numero escrito distinto sigue siendo el mismo numero.
        $this->patchJson("/api/v1/clients/{$laura->id}", ['phone' => '+57 300-444-5566'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('phone');

        $this->assertSame('573004445566', $otra->fresh()->phone);
    }

    public function test_el_listado_pagina_y_cuenta_visitas(): void
    {
        $client = $this->client();
        $this->atender($client);
        Client::create(['business_id' => $this->business->id, 'name' => 'Sin visitas']);

        $response = $this->getJson('/api/v1/clients')->assertOk();

        $this->assertSame(2, $response->json('meta.total'));
        $this->assertSame(1, collect($response->json('data'))->firstWhere('full_name', 'Laura Gomez')['visits']);
        $this->assertSame(0, collect($response->json('data'))->firstWhere('full_name', 'Sin visitas')['visits']);
    }

    public function test_ver_el_historial_exige_su_propio_permiso(): void
    {
        $client = $this->client();

        // Sin rol y con un solo permiso directo. Quitarselo a alguien que ya
        // lo tiene POR SU ROL no funcionaria: revokePermissionTo solo toca
        // los permisos directos, y el rol se lo seguiria concediendo.
        $limitado = User::create([
            'business_id' => $this->business->id, 'name' => 'Limitado',
            'email' => 'limitado@prueba.test', 'password' => Hash::make('password123'), 'is_active' => true,
        ]);
        $limitado->givePermissionTo('clientes.ver');

        Sanctum::actingAs($limitado);

        // Poder elegir a alguien en un buscador no es lo mismo que poder leer
        // su historial completo.
        $this->getJson('/api/v1/clients/search?q=lau')->assertOk();
        $this->getJson("/api/v1/clients/{$client->id}")->assertStatus(403);
    }

    public function test_no_se_puede_ver_la_ficha_de_un_cliente_de_otro_negocio(): void
    {
        $otroNegocio = $this->makeBusiness();

        $ajenoId = \Illuminate\Support\Facades\DB::table('clients')->insertGetId([
            'business_id' => $otroNegocio->id,
            'name' => 'Ajena',
            'is_active' => true,
            'accepts_marketing' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // El scope global convierte esto en un 404, no en un 403: para este
        // negocio ese cliente sencillamente no existe.
        $this->getJson("/api/v1/clients/{$ajenoId}")->assertStatus(404);
    }
}
