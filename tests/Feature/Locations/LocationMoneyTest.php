<?php

namespace Tests\Feature\Locations;

use App\Models\Business;
use App\Models\CashClosing;
use App\Models\CashShift;
use App\Models\Expense;
use App\Models\Location;
use App\Models\PaymentMethod;
use App\Models\Resource;
use App\Models\Service;
use App\Models\User;
use App\Support\PermissionCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Scheduling\SchedulingScenario;
use Tests\TestCase;

/**
 * El dinero, por sede.
 *
 * El efectivo es FÍSICO: hay un cajón en Chapinero y otro en Cedritos. Un
 * cierre que sume los dos no se puede cuadrar contra ninguno, y la diferencia
 * -- el único dato que importa de un cierre -- deja de significar nada.
 *
 * Eso es lo que se defiende acá, y de paso quién puede mirar qué: el dueño
 * todo, los demás lo suyo.
 */
class LocationMoneyTest extends TestCase
{
    use RefreshDatabase, SchedulingScenario;

    private Business $business;

    private User $duena;

    private Location $chapinero;

    private Location $cedritos;

    private Resource $maria;

    private Resource $ana;

    private Service $manicure;

    private PaymentMethod $efectivo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(
            CarbonImmutable::now('America/Bogota')->startOfDay()
                ->previous(CarbonImmutable::WEDNESDAY)->setTime(8, 0),
        );

        PermissionCatalog::sync();

        $this->business = $this->makeBusiness(['min_booking_notice_min' => 0]);
        // El turno viene apagado incluso en el plan full: es una forma
        // distinta de operar la caja, no una función avanzada. Acá se
        // enciende porque es justo lo que se está probando.
        $this->business->update([
            'feature_flags' => array_merge($this->business->feature_flags, ['cash_shift' => true]),
        ]);
        $this->chapinero = $this->business->primaryLocation();
        $this->cedritos = Location::create([
            'business_id' => $this->business->id,
            'name' => 'Cedritos', 'slug' => 'cedritos',
            'is_primary' => false, 'is_active' => true,
        ]);

        $this->duena = $this->hacerUsuario('duena@prueba.test', true);

        $this->efectivo = PaymentMethod::create([
            'business_id' => $this->business->id, 'name' => 'Efectivo', 'counts_as_cash' => true,
        ]);

        $this->maria = $this->makeResource($this->business, 'Maria', '09:00:00', '18:00:00', [1, 2, 3, 4, 5, 6], $this->chapinero->id);
        $this->ana = $this->makeResource($this->business, 'Ana', '09:00:00', '18:00:00', [1, 2, 3, 4, 5, 6], $this->cedritos->id);

        $this->manicure = $this->makeService($this->business, 60, [$this->maria, $this->ana]);
        $this->manicure->update(['price' => 50000, 'commission_rate' => 0.30]);

