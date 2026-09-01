<?php

namespace Tests\Feature\Locations;

use App\Models\Business;
use App\Models\Location;
use App\Models\Resource;
use App\Models\Service;
use App\Models\User;
use App\Support\PermissionCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Scheduling\SchedulingScenario;
use Tests\TestCase;

/**
 * Se agenda EN una sede, punto.
 *
 * Es la misma idea que la caja: nadie se hace las manos en Chapinero y los
 * pies en Cedritos, y nadie reagenda su cita del sábado mudándola de local.
 * `BookingService` ya lo rechaza al reservar, pero rechazar es el último
 * recurso: lo que se defiende acá es que la hora del otro local NUNCA LLEGUE A
 * OFRECERSE, ni en la agenda de adentro ni en la página pública.
 *
 * Un 422 después de que la clienta eligió una hora es una función que falla
 * tarde; no ofrecerla es una que no falla.
 */
class LocationBookingTest extends TestCase
{
    use RefreshDatabase, SchedulingScenario;

    private Business $business;

    private User $admin;

    private Location $chapinero;

    private Location $cedritos;

    private Resource $maria;

    private Resource $ana;

    private Service $manicure;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(
            CarbonImmutable::now('America/Bogota')->startOfDay()
                ->previous(CarbonImmutable::WEDNESDAY)->setTime(8, 0),
        );

        PermissionCatalog::sync();

        $this->business = $this->makeBusiness(['min_booking_notice_min' => 0]);
        $this->business->update(['slug' => 'spa-sedes']);

        $this->chapinero = $this->business->primaryLocation();
        $this->cedritos = Location::create([
            'business_id' => $this->business->id,
            'name' => 'Cedritos', 'slug' => 'cedritos',
            'is_primary' => false, 'is_active' => true,
        ]);

        $this->admin = User::create([
            'business_id' => $this->business->id, 'name' => 'Dueña',
            'email' => 'admin@prueba.test', 'password' => Hash::make('password123'),
            'is_active' => true, 'is_owner' => true,
        ]);
        PermissionCatalog::applyRole($this->admin, PermissionCatalog::ROLE_ADMIN);

        $this->maria = $this->makeResource($this->business, 'Maria', '09:00:00', '18:00:00', [1, 2, 3, 4, 5, 6], $this->chapinero->id);
        $this->ana = $this->makeResource($this->business, 'Ana', '09:00:00', '18:00:00', [1, 2, 3, 4, 5, 6], $this->cedritos->id);

        $this->manicure = $this->makeService($this->business, 60, [$this->maria, $this->ana]);

