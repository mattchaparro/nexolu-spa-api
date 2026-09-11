<?php

namespace Tests\Feature\Expenses;

use App\Models\Business;
use App\Models\Expense;
use App\Models\ExpenseType;
use App\Models\RecurringExpense;
use App\Models\User;
use App\Services\Expenses\GeneradorDeGastosFijos;
use App\Support\PermissionCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Scheduling\SchedulingScenario;
use Tests\TestCase;

/**
 * Los gastos que se repiten cada mes se ponen solos.
 *
 * Existe por una historia concreta: en el sistema viejo esto era una lista que
 * alguien copiaba a mano cada primero de mes. Se hizo puntual, ocho veces al
 * mes, desde 2025 -- y en mayo de 2026 se corto. Cinco meses seguidos sin
 * registrar ~1,2 millones cada uno. El arriendo se causo igual.
 */
class GastosFijosTest extends TestCase
{
    use RefreshDatabase, SchedulingScenario;

    private Business $business;

    private ExpenseType $tipo;

    protected function setUp(): void
    {
        parent::setUp();

        PermissionCatalog::sync();

        $this->business = $this->makeBusiness();

        $this->tipo = ExpenseType::create([
            'business_id' => $this->business->id, 'name' => 'Fijos', 'is_active' => true,
        ]);

        $admin = User::create([
            'business_id' => $this->business->id, 'name' => 'Ana',
            'email' => 'ana@prueba.test', 'password' => Hash::make('password123'), 'is_active' => true,
        ]);
        PermissionCatalog::applyRole($admin, PermissionCatalog::ROLE_ADMIN);
        Sanctum::actingAs($admin->fresh());
    }

    private function plantilla(array $overrides = []): RecurringExpense
    {
        return RecurringExpense::create(array_merge([
            'business_id' => $this->business->id,
            'expense_type_id' => $this->tipo->id,
            'description' => 'Arriendo',
            'value' => 840000,
            'day_of_month' => 1,
            'scope' => Expense::scopes()[0],
            'is_active' => true,
        ], $overrides));
    }

    private function generar(string $mes): int
    {
        return $this->app->make(GeneradorDeGastosFijos::class)->paraElMes(
            $this->business,
            CarbonImmutable::parse($mes.'-01', $this->business->businessTimezone()),
        );
    }

    public function test_pone_el_gasto_del_mes(): void
    {
        $this->plantilla();

        $this->assertSame(1, $this->generar('2026-09'));

        $gasto = Expense::withoutGlobalScope('business')->first();

        $this->assertSame('2026-09-01', $gasto->date->toDateString());
        $this->assertEqualsWithDelta(840000, (float) $gasto->value, 0.01);
        // Con el mes en la descripcion: doce renglones que digan "Arriendo" a
        // secas no se distinguen entre si.
        $this->assertSame('Arriendo - septiembre 2026', $gasto->description);
    }

    public function test_correrlo_dos_veces_no_cobra_el_arriendo_dos_veces(): void
    {
        /*
         * Lo que de verdad lo impide es el indice unico (plantilla, mes). Sin
         * eso, un cron que se dispara dos veces tras un reinicio deja el mes
         * con 840.000 de mas, y la forma de enterarse es el cierre.
         */
        $this->plantilla();

        $this->assertSame(1, $this->generar('2026-09'));
        $this->assertSame(0, $this->generar('2026-09'));

        $this->assertSame(1, Expense::withoutGlobalScope('business')->count());
    }

    public function test_cada_mes_es_su_propio_gasto(): void
    {
        $this->plantilla();

        $this->generar('2026-09');
        $this->generar('2026-10');

        $this->assertSame(2, Expense::withoutGlobalScope('business')->count());
    }

    public function test_el_dia_31_en_febrero_cae_en_el_ultimo_dia(): void
    {
        // El arriendo de febrero existe aunque febrero no tenga 31.
        $this->plantilla(['day_of_month' => 31]);

        $this->generar('2026-02');

        $this->assertSame(
            '2026-02-28',
            Expense::withoutGlobalScope('business')->first()->date->toDateString(),
        );
    }

    public function test_una_plantilla_apagada_no_genera(): void
    {
        $this->plantilla(['is_active' => false]);

        $this->assertSame(0, $this->generar('2026-09'));
    }

    public function test_el_gasto_generado_se_edita_como_cualquier_otro(): void
    {
        /*
         * La plantilla dice cuanto SUELE ser, no cuanto fue. En el legacy la
         * plantilla del servidor decia 80.000 y el gasto real de abril fueron
         * 36.000.
         */
        $this->plantilla(['description' => 'Servidor', 'value' => 80000]);
        $this->generar('2026-09');

        $gasto = Expense::withoutGlobalScope('business')->first();

        $this->postJson("/api/v1/expenses/{$gasto->id}", [
            'date' => $gasto->date->toDateString(),
            'description' => $gasto->description,
            'value' => 36000,
            'scope' => $gasto->scope,
        ])->assertOk();

        $this->assertEqualsWithDelta(36000, (float) $gasto->fresh()->value, 0.01);
    }

    public function test_cambiar_la_plantilla_no_reescribe_lo_ya_generado(): void
    {
        // Subir el arriendo en octubre no cambia lo que se pago en septiembre.
        $plantilla = $this->plantilla();
        $this->generar('2026-09');

        $this->patchJson("/api/v1/expenses/recurring/{$plantilla->id}", [
            'description' => 'Arriendo',
            'value' => 900000,
            'expense_type_id' => $this->tipo->id,
            'scope' => Expense::scopes()[0],
        ])->assertOk();

        $this->assertEqualsWithDelta(
            840000,
            (float) Expense::withoutGlobalScope('business')->first()->value,
            0.01,
        );
    }

    public function test_la_pantalla_dice_cuanto_suman_y_cuales_faltan(): void
    {
        $this->plantilla();
        $this->plantilla(['description' => 'Agua', 'value' => 15000]);

        $respuesta = $this->getJson('/api/v1/expenses/recurring')->assertOk();

        $this->assertEqualsWithDelta(855000, $respuesta->json('monthly_total'), 0.01);
        $this->assertFalse($respuesta->json('data.0.generated_this_period'));

        $this->postJson('/api/v1/expenses/recurring/generate')->assertOk();

        $this->assertTrue(
            $this->getJson('/api/v1/expenses/recurring')->json('data.0.generated_this_period'),
        );
    }
}
