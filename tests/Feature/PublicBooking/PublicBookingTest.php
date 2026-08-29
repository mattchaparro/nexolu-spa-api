<?php

namespace Tests\Feature\PublicBooking;

use App\Models\Appointment;
use App\Models\Business;
use App\Models\Client;
use App\Models\ClientPenalty;
use App\Models\Resource;
use App\Models\ResourceBreak;
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
 * La pagina publica de un negocio.
 *
 * Lo que se defiende es lo que Blue Souls tenia mal: `/api/external/*` corria
 * con throttle y nada mas -- crear y borrar citas, aplicar penalizaciones y
 * enumerar clientes con telefono, sin credenciales. Aca lo publico solo lee el
 * catalogo y crea una cita para si mismo.
 *
 * Y algo que solo pasa sin sesion: el scope global de `BelongsToBusiness` no
 * filtra, asi que una consulta sin `where('business_id')` explicito devolveria
 * las filas de todos los negocios. Hay pruebas para eso.
 */
class PublicBookingTest extends TestCase
{
    use RefreshDatabase, SchedulingScenario;

    private Business $business;

    private Resource $maria;

    private Service $service;

    protected function setUp(): void
    {
        parent::setUp();

        /*
         * El limitador vive en cache, no en la base, asi que `RefreshDatabase`
         * no lo toca y el contador se arrastra de una prueba a la siguiente:
         * la sexta reserva de la CLASE daba 429 aunque fuera la primera de su
         * test. Se limpia aca y el throttle tiene su prueba propia mas abajo.
         */
        \Illuminate\Support\Facades\Cache::flush();

        PermissionCatalog::sync();

        $this->business = $this->makeBusiness(['min_booking_notice_min' => 0]);
        $this->business->update([
            'name' => 'Luxury Nails',
            'slug' => 'luxury-nails',
            'address' => 'Calle 10 #4-20',
            'phone' => '573001112233',
        ]);

        $this->maria = $this->makeResource($this->business, 'Maria', '09:00:00', '17:00:00', [3]);
        $this->service = $this->makeService($this->business, 60, [$this->maria]);
        $this->service->update([
            'name' => 'Manicure clásico',
            'price' => 45000,
            'is_bookable_online' => true,
        ]);
    }

    private function url(string $path = ''): string
    {
        return '/api/v1/public/luxury-nails'.$path;
    }

    private function reservar(array $overrides = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson($this->url('/appointments'), array_merge([
            'service_id' => $this->service->id,
            'resource_id' => $this->maria->id,
            'starts_at' => $this->wednesday()->format('Y-m-d').' 10:00:00',
            'client_name' => 'Carolina Restrepo',
            'client_phone' => '3001234567',
        ], $overrides));
    }

    /*
    |--------------------------------------------------------------------------
    | La pagina
    |--------------------------------------------------------------------------
    */

    public function test_la_pagina_dice_quien_es_el_negocio(): void
    {
        $pagina = $this->getJson($this->url())->assertOk();

        $this->assertSame('Luxury Nails', $pagina->json('business.name'));
        $this->assertSame('Calle 10 #4-20', $pagina->json('business.address'));
        // Sin nada escrito, el titular cae al nombre y el WhatsApp al teléfono
        // que ya estaba cargado: una página a medio llenar es peor que ninguna.
        $this->assertSame('Luxury Nails', $pagina->json('profile.headline'));
        $this->assertSame('573001112233', $pagina->json('profile.whatsapp'));
    }

    public function test_el_perfil_escrito_manda_sobre_los_valores_por_defecto(): void
    {
        $this->business->update(['public_profile' => [
            'headline' => 'Uñas que hablan por ti',
            'about' => 'Diez años en el barrio.',
            'instagram' => '@luxurynails',
        ]]);

        $pagina = $this->getJson($this->url())->assertOk();

        $this->assertSame('Uñas que hablan por ti', $pagina->json('profile.headline'));
        // El arroba se convierte en URL: pegarlo crudo en un href produce un
        // enlace roto que nadie prueba hasta que un cliente lo toca.
        $this->assertSame('https://instagram.com/luxurynails', $pagina->json('profile.instagram'));
    }

    public function test_el_horario_sale_del_horario_real_del_equipo(): void
    {
        $horas = collect($this->getJson($this->url())->assertOk()->json('hours'));

        $miercoles = $horas->firstWhere('weekday', 3);
        $this->assertSame('09:00', $miercoles['opens']);
        $this->assertSame('17:00', $miercoles['closes']);

        // Maria solo trabaja miércoles: el resto sale cerrado.
        $this->assertNull($horas->firstWhere('weekday', 1)['opens']);
    }