        Sanctum::actingAs($this->admin->fresh());
    }

    private function hoy(): CarbonImmutable
    {
        return CarbonImmutable::now('America/Bogota')->startOfDay();
    }

    // ---- Agenda de adentro ----

    public function test_los_huecos_son_solo_de_la_sede_pedida(): void
    {
        $slots = $this->getJson('/api/v1/availability?service_id='.$this->manicure->id
            .'&date='.$this->hoy()->toDateString()
            .'&location_id='.$this->cedritos->id)->assertOk()->json('slots');

        $personas = array_unique(array_column($slots, 'resource_name'));

        $this->assertSame(['Ana'], array_values($personas));
    }

    public function test_sin_sede_los_huecos_vienen_de_todas(): void
    {
        // Es lo que hacía antes de que existieran las sedes, y lo correcto
        // para el negocio de un solo local.
        $slots = $this->getJson('/api/v1/availability?service_id='.$this->manicure->id
            .'&date='.$this->hoy()->toDateString())->assertOk()->json('slots');

        $personas = array_unique(array_column($slots, 'resource_name'));
        sort($personas);

        $this->assertSame(['Ana', 'Maria'], array_values($personas));
    }

    public function test_una_cadena_no_se_reparte_entre_sedes(): void
    {
        /*
         * Sin filtro, la continuidad podría "resolverse" mandando el manicure
         * a un local y el pedicure al otro. `BookingService` lo rechaza, pero
         * recién después de que la clienta eligió esa hora.
         */
        $pedicure = $this->makeService($this->business, 60, [$this->maria, $this->ana], name: 'Pedicure');

        $slots = $this->getJson('/api/v1/availability/chain?service_ids[]='.$this->manicure->id
            .'&service_ids[]='.$pedicure->id
            .'&date='.$this->hoy()->toDateString()
            .'&location_id='.$this->cedritos->id)->assertOk()->json('slots');

        $this->assertNotEmpty($slots);

        foreach ($slots as $slot) {
            foreach ($slot['legs'] as $leg) {
                $this->assertSame('Ana', $leg['resource_name']);
            }
        }
    }

    public function test_el_equipo_se_puede_pedir_por_sede(): void
    {
        // Es lo que hace que el modal de agendar y el de reagendar no ofrezcan
        // a alguien del otro local.
        $nombres = array_column(
            $this->getJson('/api/v1/resources?location_id='.$this->chapinero->id)->assertOk()->json(),
            'name',
        );

        $this->assertSame(['Maria'], $nombres);
    }

    // ---- Página pública ----

    public function test_la_pagina_publica_lista_las_sedes(): void
    {
        $body = $this->getJson('/api/v1/public/spa-sedes')->assertOk();

        $this->assertCount(2, $body->json('locations'));
        // Sin sede elegida no se asume ninguna: con dos locales, la clienta
        // tiene que decir a cuál va.
        $this->assertNull($body->json('location'));
    }

    public function test_el_enlace_de_una_sede_solo_muestra_su_equipo(): void
    {
        $body = $this->getJson('/api/v1/public/spa-sedes?location=cedritos')->assertOk();

        $this->assertSame('Cedritos', $body->json('location.name'));
        $this->assertSame(['Ana'], array_column($body->json('resources'), 'name'));
    }

    public function test_con_una_sola_sede_se_elige_sola(): void
    {
        // La clienta de un negocio de un local no tiene por qué ver un paso
        // que sólo tiene una respuesta.
        $this->cedritos->update(['is_active' => false]);

        $body = $this->getJson('/api/v1/public/spa-sedes')->assertOk();

        $this->assertCount(1, $body->json('locations'));
        $this->assertSame('Principal', $body->json('location.name'));
    }

    public function test_un_enlace_de_sede_que_ya_no_existe_lleva_al_negocio(): void
    {
        /*
         * El enlace viejo de una sede que cerró tiene que llevar a la página
         * del negocio, no a un error. La clienta que lo guardó en WhatsApp
         * hace seis meses no tiene por qué enterarse de eso.
         */
        $body = $this->getJson('/api/v1/public/spa-sedes?location=inventada')->assertOk();

        $this->assertNull($body->json('location'));
        $this->assertCount(2, $body->json('locations'));
    }

    public function test_los_huecos_publicos_son_de_la_sede_del_enlace(): void
    {
        $slots = $this->getJson('/api/v1/public/spa-sedes/availability?service_id='.$this->manicure->id
            .'&date='.$this->hoy()->toDateString()
            .'&location=cedritos')->assertOk()->json('slots');

        $this->assertNotEmpty($slots);
        $this->assertSame(['Ana'], array_values(array_unique(array_column($slots, 'resource_name'))));
    }

    public function test_el_calendario_publico_no_pinta_dias_del_otro_local(): void
    {
        // Ana sólo trabaja lunes. Si el calendario de Cedritos mostrara los
        // días de María, la clienta descubriría el error un paso después.
        $this->ana->schedules()->where('weekday', '!=', 1)->delete();

        $dias = collect($this->getJson('/api/v1/public/spa-sedes/days?service_id='.$this->manicure->id
            .'&from='.$this->hoy()->startOfWeek()->toDateString()
            .'&days=7&location=cedritos')->assertOk()->json('days'))
            ->where('has_slots', true)
            ->pluck('date');

        foreach ($dias as $dia) {
            $this->assertSame(1, CarbonImmutable::parse($dia)->isoWeekday());
        }
    }

    public function test_la_direccion_publica_es_la_de_la_sede(): void
    {
        // Es lo único que evita que alguien llegue al local equivocado con una
        // cita perfectamente válida.
        $this->cedritos->update(['address' => 'Calle 140 #12-30']);

        $this->assertSame(
            'Calle 140 #12-30',
            $this->getJson('/api/v1/public/spa-sedes?location=cedritos')->assertOk()->json('business.address'),
        );
    }
}
