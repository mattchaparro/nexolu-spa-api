<?php

namespace Tests\Feature\Locations;

use App\Models\Appointment;
use App\Models\Business;
use App\Models\Location;
use App\Models\Resource;
use App\Models\User;
use App\Support\BusinessFeaturePresets;
use App\Support\BusinessPlanLimits;
use App\Support\PermissionCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Scheduling\SchedulingScenario;
use Tests\TestCase;

/**
 * Varias sedes bajo un mismo negocio.
 *
 * Lo que se defiende acá es la frontera: clientes y catálogo son del NEGOCIO
 * -- la misma persona con la misma tarjeta de sellos en los dos locales -- y
 * la agenda y la gente son de la SEDE. Y sobre todo, que nadie termine citado
 * en el local equivocado.
 */
class LocationTest extends TestCase
{
    use RefreshDatabase, SchedulingScenario;

    private Business $business;

    private User $admin;

    private Location $chapinero;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(
            CarbonImmutable::now('America/Bogota')->startOfDay()
                ->previous(CarbonImmutable::WEDNESDAY)->setTime(8, 0),
        );

        PermissionCatalog::sync();

        $this->business = $this->makeBusiness(['min_booking_notice_min' => 0]);
        $this->chapinero = $this->business->primaryLocation();

        $this->admin = User::create([
            'business_id' => $this->business->id, 'name' => 'Dueña',
            'email' => 'admin@prueba.test', 'password' => Hash::make('password123'), 'is_active' => true,
            // La dueña: ve todas las sedes. Es lo que pasa en la realidad -- el
            // primer administrador de un negocio lo es -- y sin marcarlo estas
            // pruebas medirían a una administradora acotada a un local.
            'is_owner' => true,
        ]);
        PermissionCatalog::applyRole($this->admin, PermissionCatalog::ROLE_ADMIN);