    public function test_el_cierre_de_mediodia_del_local_aparece_en_el_horario(): void
    {
        ResourceBreak::create([
            'business_id' => $this->business->id, 'resource_id' => null, 'weekday' => null,
            'start_time' => '13:00:00', 'end_time' => '14:00:00', 'label' => 'Cierre de mediodía',
            'effective_from' => '2020-01-01', 'is_active' => true,
        ]);

        $miercoles = collect($this->getJson($this->url())->json('hours'))->firstWhere('weekday', 3);

        $this->assertSame('Cierre de mediodía', $miercoles['breaks'][0]['label']);
    }

    public function test_el_almuerzo_de_una_profesional_no_es_el_horario_del_local(): void
    {
        ResourceBreak::create([
            'business_id' => $this->business->id, 'resource_id' => $this->maria->id, 'weekday' => null,
            'start_time' => '13:00:00', 'end_time' => '14:00:00', 'label' => 'Almuerzo de Maria',
            'effective_from' => '2020-01-01', 'is_active' => true,
        ]);

        $miercoles = collect($this->getJson($this->url())->json('hours'))->firstWhere('weekday', 3);

        $this->assertSame([], $miercoles['breaks']);
    }

    /*
    |--------------------------------------------------------------------------
    | El catalogo
    |--------------------------------------------------------------------------
    */

    public function test_solo_se_muestran_los_servicios_ofrecidos_por_internet(): void
    {
        $privado = $this->makeService($this->business, 90, [$this->maria]);
        $privado->update(['name' => 'Valoración', 'is_bookable_online' => false]);

        $nombres = array_column($this->getJson($this->url('/services'))->assertOk()->json(), 'name');

        $this->assertSame(['Manicure clásico'], $nombres);
    }

    public function test_solo_se_muestra_el_equipo_que_atiende_por_internet(): void
    {
        $interna = $this->makeResource($this->business, 'Interna', '09:00:00', '17:00:00', [3]);
        $interna->update(['is_bookable_online' => false]);

        $nombres = array_column($this->getJson($this->url())->json('resources'), 'name');

        $this->assertSame(['Maria'], $nombres);
    }

    public function test_los_huecos_respetan_el_almuerzo(): void
    {
        ResourceBreak::create([
            'business_id' => $this->business->id, 'resource_id' => $this->maria->id, 'weekday' => null,
            'start_time' => '13:00:00', 'end_time' => '14:00:00', 'label' => 'Almuerzo',
            'effective_from' => '2020-01-01', 'is_active' => true,
        ]);

        $horas = array_column(
            $this->getJson($this->url('/availability?service_id='.$this->service->id
                .'&date='.$this->wednesday()->format('Y-m-d')))->assertOk()->json('slots'),
            'label',
        );

        $this->assertContains('12:00 pm', $horas);
        $this->assertNotContains('1:00 pm', $horas);
    }

    public function test_los_dias_dicen_solo_si_hay_o_no_hay(): void
    {
        $dias = collect($this->getJson($this->url('/days?service_id='.$this->service->id
            .'&from='.$this->wednesday()->format('Y-m-d')))->assertOk()->json('days'));

        // Miércoles sí, jueves no: Maria solo trabaja miércoles.
        $this->assertTrue($dias->firstWhere('date', $this->wednesday()->toDateString())['has_slots']);
        $this->assertFalse($dias->firstWhere('date', $this->wednesday()->addDay()->toDateString())['has_slots']);
    }

    /*
    |--------------------------------------------------------------------------
    | Reservar
    |--------------------------------------------------------------------------
    */

    public function test_reservar_crea_la_cita_y_la_ficha_del_cliente(): void
    {
        $respuesta = $this->reservar()->assertCreated();

        $this->assertSame('Manicure clásico', $respuesta->json('service'));
        $this->assertSame('Maria', $respuesta->json('resource'));
        $this->assertSame('10:00 am', $respuesta->json('time_label'));

        $cita = Appointment::withoutGlobalScope('business')->latest('id')->first();
        $this->assertSame(Appointment::SOURCE_ONLINE, $cita->source);
        $this->assertSame($this->business->id, $cita->business_id);

        // La ficha se crea: así crece la base de clientes del negocio.
        $this->assertDatabaseHas('clients', [
            'business_id' => $this->business->id,
            'phone' => '573001234567',
        ]);
    }

