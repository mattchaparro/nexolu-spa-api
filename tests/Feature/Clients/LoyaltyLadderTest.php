<?php

namespace Tests\Feature\Clients;

use App\Models\Business;
use App\Models\Client;
use App\Models\LoyaltyProgram;
use App\Models\LoyaltyReward;
use App\Models\LoyaltyStamp;
use App\Models\LoyaltyTier;
use App\Models\PaymentMethod;
use App\Models\Resource;
use App\Models\Service;
use App\Models\User;
use App\Support\Money\LoyaltyCalculator;
use App\Support\PermissionCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Scheduling\SchedulingScenario;
use Tests\TestCase;

/**
 * La tarjeta en modo ESCALERA: hitos acumulativos.
 *
 * Es lo que Luxury lleva años usando y lo que sus clientas conocen: a las 5
 * visitas un premio, a las 10 otro, a las 15 otro, y los sellos no se gastan
 * nunca.
 *
 * Cada prueba de acá defiende contra algo concreto que el sistema viejo hace
 * mal o no hace. Sin ellas, la diferencia entre "entrega el premio una vez" y
 * "lo entrega en cada cobro a partir de las 10 visitas" no se ve hasta que
 * alguien revisa por qué el margen del mes se cayó.
 */
class LoyaltyLadderTest extends TestCase
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

        $this->travelTo(
            CarbonImmutable::now('America/Bogota')->startOfDay()
                ->previous(CarbonImmutable::WEDNESDAY)->setTime(8, 0),
        );

        PermissionCatalog::sync();

        $this->business = $this->makeBusiness(['slot_granularity_min' => 60, 'min_booking_notice_min' => 0]);

        $this->admin = User::create([
            'business_id' => $this->business->id, 'name' => 'Dueña',
            'email' => 'admin@prueba.test', 'password' => Hash::make('password123'), 'is_active' => true,
        ]);
        PermissionCatalog::applyRole($this->admin, PermissionCatalog::ROLE_ADMIN);

        $this->efectivo = PaymentMethod::create([
            'business_id' => $this->business->id, 'name' => 'Efectivo', 'counts_as_cash' => true,
        ]);

        $this->maria = $this->makeResource($this->business, 'Maria', '08:00:00', '20:00:00');
        $this->service = $this->makeService($this->business, 60, [$this->maria]);
        $this->service->update(['name' => 'Manicure', 'price' => 50000, 'commission_rate' => 0.30]);

        Sanctum::actingAs($this->admin->fresh());
    }

    private function hoy(): CarbonImmutable
    {
        return CarbonImmutable::now('America/Bogota')->startOfDay();
    }

    /** La escalera chica de Luxury, para no encadenar 35 visitas en un test. */
    private function crearEscalera(?array $tiers = null): array
    {
        return $this->postJson('/api/v1/loyalty/program', [
            'name' => 'Escalera Luxury',
            'mode' => LoyaltyProgram::MODE_LADDER,
            'tiers' => $tiers ?? [
                ['stamps_required' => 2, 'reward_type' => LoyaltyCalculator::REWARD_DISCOUNT_PERCENT, 'reward_value' => 10],
                ['stamps_required' => 4, 'reward_type' => LoyaltyCalculator::REWARD_DISCOUNT_PERCENT, 'reward_value' => 15],
                ['stamps_required' => 6, 'reward_type' => LoyaltyCalculator::REWARD_DISCOUNT_PERCENT, 'reward_value' => 25],
            ],
        ])->assertOk()->json('program');
    }

    /** Agenda y cobra una visita. Devuelve el id de la cita. */
    private function visita(int $hora, ?int $clientId = null): int
    {
        $id = $this->postJson('/api/v1/appointments', [
            'service_id' => $this->service->id,
            'resource_id' => $this->maria->id,
            'starts_at' => $this->hoy()->format('Y-m-d').sprintf(' %02d:00:00', $hora),
            'client_id' => $clientId,
            'client_name' => $clientId === null ? 'Carolina' : null,
            'client_phone' => $clientId === null ? '3001234567' : null,
        ])->assertCreated()->json('id');

        $this->postJson("/api/v1/appointments/{$id}/checkout", [
            'payment_method_id' => $this->efectivo->id,
        ])->assertOk();

        return $id;
    }

    private function clienteId(): int
    {
        return Client::withoutGlobalScope('business')->latest('id')->first()->id;
    }

    private function premios(int $clientId): \Illuminate\Support\Collection
    {
        return LoyaltyReward::withoutGlobalScope('business')
            ->where('client_id', $clientId)->orderBy('id')->get();
    }

    /*
    |--------------------------------------------------------------------------
    | Configurar la escalera
    |--------------------------------------------------------------------------
    */

    public function test_una_escalera_de_un_solo_escalon_no_se_deja_configurar(): void
    {
        // Eso no es una escalera: es un premio que se entrega una vez y nunca
        // más. Quien elige "escalera" está pidiendo otra cosa.
        $this->postJson('/api/v1/loyalty/program', [
            'name' => 'Coja', 'mode' => LoyaltyProgram::MODE_LADDER,
            'tiers' => [
                ['stamps_required' => 5, 'reward_type' => LoyaltyCalculator::REWARD_DISCOUNT_PERCENT, 'reward_value' => 10],
            ],
        ])->assertStatus(422);
    }

    public function test_dos_escalones_para_el_mismo_numero_de_visitas_no_se_dejan(): void
    {
        // "A las 10 visitas, ¿cuál de los dos premios?" no tiene una respuesta
        // que el mostrador pueda dar en voz alta.
        $this->postJson('/api/v1/loyalty/program', [
            'name' => 'Ambigua', 'mode' => LoyaltyProgram::MODE_LADDER,
            'tiers' => [
                ['stamps_required' => 5, 'reward_type' => LoyaltyCalculator::REWARD_DISCOUNT_PERCENT, 'reward_value' => 10],
                ['stamps_required' => 5, 'reward_type' => LoyaltyCalculator::REWARD_DISCOUNT_PERCENT, 'reward_value' => 20],
            ],
        ])->assertStatus(422);
    }

    public function test_un_escalon_sin_premio_utilizable_dice_cual_escalon_es(): void
    {
        /*
         * Con siete escalones, "el premio necesita un valor" a secas obliga a
         * revisarlos uno por uno. El mensaje tiene que señalar el roto.
         */
        $r = $this->postJson('/api/v1/loyalty/program', [
            'name' => 'Rota', 'mode' => LoyaltyProgram::MODE_LADDER,
            'tiers' => [
                ['stamps_required' => 5, 'reward_type' => LoyaltyCalculator::REWARD_DISCOUNT_PERCENT, 'reward_value' => 10],
                ['stamps_required' => 10, 'reward_type' => LoyaltyCalculator::REWARD_DISCOUNT_PERCENT, 'reward_value' => 0],
            ],
        ])->assertStatus(422);

        $this->assertStringContainsString('10 visitas', $r->json('message'));
    }

    public function test_los_escalones_se_guardan_ordenados_por_visitas(): void
    {
        $program = $this->crearEscalera([
            ['stamps_required' => 6, 'reward_type' => LoyaltyCalculator::REWARD_DISCOUNT_PERCENT, 'reward_value' => 25],
            ['stamps_required' => 2, 'reward_type' => LoyaltyCalculator::REWARD_DISCOUNT_PERCENT, 'reward_value' => 10],
            ['stamps_required' => 4, 'reward_type' => LoyaltyCalculator::REWARD_DISCOUNT_PERCENT, 'reward_value' => 15],
        ]);

        $this->assertSame([2, 4, 6], array_column($program['tiers'], 'stamps_required'));
    }

    /*
    |--------------------------------------------------------------------------
    | Ganar los premios
    |--------------------------------------------------------------------------
    */

    public function test_cada_hito_entrega_su_propio_premio(): void
    {
        $this->crearEscalera();

        $this->visita(9);
        $cliente = $this->clienteId();
        $this->visita(10, $cliente);

        $premios = $this->premios($cliente);
        $this->assertCount(1, $premios);
        $this->assertEquals(10, $premios[0]->reward_value);

        $this->visita(11, $cliente);
        $this->visita(12, $cliente);

        $premios = $this->premios($cliente);
        $this->assertCount(2, $premios);
        $this->assertEquals(15, $premios[1]->reward_value);
    }

    public function test_los_sellos_no_se_gastan_nunca(): void
    {
        /*
         * Es LA diferencia con el modo tarjeta. Una clienta con 4 visitas
         * tiene 4 sellos, no 0 -- aunque ya se haya ganado el premio de las 2.
         */
        $this->crearEscalera();

        $this->visita(9);
        $cliente = $this->clienteId();
        $this->visita(10, $cliente);
        $this->visita(11, $cliente);
        $this->visita(12, $cliente);

        $this->assertSame(4, LoyaltyStamp::withoutGlobalScope('business')
            ->where('client_id', $cliente)->whereNull('consumed_by_reward_id')->count());
    }

    public function test_un_escalon_ya_ganado_no_se_vuelve_a_entregar(): void
    {
        /*
         * El riesgo real de la escalera: como los sellos no se gastan, el
         * saldo sigue por encima del hito para siempre. Sin la marca de qué
         * escalón pagó cada premio, CADA cobro posterior volvería a
         * desbloquear el de las 2 visitas -- y nadie lo notaría hasta ver el
         * margen del mes.
         */
        $this->crearEscalera();

        $this->visita(9);
        $cliente = $this->clienteId();
        $this->visita(10, $cliente);
        $this->visita(11, $cliente);

        $delDosVisitas = $this->premios($cliente)
            ->filter(fn (LoyaltyReward $r) => (float) $r->reward_value === 10.0);

        $this->assertCount(1, $delDosVisitas);
    }

    public function test_quien_se_salta_un_hito_igual_se_lo_gana(): void
    {
        /*
         * El sistema viejo exige igualdad exacta (`stamps == required`), así
         * que una clienta cuyo contador salta de 1 a 3 -- un ajuste a mano,
         * una migración -- pierde el premio de las 2 para siempre. Acá basta
         * con haberlo pasado.
         */
        $this->crearEscalera();

        $this->visita(9);
        $cliente = $this->clienteId();

        /*
         * El programa se apaga un momento para que las visitas NO generen
         * sello, y despues se prende: es la forma limpia de dejar a la
         * clienta con el contador saltado, que es justo lo que produce una
         * importacion o un ajuste a mano.
         */
        $program = LoyaltyProgram::withoutGlobalScope('business')->first();
        $program->update(['is_active' => false]);

        $citas = [$this->visita(11, $cliente), $this->visita(12, $cliente)];

        $program->update(['is_active' => true]);

        foreach ($citas as $cita) {
            LoyaltyStamp::create([
                'business_id' => $this->business->id, 'program_id' => $program->id,
                'client_id' => $cliente, 'appointment_id' => $cita, 'earned_at' => now(),
            ]);
        }

        // Tres sellos: nunca estuvo exactamente en 2, que es lo que el
        // sistema viejo exige para entregar ese escalon.
        $this->assertSame(3, LoyaltyStamp::withoutGlobalScope('business')
            ->where('client_id', $cliente)->count());

        app(\App\Services\Loyalty\LoyaltyService::class)
            ->unlockIfComplete(Client::withoutGlobalScope('business')->find($cliente), $program->fresh());

        $premios = $this->premios($cliente);
        $this->assertCount(1, $premios);
        $this->assertEquals(10, $premios->first()->reward_value);
    }

    public function test_un_premio_ganado_no_se_vence_al_llegar_el_siguiente(): void
    {
        /*
         * El sistema viejo SÍ lo vence: al desbloquear el de 10, el de 5 sin
         * usar pasa a `expired`. Allá tenía una razón -- nada marcaba un
         * premio como usado, así que el vencimiento hacía de límite. Acá el
         * canje se registra, y quitarle a una clienta un premio que se ganó y
         * todavía no usó sería retirarle una promesa del local.
         */
        $this->crearEscalera();

        $this->visita(9);
        $cliente = $this->clienteId();
        $this->visita(10, $cliente);
        $this->visita(11, $cliente);
        $this->visita(12, $cliente);

        $premios = $this->premios($cliente);

        $this->assertCount(2, $premios);
        $this->assertTrue($premios->every(fn (LoyaltyReward $r) => $r->status === LoyaltyReward::STATUS_AVAILABLE));
    }

    public function test_el_premio_se_congela_aunque_el_negocio_cambie_el_escalon(): void
    {
        // Misma regla que el precio de una cita cobrada: a quien ya lo alcanzó
        // se le entrega lo que decía el escalón el día que lo alcanzó.
        $this->crearEscalera();

        $this->visita(9);
        $cliente = $this->clienteId();
        $this->visita(10, $cliente);

        $this->crearEscalera([
            ['stamps_required' => 2, 'reward_type' => LoyaltyCalculator::REWARD_DISCOUNT_PERCENT, 'reward_value' => 5],
            ['stamps_required' => 4, 'reward_type' => LoyaltyCalculator::REWARD_DISCOUNT_PERCENT, 'reward_value' => 15],
        ]);

        $this->assertEquals(10, $this->premios($cliente)->first()->reward_value);
    }

    public function test_un_escalon_retirado_deja_de_entregar_pero_no_borra_lo_entregado(): void
    {
        /*
         * Si el escalón se borrara, el premio que ya entregó quedaría
         * huérfano -- y volver a poner ese escalón mañana se lo regalaría de
         * nuevo a quien ya lo ganó.
         */
        $this->crearEscalera();

        $this->visita(9);
        $cliente = $this->clienteId();
        $this->visita(10, $cliente);

        $this->crearEscalera([
            ['stamps_required' => 4, 'reward_type' => LoyaltyCalculator::REWARD_DISCOUNT_PERCENT, 'reward_value' => 15],
            ['stamps_required' => 6, 'reward_type' => LoyaltyCalculator::REWARD_DISCOUNT_PERCENT, 'reward_value' => 25],
        ]);

        $this->assertCount(1, $this->premios($cliente));

        $retirado = LoyaltyTier::withoutGlobalScope('business')->where('stamps_required', 2)->first();
        $this->assertNotNull($retirado, 'El escalón retirado se borró en vez de apagarse.');
        $this->assertFalse($retirado->is_active);
    }

    /*
    |--------------------------------------------------------------------------
    | Cómo se ve la tarjeta
    |--------------------------------------------------------------------------
    */

    public function test_la_tarjeta_muestra_la_escalera_completa_y_el_siguiente_hito(): void
    {
        /*
         * Todos los escalones y no solo el siguiente: lo que hace volver a una
         * clienta es ver que a las 6 hay un 25%, no enterarse de a uno.
         */
        $this->crearEscalera();

        $this->visita(9);
        $cliente = $this->clienteId();
        $this->visita(10, $cliente);

        $tarjeta = $this->getJson("/api/v1/clients/{$cliente}/loyalty")->assertOk()->json();

        $this->assertSame(LoyaltyProgram::MODE_LADDER, $tarjeta['program']['mode']);
        $this->assertSame(2, $tarjeta['stamps']);
        $this->assertCount(3, $tarjeta['tiers']);
        $this->assertTrue($tarjeta['tiers'][0]['reached']);
        $this->assertFalse($tarjeta['tiers'][1]['reached']);
        $this->assertSame(4, $tarjeta['next_tier']['stamps_required']);
        $this->assertSame(2, $tarjeta['next_tier']['stamps_away']);
    }

    public function test_al_terminar_la_escalera_no_hay_siguiente_hito(): void
    {
        $this->crearEscalera();

        $this->visita(9);
        $cliente = $this->clienteId();

        foreach ([10, 11, 12, 13, 14] as $hora) {
            $this->visita($hora, $cliente);
        }

        $tarjeta = $this->getJson("/api/v1/clients/{$cliente}/loyalty")->assertOk()->json();

        $this->assertSame(6, $tarjeta['stamps']);
        $this->assertNull($tarjeta['next_tier']);
    }

    public function test_la_pantalla_de_cobro_no_se_rompe_con_la_escalera(): void
    {
        /*
         * El modal de cobro dice "7 de 10 sellos, le faltan 3 para X". Lee
         * `required`, `remaining` y `reward_label`, que en el modo tarjeta
         * salen del programa. Si la escalera no los devuelve, esa línea
         * muestra "2 de undefined" en cada cobro de Luxury.
         *
         * Y el premio anunciado es el del SIGUIENTE hito, no el del programa:
         * decir "le faltan 2 para 10%" cuando lo que viene es el 15% es
         * prometer de menos.
         */
        $this->crearEscalera();

        $this->visita(9);
        $cliente = $this->clienteId();
        $this->visita(10, $cliente);

        $tarjeta = $this->getJson("/api/v1/clients/{$cliente}/loyalty")->assertOk()->json();

        $this->assertSame(2, $tarjeta['stamps']);
        $this->assertSame(4, $tarjeta['required']);
        $this->assertSame(2, $tarjeta['remaining']);
        $this->assertFalse($tarjeta['complete']);
        $this->assertStringContainsString('15', $tarjeta['program']['reward_label']);
    }

    /*
    |--------------------------------------------------------------------------
    | Que el modo tarjeta siga intacto
    |--------------------------------------------------------------------------
    */

    public function test_un_programa_sin_modo_sigue_siendo_tarjeta(): void
    {
        // Ningún negocio con un programa ya configurado puede despertar con
        // otras reglas de fidelización por un deploy.
        $program = $this->postJson('/api/v1/loyalty/program', [
            'name' => 'De siempre', 'stamps_required' => 3,
            'reward_type' => LoyaltyCalculator::REWARD_DISCOUNT_PERCENT, 'reward_value' => 100,
        ])->assertOk()->json('program');

        $this->assertSame(LoyaltyProgram::MODE_CARD, $program['mode']);
        $this->assertSame([], $program['tiers']);
    }
}