        Sanctum::actingAs($this->admin->fresh());
    }

    private function hoy(): CarbonImmutable
    {
        return CarbonImmutable::now('America/Bogota')->startOfDay();
    }

    private function abrirCedritos(): Location
    {
        return Location::create([
            'business_id' => $this->business->id,
            'name' => 'Cedritos', 'slug' => 'cedritos',
            'is_primary' => false, 'is_active' => true,
        ]);
    }

    public function test_todo_negocio_nace_con_su_sede_principal(): void
    {
        /*
         * Sin esto, un negocio creado por un camino que nadie se acordó de
         * tocar -- el seeder, una factory -- deja a su gente sin dónde
         * trabajar y a la rejilla sin columnas.
         */
        $this->assertNotNull($this->chapinero);
        $this->assertTrue($this->chapinero->is_primary);
        $this->assertSame(1, $this->business->locations()->count());
    }

    public function test_la_sede_del_negocio_nuevo_no_cuelga_del_negocio_de_quien_lo_crea(): void
    {
        // El superadmin que da de alta un negocio está logueado en OTRO. Si la
        // sede se creara con el trait de tenancy, se la quedaría el suyo.
        $otro = $this->makeBusiness();

        $this->assertSame(
            $otro->id,
            Location::withoutGlobalScopes()->where('business_id', $otro->id)->value('business_id'),
        );
    }

    public function test_quien_atiende_pertenece_a_una_sede(): void
    {
        $cedritos = $this->abrirCedritos();

        $ana = $this->postJson('/api/v1/resources', [
            'type' => Resource::TYPE_STAFF, 'name' => 'Ana', 'location_id' => $cedritos->id,
        ])->assertCreated();

        $this->assertSame($cedritos->id, Resource::find($ana->json('id'))->location_id);
    }

    public function test_sin_sede_explicita_cae_en_la_principal(): void
    {
        // Es el negocio de un solo local, y el formulario que todavía no
        // pregunta. Nunca puede quedar en nulo: un recurso sin sede
        // desaparece del filtro de la agenda sin explicación.
        $lucia = $this->postJson('/api/v1/resources', [
            'type' => Resource::TYPE_STAFF, 'name' => 'Lucia',
        ])->assertCreated();

        $this->assertSame($this->chapinero->id, Resource::find($lucia->json('id'))->location_id);
    }

    public function test_la_sede_de_otro_negocio_es_rechazada(): void
    {
        $ajena = Location::withoutGlobalScopes()
            ->where('business_id', $this->makeBusiness()->id)
            ->first();

        $this->postJson('/api/v1/resources', [
            'type' => Resource::TYPE_STAFF, 'name' => 'Intrusa', 'location_id' => $ajena->id,
        ])->assertStatus(422);
    }

    public function test_la_cita_congela_la_sede_donde_ocurrio(): void
    {
        $cedritos = $this->abrirCedritos();
        $maria = $this->makeResource($this->business, 'Maria', '09:00:00', '18:00:00', [1, 2, 3, 4, 5, 6], $cedritos->id);
        $manicure = $this->makeService($this->business, 60, [$maria]);

        $id = $this->postJson('/api/v1/appointments', [
            'service_id' => $manicure->id, 'resource_id' => $maria->id,
            'starts_at' => $this->hoy()->format('Y-m-d').' 10:00:00',
            'client_name' => 'Carolina',
        ])->assertCreated()->json('id');

        $this->assertSame($cedritos->id, Appointment::find($id)->location_id);

        /*
         * Y se queda ahí aunque María se traslade. La caja de ese día y las
         * comisiones ya hechas no pueden cambiar de local tres meses después
         * por una decisión de personal.
         */
        $maria->update(['location_id' => $this->chapinero->id]);

        $this->assertSame($cedritos->id, Appointment::find($id)->location_id);
    }

    public function test_una_cita_no_se_reparte_entre_dos_sedes(): void
    {
        $cedritos = $this->abrirCedritos();
        $maria = $this->makeResource($this->business, 'Maria', '09:00:00', '18:00:00', [1, 2, 3, 4, 5, 6]);
        $ana = $this->makeResource($this->business, 'Ana', '09:00:00', '18:00:00', [1, 2, 3, 4, 5, 6], $cedritos->id);

        $manicure = $this->makeService($this->business, 60, [$maria], name: 'Manicure');
        $pedicure = $this->makeService($this->business, 60, [$ana], name: 'Pedicure');

        // Nadie cruza la ciudad entre servicio y servicio: eso no es una cita
        // larga, son dos visitas.
        $this->postJson('/api/v1/appointments', [
            'client_name' => 'Carolina',
            'items' => [
                ['service_id' => $manicure->id, 'resource_id' => $maria->id, 'starts_at' => $this->hoy()->format('Y-m-d').' 10:00:00'],
                ['service_id' => $pedicure->id, 'resource_id' => $ana->id, 'starts_at' => $this->hoy()->format('Y-m-d').' 11:00:00'],
            ],
        ])->assertStatus(422);
    }

    public function test_no_se_reagenda_a_alguien_de_otra_sede(): void
    {
        $cedritos = $this->abrirCedritos();
        $maria = $this->makeResource($this->business, 'Maria', '09:00:00', '18:00:00', [1, 2, 3, 4, 5, 6]);
        $ana = $this->makeResource($this->business, 'Ana', '09:00:00', '18:00:00', [1, 2, 3, 4, 5, 6], $cedritos->id);
        $manicure = $this->makeService($this->business, 60, [$maria, $ana]);

        $id = $this->postJson('/api/v1/appointments', [
            'service_id' => $manicure->id, 'resource_id' => $maria->id,
            'starts_at' => $this->hoy()->format('Y-m-d').' 10:00:00',
            'client_name' => 'Carolina',
        ])->assertCreated()->json('id');

        /*
         * Se rechaza en vez de mover la sede de la cita: quien la mueve en la
         * pantalla no es quien se aparece en la puerta equivocada.
         */
        $this->patchJson("/api/v1/appointments/{$id}/reschedule", [
            'starts_at' => $this->hoy()->format('Y-m-d').' 14:00:00',
            'resource_id' => $ana->id,
        ])->assertStatus(422);
    }

    public function test_no_se_traslada_a_alguien_con_citas_pendientes(): void
    {
        $cedritos = $this->abrirCedritos();
        $maria = $this->makeResource($this->business, 'Maria', '09:00:00', '18:00:00', [1, 2, 3, 4, 5, 6]);
        $manicure = $this->makeService($this->business, 60, [$maria]);

        $this->postJson('/api/v1/appointments', [
            'service_id' => $manicure->id, 'resource_id' => $maria->id,
            'starts_at' => $this->hoy()->format('Y-m-d').' 10:00:00',
            'client_name' => 'Carolina',
        ])->assertCreated();

        /*
         * Y dice CUÁLES. Un "no puedes" a secas obliga a ir a buscarlas a la
         * agenda, día por día, sin saber cuántas faltan. El traslado no puede
         * reagendarlas solo -- no hay forma de saber si hay hueco en el otro
         * local, ni de avisarle a cada clienta -- pero sí puede decir qué hay
         * que resolver.
         */
        $rechazo = $this->postJson("/api/v1/resources/{$maria->id}", ['location_id' => $cedritos->id])
            ->assertStatus(422);

        $this->assertCount(1, $rechazo->json('blocking'));
        $this->assertSame('Carolina', $rechazo->json('blocking.0.client_name'));
        $this->assertSame('10:00', $rechazo->json('blocking.0.time'));

        $this->assertSame($this->chapinero->id, $maria->fresh()->location_id);
    }

    public function test_mi_pagina_muestra_el_enlace_de_cada_sede(): void
    {
        // Sin esto el enlace por sede existe y nadie lo encuentra: quien
        // administra el negocio no tiene por qué deducir que a la URL se le
        // pega el slug del local.
        $this->abrirCedritos();

        $this->assertSame(
            ['principal', 'cedritos'],
            array_column($this->getJson('/api/v1/public-page')->assertOk()->json('locations'), 'slug'),
        );
    }

    public function test_con_una_sola_sede_no_hay_enlaces_que_mostrar(): void
    {
        // El enlace del negocio ya es el de esa sede: dos enlaces que llevan
        // al mismo sitio sólo generan la duda de cuál mandar.
        $this->assertSame([], $this->getJson('/api/v1/public-page')->assertOk()->json('locations'));
    }

    public function test_la_rejilla_muestra_una_sola_sede(): void
    {
        $cedritos = $this->abrirCedritos();
        $this->makeResource($this->business, 'Maria', '09:00:00', '18:00:00', [1, 2, 3, 4, 5, 6]);
        $this->makeResource($this->business, 'Ana', '09:00:00', '18:00:00', [1, 2, 3, 4, 5, 6], $cedritos->id);

        // Dos jornadas distintas en el mismo ancho no se pueden leer.
        $columnas = $this->getJson('/api/v1/agenda?from='.$this->hoy()->toDateString()
            .'&location_id='.$cedritos->id)->assertOk()->json('days.0.resources');

        $this->assertCount(1, $columnas);
        $this->assertSame('Ana', $columnas[0]['name']);
    }

    public function test_sin_sede_la_duena_ve_todas(): void
    {
        // Es lo que hacía antes de que existieran las sedes, y lo correcto
        // para el negocio de un solo local.
        $cedritos = $this->abrirCedritos();
        $this->makeResource($this->business, 'Maria', '09:00:00', '18:00:00', [1, 2, 3, 4, 5, 6]);
        $this->makeResource($this->business, 'Ana', '09:00:00', '18:00:00', [1, 2, 3, 4, 5, 6], $cedritos->id);

        $this->assertCount(2, $this->getJson('/api/v1/agenda?from='.$this->hoy()->toDateString())
            ->assertOk()->json('days.0.resources'));
    }

    public function test_la_sede_limita_la_rejilla_no_solo_la_filtra(): void
    {
        /*
         * Sin sede pedida NO vienen todas: vienen las que esa persona puede
         * ver. Es la diferencia entre un filtro y un límite, y confundirlos es
         * como la administradora de Cedritos termina leyendo la clientela de
         * Chapinero simplemente por no mandar un parámetro.
         */
        $cedritos = $this->abrirCedritos();
        $this->makeResource($this->business, 'Maria', '09:00:00', '18:00:00', [1, 2, 3, 4, 5, 6]);
        $this->makeResource($this->business, 'Ana', '09:00:00', '18:00:00', [1, 2, 3, 4, 5, 6], $cedritos->id);

        $encargada = User::create([
            'business_id' => $this->business->id, 'name' => 'Encargada',
            'email' => 'cedritos@prueba.test', 'password' => Hash::make('password123'),
            'is_active' => true,
        ]);
        PermissionCatalog::applyRole($encargada, PermissionCatalog::ROLE_ADMIN);
        $encargada->locations()->sync([$cedritos->id]);
        Sanctum::actingAs($encargada->fresh());

        $columnas = $this->getJson('/api/v1/agenda?from='.$this->hoy()->toDateString())
            ->assertOk()->json('days.0.resources');

        $this->assertCount(1, $columnas);
        $this->assertSame('Ana', $columnas[0]['name']);

        // Y pedir la ajena a mano tampoco la abre.
        $this->getJson('/api/v1/agenda?from='.$this->hoy()->toDateString()
            .'&location_id='.$this->chapinero->id)->assertStatus(422);

        // Ni el equipo: quien administra un local no tiene por qué conocer al
        // equipo del otro.
        $this->assertSame(
            ['Ana'],
            array_column($this->getJson('/api/v1/resources')->assertOk()->json(), 'name'),
        );
    }

    public function test_el_plan_no_deja_abrir_mas_sedes_de_las_contratadas(): void
    {
        $this->business->update([
            'subscription_plan' => BusinessFeaturePresets::PLAN_PRO,
            'feature_flags' => BusinessFeaturePresets::pro(),
            'plan_limits' => [BusinessPlanLimits::MAX_LOCATIONS => 1],
        ]);
        Sanctum::actingAs($this->admin->fresh());

        // El 422 dice el número: "límite alcanzado" a secas obliga a adivinar.
        $this->postJson('/api/v1/locations', ['name' => 'Cedritos'])
            ->assertStatus(422)
            ->assertJsonPath('message', fn (string $m) => str_contains($m, '1 sede'));
    }

    public function test_una_sede_se_apaga_pero_no_se_borra(): void
    {
        $cedritos = $this->abrirCedritos();

        $this->deleteJson("/api/v1/locations/{$cedritos->id}")->assertOk();

        // Lo que se atendió ahí no puede desaparecer porque el local cerró.
        $this->assertFalse($cedritos->fresh()->is_active);
        $this->assertDatabaseHas('locations', ['id' => $cedritos->id]);
    }

    public function test_no_se_apaga_una_sede_con_gente_adentro(): void
    {
        $cedritos = $this->abrirCedritos();
        $this->makeResource($this->business, 'Ana', '09:00:00', '18:00:00', [1, 2, 3, 4, 5, 6], $cedritos->id);

        $this->deleteJson("/api/v1/locations/{$cedritos->id}")->assertStatus(422);
    }

    public function test_no_se_apaga_la_sede_principal(): void
    {
        // Sin principal, todo lo que nazca sin sede explícita se queda sin
        // dónde caer.
        $this->deleteJson("/api/v1/locations/{$this->chapinero->id}")->assertStatus(422);
    }

    public function test_la_principal_es_exclusiva(): void
    {
        $cedritos = $this->abrirCedritos();

        $this->postJson("/api/v1/locations/{$cedritos->id}/primary")->assertOk();

        $this->assertTrue($cedritos->fresh()->is_primary);
        $this->assertFalse($this->chapinero->fresh()->is_primary);
    }

    public function test_la_sede_apagada_libera_el_cupo_del_plan(): void
    {
        // Mismo criterio que con la gente: se cuenta sólo lo activo.
        $cedritos = $this->abrirCedritos();
        $cedritos->update(['is_active' => false]);

        $this->assertSame(1, $this->business->fresh()->usageFor(BusinessPlanLimits::MAX_LOCATIONS));
    }
}