    public function test_la_respuesta_no_devuelve_la_ficha_del_cliente(): void
    {
        Client::create([
            'business_id' => $this->business->id, 'name' => 'Carolina',
            'last_name' => 'Restrepo', 'phone' => '573001234567',
        ]);

        $respuesta = $this->reservar()->assertCreated();

        // Si devolviera la ficha, cualquiera podría escribir el teléfono de
        // otra persona y leerse su nombre y sus citas: un enumerador de
        // clientela servido por el propio formulario de reservas.
        $this->assertArrayNotHasKey('client', $respuesta->json());
        $this->assertArrayNotHasKey('client_id', $respuesta->json());
        $this->assertArrayNotHasKey('client_name', $respuesta->json());
    }

    public function test_dos_reservas_al_mismo_hueco_solo_deja_pasar_una(): void
    {
        $this->reservar()->assertCreated();

        $this->reservar(['client_name' => 'Otra', 'client_phone' => '3009998877'])
            ->assertStatus(409);
    }

    public function test_no_se_reserva_encima_del_almuerzo(): void
    {
        ResourceBreak::create([
            'business_id' => $this->business->id, 'resource_id' => $this->maria->id, 'weekday' => null,
            'start_time' => '13:00:00', 'end_time' => '14:00:00', 'label' => 'Almuerzo',
            'effective_from' => '2020-01-01', 'is_active' => true,
        ]);

        // Mandando la hora a mano, que es lo que haría alguien con curl.
        $this->reservar(['starts_at' => $this->wednesday()->format('Y-m-d').' 13:00:00'])
            ->assertStatus(422);
    }

    public function test_no_se_reserva_un_servicio_que_no_se_ofrece_por_internet(): void
    {
        $this->service->update(['is_bookable_online' => false]);

        $this->reservar()->assertNotFound();
    }

    public function test_un_telefono_invalido_se_rechaza(): void
    {
        $this->reservar(['client_phone' => 'no soy un teléfono'])->assertStatus(422);
    }