        Sanctum::actingAs($this->duena->fresh());
    }

    private function hacerUsuario(string $email, bool $esDuena): User
    {
        $user = User::create([
            'business_id' => $this->business->id,
            'name' => $esDuena ? 'Dueña' : 'Encargada',
            'email' => $email,
            'password' => Hash::make('password123'),
            'is_active' => true,
            'is_owner' => $esDuena,
        ]);
        PermissionCatalog::applyRole($user, PermissionCatalog::ROLE_ADMIN);

        return $user;
    }

    private function hoy(): CarbonImmutable
    {
        return CarbonImmutable::now('America/Bogota')->startOfDay();
    }

    /** Cobra una cita en la sede de esa persona. */
    private function cobrar(Resource $quien, string $hora): void
    {
        $id = $this->postJson('/api/v1/appointments', [
            'service_id' => $this->manicure->id,
            'resource_id' => $quien->id,
            'starts_at' => $this->hoy()->format('Y-m-d')." {$hora}:00",
            'client_name' => 'Carolina',
        ])->assertCreated()->json('id');

        $this->postJson("/api/v1/appointments/{$id}/checkout", [
            'payment_method_id' => $this->efectivo->id,
        ])->assertOk();
    }

    private function preview(?int $locationId): TestResponse
    {
        $url = '/api/v1/cash/closing/preview?date='.$this->hoy()->toDateString()
            .($locationId ? "&location_id={$locationId}" : '');

        return $this->getJson($url);
    }

    public function test_cada_sede_cuadra_su_propio_cajon(): void
    {
        $this->cobrar($this->maria, '10:00');
        $this->cobrar($this->ana, '11:00');
        $this->cobrar($this->ana, '12:00');

        // Un cierre que sumara los dos cajones no se podría cuadrar contra
        // ninguno de los dos.
        $this->assertEqualsWithDelta(50000, $this->preview($this->chapinero->id)->json('total_charged'), 0.01);
        $this->assertEqualsWithDelta(100000, $this->preview($this->cedritos->id)->json('total_charged'), 0.01);
    }

    public function test_con_dos_sedes_hay_que_decir_cual_se_cierra(): void
    {
        $this->cobrar($this->maria, '10:00');

        // Ni siquiera la vista previa: enseñarle a alguien un cuadre que
        // después no va a poder confirmar es peor que preguntarle antes.
        $this->preview(null)->assertStatus(422);

        $this->postJson('/api/v1/cash/closing', [
            'date' => $this->hoy()->toDateString(),
            'actual_cash' => 50000,
        ])->assertStatus(422)
            ->assertJsonPath('message', fn (string $m) => str_contains($m, 'más de una sede'));
    }

    public function test_el_mismo_dia_se_cierra_en_las_dos_sedes(): void
    {
        $this->cobrar($this->maria, '10:00');
        $this->cobrar($this->ana, '11:00');

        foreach ([$this->chapinero, $this->cedritos] as $sede) {
            $this->postJson('/api/v1/cash/closing', [
                'date' => $this->hoy()->toDateString(),
                'actual_cash' => 50000,
                'location_id' => $sede->id,
            ])->assertCreated();
        }

        // El índice único pasó de "un día se cierra una vez" a "una vez POR
        // SEDE". Sin eso el segundo local no podría cerrar nunca.
        $this->assertSame(2, CashClosing::where('date', $this->hoy()->toDateString())->count());
    }

    public function test_la_base_de_manana_sale_del_cierre_de_su_propia_sede(): void
    {
        $this->cobrar($this->maria, '10:00');

        // Chapinero cierra contando 900.000 y deja esa base.
        $this->postJson('/api/v1/cash/closing', [
            'date' => $this->hoy()->subDay()->toDateString(),
            'actual_cash' => 900000,
            'location_id' => $this->chapinero->id,
        ])->assertCreated();

        /*
         * Cedritos no heredó nada: arranca en cero. Si heredara la base de
         * Chapinero empezaría el día con una plata que su cajón nunca tuvo, y
         * no volvería a cuadrar jamás -- un error que se arrastra hacia
         * adelante y que nadie relaciona con el día en que empezó.
         */
        $this->assertEqualsWithDelta(0, $this->preview($this->cedritos->id)->json('opening_cash'), 0.01);
        $this->assertEqualsWithDelta(900000, $this->preview($this->chapinero->id)->json('opening_cash'), 0.01);
    }

    public function test_el_gasto_de_un_local_no_descuadra_al_otro(): void
    {
        $this->cobrar($this->maria, '10:00');
        $this->cobrar($this->ana, '11:00');

        $antesCedritos = $this->preview($this->cedritos->id)->json('expected_cash');

        $this->postJson('/api/v1/expenses', [
            'date' => $this->hoy()->toDateString(),
            'description' => 'Arriendo Chapinero',
            'value' => 20000,
            'scope' => Expense::SCOPE_OPERATIONAL,
            'payment_method_id' => $this->efectivo->id,
            'location_id' => $this->chapinero->id,
        ])->assertCreated();

        $this->assertEqualsWithDelta($antesCedritos, $this->preview($this->cedritos->id)->json('expected_cash'), 0.01);
        $this->assertEqualsWithDelta(50000 - 20000, $this->preview($this->chapinero->id)->json('expected_cash'), 0.01);
    }

    public function test_el_gasto_del_negocio_entero_no_lo_paga_ningun_cajon(): void
    {
        $this->cobrar($this->maria, '10:00');
        $antes = $this->preview($this->chapinero->id)->json('expected_cash');

        // La contadora no es de ningún local. Descontarla del cajón de
        // Chapinero lo dejaría corto por una plata que ese cajón nunca tuvo.
        Expense::create([
            'business_id' => $this->business->id,
            'location_id' => null,
            'date' => $this->hoy()->toDateString(),
            'description' => 'Contadora',
            'value' => 300000,
            'scope' => Expense::SCOPE_OPERATIONAL,
            'payment_method_id' => $this->efectivo->id,
        ]);

        $this->assertEqualsWithDelta($antes, $this->preview($this->chapinero->id)->json('expected_cash'), 0.01);
    }

    public function test_el_gasto_sin_sede_explicita_cae_en_la_de_quien_lo_registra(): void
    {
        /*
         * "No me lo preguntaron" no es lo mismo que "no es de ningún local".
         * Sin esta distinción, todo gasto registrado desde una pantalla que
         * todavía no pregunta sede desaparecía del cuadre del día.
         */
        $encargada = $this->hacerUsuario('cedritos@prueba.test', false);
        $encargada->locations()->sync([$this->cedritos->id]);
        Sanctum::actingAs($encargada->fresh());

        $id = $this->postJson('/api/v1/expenses', [
            'date' => $this->hoy()->toDateString(),
            'description' => 'Domicilio insumos',
            'value' => 10000,
            'scope' => Expense::SCOPE_OPERATIONAL,
            'payment_method_id' => $this->efectivo->id,
        ])->assertCreated()->json('id');

        $this->assertSame($this->cedritos->id, Expense::find($id)->location_id);
    }

    public function test_el_reporte_compara_las_dos_sedes(): void
    {
        $this->cobrar($this->maria, '10:00');
        $this->cobrar($this->ana, '11:00');
        $this->cobrar($this->ana, '12:00');

        // La pregunta del dueño de dos locales no es "cuánto se hizo", es
        // "cuál de los dos está jalando".
        $porSede = collect($this->getJson('/api/v1/reports/sales?from='.$this->hoy()->toDateString())
            ->assertOk()->json('by_location'))->keyBy('name');

        $this->assertEqualsWithDelta(100000, $porSede['Cedritos']['charged'], 0.01);
        $this->assertEqualsWithDelta(50000, $porSede['Principal']['charged'], 0.01);
    }

    public function test_la_duena_ve_las_dos_sedes(): void
    {
        $this->cobrar($this->maria, '10:00');
        $this->cobrar($this->ana, '11:00');

        // Sin filtro: las dos. Darle una sola le escondería la mitad de su
        // negocio sin decírselo.
        $this->assertEqualsWithDelta(
            100000,
            $this->getJson('/api/v1/reports/sales?from='.$this->hoy()->toDateString())
                ->assertOk()->json('totals.charged'),
            0.01,
        );
    }

    public function test_la_encargada_de_una_sede_solo_ve_la_suya(): void
    {
        $this->cobrar($this->maria, '10:00');
        $this->cobrar($this->ana, '11:00');

        $encargada = $this->hacerUsuario('cedritos@prueba.test', false);
        $encargada->locations()->sync([$this->cedritos->id]);
        Sanctum::actingAs($encargada->fresh());

        $this->assertEqualsWithDelta(
            50000,
            $this->getJson('/api/v1/reports/sales?from='.$this->hoy()->toDateString())
                ->assertOk()->json('totals.charged'),
            0.01,
        );
    }

    public function test_pedir_una_sede_ajena_se_rechaza_en_vez_de_ignorarse(): void
    {
        $encargada = $this->hacerUsuario('cedritos@prueba.test', false);
        $encargada->locations()->sync([$this->cedritos->id]);
        Sanctum::actingAs($encargada->fresh());

        /*
         * 422, no "sin filtro". Degradar un filtro prohibido a "todas" es
         * exactamente al revés de lo que se quiere: quien pide lo que no le
         * toca terminaría viendo MÁS.
         */
        $this->getJson('/api/v1/reports/sales?from='.$this->hoy()->toDateString()
            .'&location_id='.$this->chapinero->id)->assertStatus(422);
    }

    public function test_sin_sede_asignada_ve_donde_trabaja(): void
    {
        $this->cobrar($this->maria, '10:00');
        $this->cobrar($this->ana, '11:00');

        // Vacío NO es "todas": es la sede donde trabaja. El default inseguro
        // es el que nadie revisa.
        $anaUser = User::create([
            'business_id' => $this->business->id,
            'name' => 'Ana', 'email' => 'ana@prueba.test',
            'password' => Hash::make('password123'), 'is_active' => true,
        ]);
        PermissionCatalog::applyRole($anaUser, PermissionCatalog::ROLE_ADMIN);
        $this->ana->update(['user_id' => $anaUser->id]);

        Sanctum::actingAs($anaUser->fresh());

        $this->assertEqualsWithDelta(
            50000,
            $this->getJson('/api/v1/reports/sales?from='.$this->hoy()->toDateString())
                ->assertOk()->json('totals.charged'),
            0.01,
        );
    }

    public function test_al_dueno_no_se_le_restringen_las_sedes(): void
    {
        // Un negocio sin nadie que vea sus dos locales es un negocio que ya no
        // puede administrarse a sí mismo.
        $otro = $this->hacerUsuario('otro@prueba.test', false);
        Sanctum::actingAs($otro->fresh());

        $this->putJson("/api/v1/permissions/{$this->duena->id}/locations", [
            'location_ids' => [$this->cedritos->id],
        ])->assertStatus(422)
            ->assertJsonPath('message', fn (string $m) => str_contains($m, 'dueño'));

        $this->assertNull($this->duena->fresh()->locationScope());
    }

    public function test_el_turno_de_caja_es_de_un_cajon(): void
    {
        $encargada = $this->hacerUsuario('cedritos@prueba.test', false);
        $encargada->locations()->sync([$this->cedritos->id]);
        Sanctum::actingAs($encargada->fresh());

        // No hay que preguntárselo: solo puede estar en un cajón.
        $this->postJson('/api/v1/cash/shift/open', ['opening_cash' => 50000])->assertCreated();

        $this->assertSame(
            $this->cedritos->id,
            CashShift::where('user_id', $encargada->id)->value('location_id'),
        );
    }

    public function test_a_la_duena_hay_que_preguntarle_en_que_cajon_abre_turno(): void
    {
        // Ve las dos: adivinar cuál sería inventar de dónde salió esa base.
        $this->postJson('/api/v1/cash/shift/open', ['opening_cash' => 50000])
            ->assertStatus(422)
            ->assertJsonPath('message', fn (string $m) => str_contains($m, 'sede'));

        $this->postJson('/api/v1/cash/shift/open', [
            'opening_cash' => 50000,
            'location_id' => $this->chapinero->id,
        ])->assertCreated();
    }
}
