<?php

namespace Tests\Feature\Clients;

use App\Models\Appointment;
use App\Models\Business;
use App\Models\Client;
use App\Models\Resource;
use App\Models\User;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Scheduling\SchedulingScenario;
use Tests\TestCase;

/**
 * Identificar a quien se tiene delante, sin abrir la base de clientas.
 *
 * Las dos mitades tienen que cumplirse a la vez: que la manicurista PUEDA
 * decir quien vino -- si no, la visita no suma sello ni recibe encuesta -- y
 * que NO pueda recorrerse ni llevarse la lista, que es el activo del negocio.
 */
class IdentificarClienteTest extends TestCase
{
    use RefreshDatabase, SchedulingScenario;

    private Business $business;

    private User $manicurista;

    private Client $laura;

    protected function setUp(): void
    {
        parent::setUp();

        PermissionCatalog::sync();

        $this->business = $this->makeBusiness();

        $maria = $this->makeResource($this->business, 'Maria', '08:00:00', '20:00:00');

        $this->manicurista = User::create([
            'business_id' => $this->business->id, 'name' => 'Maria',
            'email' => 'maria@prueba.test', 'password' => Hash::make('password123'),
            'is_active' => true,
        ]);
        PermissionCatalog::applyRole($this->manicurista, PermissionCatalog::ROLE_STAFF);
        $maria->update(['user_id' => $this->manicurista->id]);

        $this->laura = Client::create([
            'business_id' => $this->business->id,
            'name' => 'Laura', 'last_name' => 'Bello',
            'phone' => '3001234567', 'email' => 'laura@correo.test',
            'is_active' => true,
        ]);

        Sanctum::actingAs($this->manicurista->fresh());
    }

    private function cita(): Appointment
    {
        $desde = now($this->business->businessTimezone());

        return Appointment::create([
            'business_id' => $this->business->id,
            'client_name' => 'Sin ficha',
            'starts_at' => $desde->utc(),
            'ends_at' => $desde->copy()->addHour()->utc(),
        ]);
    }

    public function test_con_el_telefono_completo_la_encuentra(): void
    {
        $this->getJson('/api/v1/clients/lookup?phone=3001234567')
            ->assertOk()
            ->assertJsonPath('found', true)
            ->assertJsonPath('client.display_name', 'Laura B.');
    }

    public function test_la_respuesta_no_trae_telefono_ni_correo(): void
    {
        /*
         * Quien pregunta ya tiene el numero -- lo acaba de escribir -- asi que
         * devolverselo no le dice nada nuevo. El correo si le daria algo que no
         * tenia, y eso es exactamente lo que el dueño no quiere.
         */
        $respuesta = $this->getJson('/api/v1/clients/lookup?phone=3001234567')->assertOk();

        $this->assertSame(['id', 'display_name'], array_keys($respuesta->json('client')));
        $respuesta->assertDontSee('laura@correo.test')->assertDontSee('Bello');
    }

    public function test_no_se_puede_recorrer_la_base(): void
    {
        // Con un prefijo saldria un listado, que es justo lo que no puede ser.
        $this->getJson('/api/v1/clients/lookup?phone=300')->assertStatus(422);

        // Y el buscador de verdad le sigue estando prohibido.
        $this->getJson('/api/v1/clients/search?q=Laura')->assertForbidden();
        $this->getJson('/api/v1/clients')->assertForbidden();
    }

    public function test_encuentra_aunque_el_telefono_este_guardado_con_indicativo(): void
    {
        /*
         * La misma persona esta guardada como "3001234567" o "+57 300 123
         * 4567" segun quien la anoto. Exigir el formato exacto haria que el
         * buscador dijera "no existe" y se crearan fichas repetidas.
         */
        $this->laura->update(['phone' => '+57 300 123 4567']);

        $this->getJson('/api/v1/clients/lookup?phone=3001234567')
            ->assertOk()
            ->assertJsonPath('found', true);
    }

    public function test_si_no_existe_la_puede_crear(): void
    {
        $this->getJson('/api/v1/clients/lookup?phone=3009998888')
            ->assertOk()
            ->assertJsonPath('found', false);

        $id = $this->postJson('/api/v1/clients/quick', [
            'name' => 'Carolina',
            'phone' => '3009998888',
        ])->assertCreated()->json('client.id');

        $nueva = Client::withoutGlobalScope('business')->find($id);

        $this->assertSame('Carolina', $nueva->name);
        // Quien deja su numero para los sellos no esta pidiendo promociones.
        $this->assertFalse((bool) $nueva->accepts_marketing);
    }

    public function test_asociarla_a_la_cita_es_lo_que_hace_que_sume(): void
    {
        $cita = $this->cita();

        $this->assertNull($cita->client_id);

        $this->patchJson("/api/v1/appointments/{$cita->id}/client", [
            'client_id' => $this->laura->id,
        ])->assertOk();

        $cita->refresh();

        $this->assertSame($this->laura->id, $cita->client_id);
        // Y el nombre suelto se alinea: dos nombres para la misma visita
        // confunden a quien la mire despues en la agenda.
        $this->assertSame('Laura Bello', $cita->client_name);
    }

    public function test_una_cita_ya_cobrada_no_cambia_de_dueña(): void
    {
        /*
         * Despues del cobro los totales, la comision y los sellos ya se
         * calcularon sin ficha. Colgarsela ahora dejaria la cita diciendo que
         * es de Laura sin que Laura tenga su sello.
         */
        $cita = $this->cita();
        $cita->update(['checked_out_at' => now(), 'total' => 50000, 'status' => 'completed']);

        $this->patchJson("/api/v1/appointments/{$cita->id}/client", [
            'client_id' => $this->laura->id,
        ])->assertStatus(422);

        $this->assertNull($cita->fresh()->client_id);
    }
}