    public function test_no_se_puede_llenar_la_agenda_con_un_solo_telefono(): void
    {
        foreach (['10:00', '11:00', '12:00'] as $hora) {
            $this->reservar(['starts_at' => $this->wednesday()->format('Y-m-d')." {$hora}:00"])
                ->assertCreated();
        }

        // Sin freno, un formulario abierto deja tomar el día entero en un minuto.
        $this->reservar(['starts_at' => $this->wednesday()->format('Y-m-d').' 14:00:00'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Ya tienes 3 citas reservadas. Llámanos si necesitas otra.');
    }

    public function test_el_formulario_de_reservas_esta_limitado(): void
    {
        // Mas apretado que el de lectura: mirar la pagina es gratis, llenar la
        // agenda no. Es la ultima linea si alguien automatiza el formulario
        // rotando telefonos, que el limite por telefono no detendria.
        for ($i = 0; $i < 5; $i++) {
            $this->reservar([
                'client_phone' => '30000000'.$i.'0',
                'starts_at' => $this->wednesday()->format('Y-m-d').' 1'.$i.':00:00',
            ]);
        }

        $this->reservar(['client_phone' => '3009999999'])->assertStatus(429);
    }

    public function test_quien_falta_mucho_tiene_que_llamar(): void
    {
        $cliente = Client::create([
            'business_id' => $this->business->id, 'name' => 'Carolina', 'phone' => '573001234567',
        ]);

        for ($i = 0; $i < 3; $i++) {
            ClientPenalty::create([
                'business_id' => $this->business->id, 'client_id' => $cliente->id,
                'kind' => ClientPenalty::KIND_NO_SHOW, 'amount' => 0,
            ]);
        }

        // No se le cierra la puerta: se le pide llamar. El negocio la quiere;
        // lo que no quiere es la reserva desatendida, que sale gratis.
        $this->reservar()->assertStatus(422)
            ->assertJsonPath('message', 'No podemos reservarte en línea. Llámanos y con gusto te agendamos.');
    }

    public function test_una_falta_perdonada_no_cuenta(): void
    {
        $cliente = Client::create([
            'business_id' => $this->business->id, 'name' => 'Carolina', 'phone' => '573001234567',
        ]);

        for ($i = 0; $i < 3; $i++) {
            ClientPenalty::create([
                'business_id' => $this->business->id, 'client_id' => $cliente->id,
                'kind' => ClientPenalty::KIND_NO_SHOW, 'amount' => 0,
                'waived_at' => now(),
            ]);
        }

        $this->reservar()->assertCreated();
    }

    /*
    |--------------------------------------------------------------------------
    | Lo que la pagina publica NO puede hacer
    |--------------------------------------------------------------------------
    */

    public function test_sin_la_reserva_en_linea_encendida_la_pagina_no_existe(): void
    {
        $flags = $this->business->feature_flags;
        $flags['online_booking'] = false;
        $this->business->update(['feature_flags' => $flags]);

        // 404 y no 403: que un negocio exista pero tenga la reserva apagada no
        // es asunto de quien pasa por la URL.
        $this->getJson($this->url())->assertNotFound();
        $this->reservar()->assertNotFound();
    }

    public function test_un_negocio_suspendido_no_tiene_pagina(): void
    {
        $this->business->update(['is_active' => false]);

        $this->getJson($this->url())->assertNotFound();
    }

    public function test_no_se_ven_los_servicios_de_otro_negocio(): void
    {
        /*
         * La prueba que solo tiene sentido sin sesion: el scope global de
         * `BelongsToBusiness` no filtra cuando no hay usuario, asi que una
         * consulta sin `where('business_id')` explicito devolveria el catalogo
         * de todos los spas de la plataforma en la pagina de este.
         */
        $otro = $this->makeBusiness();
        $otro->update(['slug' => 'otro-spa']);
        $suRecurso = $this->makeResource($otro, 'Ajena', '09:00:00', '17:00:00', [3]);
        $suServicio = $this->makeService($otro, 60, [$suRecurso]);
        $suServicio->update(['name' => 'Servicio ajeno', 'is_bookable_online' => true]);

        $pagina = $this->getJson($this->url())->assertOk();

        $this->assertSame(['Manicure clásico'], array_column($pagina->json('services'), 'name'));
        $this->assertSame(['Maria'], array_column($pagina->json('resources'), 'name'));

        // Y tampoco se puede reservar el servicio de otro desde esta página.
        $this->reservar(['service_id' => $suServicio->id, 'resource_id' => $suRecurso->id])
            ->assertNotFound();
    }

    public function test_no_hay_forma_de_cancelar_ni_de_listar_citas(): void
    {
        $cita = $this->reservar()->assertCreated()->json('reference');

        // Blue Souls dejaba las dos cosas sin credenciales.
        $this->getJson($this->url('/appointments'))->assertStatus(405);
        $this->postJson($this->url("/appointments/{$cita}/cancel"))->assertNotFound();
        $this->getJson($this->url('/clients'))->assertNotFound();
    }

    /*
    |--------------------------------------------------------------------------
    | La pagina desde adentro
    |--------------------------------------------------------------------------
    */

    public function test_el_negocio_edita_su_pagina(): void
    {
        $admin = User::create([
            'business_id' => $this->business->id, 'name' => 'Ana',
            'email' => 'ana@prueba.test', 'password' => Hash::make('password123'), 'is_active' => true,
        ]);
        PermissionCatalog::applyRole($admin, PermissionCatalog::ROLE_ADMIN);
        Sanctum::actingAs($admin->fresh());

        $this->postJson('/api/v1/public-page', [
            'headline' => 'Uñas que hablan por ti',
            'about' => 'Diez años en el barrio.',
            'instagram' => '@luxurynails',
        ])->assertOk()->assertJsonPath('profile.headline', 'Uñas que hablan por ti');

        // Y se ve en la página pública.
        $this->assertSame(
            'Uñas que hablan por ti',
            $this->getJson($this->url())->json('profile.headline'),
        );
    }

    public function test_el_negocio_elige_que_servicios_se_ofrecen(): void
    {
        $otro = $this->makeService($this->business, 30, [$this->maria]);
        $otro->update(['name' => 'Retoque', 'is_bookable_online' => false]);

        $admin = User::create([
            'business_id' => $this->business->id, 'name' => 'Ana',
            'email' => 'ana@prueba.test', 'password' => Hash::make('password123'), 'is_active' => true,
        ]);
        PermissionCatalog::applyRole($admin, PermissionCatalog::ROLE_ADMIN);
        Sanctum::actingAs($admin->fresh());

        $this->putJson('/api/v1/public-page/services', ['service_ids' => [$otro->id]])->assertOk();

        // Ahora se ofrece el retoque y ya no el manicure.
        $this->assertSame(
            ['Retoque'],
            array_column($this->getJson($this->url('/services'))->json(), 'name'),
        );
    }

    public function test_una_profesional_no_edita_la_pagina_publica(): void
    {
        $staff = User::create([
            'business_id' => $this->business->id, 'name' => 'Maria',
            'email' => 'maria@prueba.test', 'password' => Hash::make('password123'), 'is_active' => true,
        ]);
        PermissionCatalog::applyRole($staff, PermissionCatalog::ROLE_STAFF);
        Sanctum::actingAs($staff->fresh());

        $this->getJson('/api/v1/public-page')->assertForbidden();
        $this->postJson('/api/v1/public-page', ['headline' => 'Mío'])->assertForbidden();
    }
}
