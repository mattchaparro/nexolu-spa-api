<?php

namespace Tests\Feature\Messaging;

use App\Models\Broadcast;
use App\Models\Business;
use App\Models\Client;
use App\Models\Message;
use App\Models\Resource;
use App\Models\Service;
use App\Models\User;
use App\Services\Messaging\BroadcastService;
use App\Services\Scheduling\BookingService;
use App\Support\PermissionCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Scheduling\SchedulingScenario;
use Tests\TestCase;

/**
 * Difusiones: la misma promocion a muchas, ahora o programada.
 *
 * Lo que se defiende, en orden de gravedad:
 *
 *  1. Que NO le llegue a quien no pidio promociones. No es pulcritud: en
 *     Colombia lo regula la Ley 1581, y para Meta es la via mas rapida a que
 *     le bajen la calidad al numero. Un numero castigado deja al negocio sin
 *     recordatorios, que es lo que de verdad le importa.
 *  2. Que no salga dos veces.
 *  3. Que salga como PLANTILLA: una difusion la inicia el negocio, fuera de
 *     la ventana de 24h, y como texto libre WhatsApp la rechaza.
 */
class BroadcastTest extends TestCase
{
    use RefreshDatabase, SchedulingScenario;

    private Business $business;

    private User $duena;

    private Resource $maria;

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
        $this->maria = $this->makeResource($this->business, 'Maria');
        $this->manicure = $this->makeService($this->business, 60, [$this->maria]);

        $this->duena = User::create([
            'business_id' => $this->business->id,
            'name' => 'Dueña',
            'email' => 'duena@prueba.test',
            'password' => Hash::make('password123'),
            'is_active' => true,
            'is_owner' => true,
        ]);
        PermissionCatalog::applyRole($this->duena, PermissionCatalog::ROLE_ADMIN);

        Sanctum::actingAs($this->duena->fresh());
    }

    private function clienta(string $nombre, bool $acepta = true): Client
    {
        return Client::create([
            'business_id' => $this->business->id,
            'name' => $nombre,
            'phone' => '57300'.random_int(1000000, 9999999),
            'accepts_marketing' => $acepta,
            'is_active' => true,
        ]);
    }

    private function difusion(array $overrides = []): Broadcast
    {
        return Broadcast::create(array_merge([
            'business_id' => $this->business->id,
            'name' => 'Promo de prueba',
            'template_name' => 'promo_septiembre',
            'template_language' => 'es',
            'template_params' => ['{nombre}', '{negocio}'],
            'body_template' => 'Hola {nombre}, promo en {negocio}.',
            'status' => Broadcast::STATUS_DRAFT,
        ], $overrides));
    }

    /**
     * Le crea N visitas pasadas, cobradas salvo que se diga lo contrario.
     *
     * Cada visita ocupa un hueco distinto: dos clientas el mismo dia a la
     * misma hora chocan contra el indice anti-solape, que es justo lo que ese
     * indice existe para hacer.
     */
    private function visitar(Client $cliente, int $cuantas, bool $cobrar = true): void
    {
        for ($i = 1; $i <= $cuantas; $i++) {
            $cita = $this->app->make(BookingService::class)->book(
                $this->business,
                [['service_id' => $this->manicure->id, 'resource_id' => $this->maria->id,
                    'starts_at' => CarbonImmutable::now('America/Bogota')
                        ->subDays($i)->setTime(8 + (self::$hueco++ % 8), 0)]],
                $cliente,
                enforceSchedule: false,
            );

            if ($cobrar) {
                $cita->forceFill(['checked_out_at' => now()->subDays($i)])->save();
            }
        }
    }

    /** Contador de huecos, para que dos visitas nunca caigan en el mismo. */
    private static int $hueco = 0;

    private function service(): BroadcastService
    {
        return $this->app->make(BroadcastService::class);
    }

    /*
    |--------------------------------------------------------------------------
    | A quien SI y a quien NO
    |--------------------------------------------------------------------------
    */

    public function test_no_le_llega_a_quien_no_acepta_promociones(): void
    {
        $this->clienta('Carolina', acepta: true);
        $this->clienta('Lucia', acepta: false);

        $enviados = $this->service()->dispatch($this->difusion());

        $this->assertSame(1, $enviados);
        $this->assertSame('Carolina', Message::withoutGlobalScopes()->first()->client->name);
    }

    public function test_no_le_llega_a_una_ficha_inactiva(): void
    {
        $this->clienta('Carolina');
        $this->clienta('Lucia')->update(['is_active' => false]);

        $this->assertSame(1, $this->service()->dispatch($this->difusion()));
    }

    public function test_no_le_llega_a_quien_no_tiene_telefono(): void
    {
        $this->clienta('Carolina');
        Client::create([
            'business_id' => $this->business->id,
            'name' => 'Sin teléfono',
            'accepts_marketing' => true,
            'is_active' => true,
        ]);

        $this->assertSame(1, $this->service()->dispatch($this->difusion()));
    }

    public function test_no_le_llega_a_las_clientas_de_otro_negocio(): void
    {
        $this->clienta('Carolina');

        /*
         * Inserción directa: `BelongsToBusiness` PISA el business_id al crear
         * si hay sesión, así que `Client::create` con el id de otro negocio
         * produciría una clienta de ESTE. La ficha ajena tiene que nacer por
         * fuera del modelo para que la prueba pruebe algo.
         */
        $otro = $this->makeBusiness();
        DB::table('clients')->insert([
            'business_id' => $otro->id,
            'name' => 'Ajena',
            'phone' => '573009998877',
            'accepts_marketing' => true,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame(1, $this->service()->dispatch($this->difusion()));
    }

    public function test_el_filtro_de_reactivacion_encuentra_a_quien_no_vuelve(): void
    {
        /*
         * La campana mas util de un spa: "no vienes desde hace meses". Se
         * mira la visita COBRADA -- una cita agendada y no atendida no dice
         * nada de nadie.
         */
        $fiel = $this->clienta('Carolina');
        $this->clienta('Lucia');

        $cita = $this->app->make(BookingService::class)->book(
            $this->business,
            [['service_id' => $this->manicure->id, 'resource_id' => $this->maria->id,
                'starts_at' => CarbonImmutable::now('America/Bogota')->subDays(3)->setTime(10, 0)]],
            $fiel,
            enforceSchedule: false,
        );
        $cita->forceFill(['checked_out_at' => now()->subDays(3)])->save();

        $difusion = $this->difusion([
            'audience' => ['not_visited_since' => CarbonImmutable::now()->subDays(30)->toDateString()],
        ]);

        $this->service()->dispatch($difusion);

        // Solo Lucia, que nunca ha venido. Carolina vino hace tres dias.
        $nombres = Message::withoutGlobalScopes()->with('client')->get()->pluck('client.name');
        $this->assertSame(['Lucia'], $nombres->all());
    }

    public function test_se_puede_mandar_solo_a_las_frecuentes(): void
    {
        /*
         * La otra mitad de "¿a quién le mando esto?".
         *
         * Con solo fechas, "premiar a la fiel" y "traer de vuelta a la que
         * vino una vez" le llegaban a la misma gente: las dos son "vino hace
         * poco". Lo que las separa es cuántas veces.
         */
        $fiel = $this->clienta('Carolina');
        $unaVez = $this->clienta('Lucia');

        $this->visitar($fiel, 5);
        $this->visitar($unaVez, 1);

        $this->service()->dispatch($this->difusion([
            'audience' => ['min_visits' => 5],
        ]));

        $nombres = Message::withoutGlobalScopes()->with('client')->get()->pluck('client.name');
        $this->assertSame(['Carolina'], $nombres->all());
    }

    public function test_se_puede_mandar_solo_a_las_que_vinieron_una_vez(): void
    {
        $fiel = $this->clienta('Carolina');
        $unaVez = $this->clienta('Lucia');

        $this->visitar($fiel, 5);
        $this->visitar($unaVez, 1);

        $this->service()->dispatch($this->difusion([
            'audience' => ['max_visits' => 1],
        ]));

        $nombres = Message::withoutGlobalScopes()->with('client')->get()->pluck('client.name');
        $this->assertSame(['Lucia'], $nombres->all());
    }

    public function test_una_cita_agendada_y_no_atendida_no_hace_frecuente_a_nadie(): void
    {
        /*
         * Se cuentan las visitas COBRADAS. Sin eso, quien agenda y nunca
         * aparece terminaria en la campaña de las fieles.
         */
        $fantasma = $this->clienta('Carolina');

        $this->visitar($fantasma, 6, cobrar: false);

        $this->service()->dispatch($this->difusion([
            'audience' => ['min_visits' => 5],
        ]));

        $this->assertSame(0, Message::withoutGlobalScopes()->count());
    }

    /*
    |--------------------------------------------------------------------------
    | Como sale
    |--------------------------------------------------------------------------
    */

    public function test_sale_como_plantilla_con_las_variables_resueltas(): void
    {
        $this->clienta('Carolina');

        $this->service()->dispatch($this->difusion());

        $mensaje = Message::withoutGlobalScopes()->first();

        $this->assertSame('promo_septiembre', $mensaje->template_name);
        // Los marcadores se resuelven por destinataria, no quedan literales.
        $this->assertSame('Carolina', $mensaje->template_params[0]);
        $this->assertStringContainsString('Carolina', $mensaje->body);
        $this->assertStringNotContainsString('{nombre}', $mensaje->body);
    }

    public function test_no_sale_dos_veces_aunque_se_despache_dos_veces(): void
    {
        /*
         * El comando puede correr dos veces, o alguien puede volver a tocar
         * "enviar". La garantia es el indice unico (broadcast_id, client_id),
         * no un contador -- un contador se desincroniza.
         */
        $this->clienta('Carolina');
        $difusion = $this->difusion();

        $this->service()->dispatch($difusion);
        $difusion->update(['status' => Broadcast::STATUS_DRAFT]);
        $this->service()->dispatch($difusion);

        $this->assertSame(1, Message::withoutGlobalScopes()->count());
    }

    /*
    |--------------------------------------------------------------------------
    | Programarla
    |--------------------------------------------------------------------------
    */

    public function test_el_comando_solo_recoge_las_que_ya_tocaban(): void
    {
        $this->clienta('Carolina');

        $this->difusion([
            'status' => Broadcast::STATUS_SCHEDULED,
            'scheduled_at' => now()->subMinutes(5),
        ]);
        $this->difusion([
            'name' => 'La de mañana',
            'status' => Broadcast::STATUS_SCHEDULED,
            'scheduled_at' => now()->addDay(),
        ]);

        $this->artisan('difusiones:enviar')->assertSuccessful();

        $this->assertSame(1, Message::withoutGlobalScopes()->count());
    }

    public function test_una_difusion_cancelada_no_sale(): void
    {
        $this->clienta('Carolina');

        $this->difusion([
            'status' => Broadcast::STATUS_CANCELLED,
            'scheduled_at' => now()->subMinutes(5),
        ]);

        $this->artisan('difusiones:enviar')->assertSuccessful();

        $this->assertSame(0, Message::withoutGlobalScopes()->count());
    }

    /*
    |--------------------------------------------------------------------------
    | La pantalla
    |--------------------------------------------------------------------------
    */

    public function test_el_conteo_previo_dice_a_cuantas_llegaria(): void
    {
        // Mandar una difusion no se puede deshacer: ver el numero ANTES es la
        // unica oportunidad de notar que el filtro estaba mal.
        $this->clienta('Carolina');
        $this->clienta('Lucia');
        $this->clienta('Sara', acepta: false);

        $difusion = $this->difusion();

        $r = $this->getJson("/api/v1/broadcasts/{$difusion->id}/preview")->assertOk();

        $this->assertSame(2, $r->json('count'));
    }

    public function test_no_se_puede_programar_para_el_pasado(): void
    {
        $this->postJson('/api/v1/broadcasts', [
            'name' => 'Tarde',
            'template_name' => 'promo',
            'body_template' => 'Hola',
            'scheduled_at' => now()->subHour()->toIso8601String(),
        ])->assertStatus(422);
    }

    public function test_una_difusion_que_ya_salio_no_se_edita(): void
    {
        /*
         * Parte de las clientas ya la recibio: editarla ahora seria mandar
         * dos versiones distintas de la misma promocion.
         */
        $difusion = $this->difusion(['status' => Broadcast::STATUS_SENT]);

        $this->putJson("/api/v1/broadcasts/{$difusion->id}", [
            'name' => 'Otra cosa',
            'template_name' => 'promo',
            'body_template' => 'Hola',
        ])->assertStatus(422);
    }

    public function test_una_difusion_de_otro_negocio_no_existe(): void
    {
        // Misma razón: la difusión ajena nace por fuera del modelo.
        $otro = $this->makeBusiness();
        $ajenaId = DB::table('broadcasts')->insertGetId([
            'business_id' => $otro->id,
            'name' => 'Ajena',
            'template_name' => 'promo',
            'template_language' => 'es',
            'body_template' => 'Hola',
            'status' => Broadcast::STATUS_DRAFT,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->getJson("/api/v1/broadcasts/{$ajenaId}/preview")->assertNotFound();
        $this->postJson("/api/v1/broadcasts/{$ajenaId}/send")->assertNotFound();
    }
}
