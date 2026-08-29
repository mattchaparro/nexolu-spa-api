<?php

namespace Tests\Unit;

use App\Support\Money\CashSummary;
use PHPUnit\Framework\TestCase;

/**
 * La aritmetica del cuadre de caja.
 *
 * Las mismas reglas que CashRegisterTest verifica por HTTP, pero escritas a
 * mano: aca se pueden cubrir los bordes -- un metodo sin nombre, un gasto sin
 * medio de pago, todo en cero -- sin sembrar citas ni levantar el framework.
 */
class CashSummaryTest extends TestCase
{
    private function charge(float $amount, bool $cash, int $id = 1, string $label = 'Efectivo'): array
    {
        return ['amount' => $amount, 'method_id' => $id, 'method_label' => $label, 'counts_as_cash' => $cash];
    }

    public function test_un_dia_sin_movimiento_devuelve_la_base_intacta(): void
    {
        $totals = CashSummary::build([], [], 50000);

        $this->assertSame(0.0, $totals['total_charged']);
        $this->assertSame(50000.0, $totals['expected_cash']);
        $this->assertSame([], $totals['payment_breakdown']);
    }

    public function test_solo_lo_cobrado_en_efectivo_entra_al_cajon(): void
    {
        $totals = CashSummary::build([
            $this->charge(50000, true),
            $this->charge(30000, false, 2, 'Transferencia'),
        ], [], 0);

        $this->assertSame(80000.0, $totals['total_charged']);
        $this->assertSame(50000.0, $totals['total_cash']);
        // La transferencia suma a la venta del dia pero no al efectivo que
        // debe haber fisicamente.
        $this->assertSame(30000.0, $totals['total_other_methods']);
        $this->assertSame(50000.0, $totals['expected_cash']);
    }

    public function test_un_gasto_en_efectivo_baja_lo_esperado_y_uno_por_transferencia_no(): void
    {
        $totals = CashSummary::build(
            [$this->charge(100000, true)],
            [
                ['value' => 10000, 'counts_as_cash' => true],
                ['value' => 30000, 'counts_as_cash' => false],
            ],
            0,
        );

        // Los dos son gasto del negocio...
        $this->assertSame(40000.0, $totals['total_expenses']);
        // ...pero solo uno salio del cajon.
        $this->assertSame(90000.0, $totals['expected_cash']);
    }

    public function test_la_base_se_suma_a_lo_esperado(): void
    {
        $totals = CashSummary::build([$this->charge(20000, true)], [], 50000);

        $this->assertSame(70000.0, $totals['expected_cash']);
    }

    public function test_lo_esperado_puede_quedar_negativo(): void
    {
        // Sacar mas efectivo del que entro es un error de operacion, pero el
        // cuadre tiene que mostrarlo en vez de recortarlo a cero: es
        // exactamente el caso que hay que ver.
        $totals = CashSummary::build([], [['value' => 30000, 'counts_as_cash' => true]], 10000);

        $this->assertSame(-20000.0, $totals['expected_cash']);
    }

    public function test_el_desglose_agrupa_por_metodo_y_ordena_por_monto(): void
    {
        $totals = CashSummary::build([
            $this->charge(10000, true, 1, 'Efectivo'),
            $this->charge(50000, false, 2, 'Datafono'),
            $this->charge(15000, true, 1, 'Efectivo'),
        ], [], 0);

        $this->assertCount(2, $totals['payment_breakdown']);
        // El mayor primero: al cuadrar se mira antes por donde entro mas.
        $this->assertSame('Datafono', $totals['payment_breakdown'][0]['label']);
        $this->assertSame(50000.0, $totals['payment_breakdown'][0]['total']);
        $this->assertSame(25000.0, $totals['payment_breakdown'][1]['total']);
    }

    public function test_dos_metodos_con_el_mismo_nombre_siguen_siendo_cuentas_distintas(): void
    {
        $totals = CashSummary::build([
            $this->charge(10000, false, 5, 'Nequi'),
            $this->charge(20000, false, 6, 'Nequi'),
        ], [], 0);

        // Agrupar por nombre las juntaria y esconderia que son dos cuentas.
        $this->assertCount(2, $totals['payment_breakdown']);
    }

    public function test_un_cobro_sin_metodo_aparece_en_el_desglose(): void
    {
        $totals = CashSummary::build([
            ['amount' => 40000, 'method_id' => null, 'method_label' => 'Sin método', 'counts_as_cash' => false],
        ], [], 0);

        // No deberia existir, pero si aparece hay que verlo: desaparecerlo del
        // cuadre deja una diferencia sin explicacion.
        $this->assertSame('Sin método', $totals['payment_breakdown'][0]['label']);
        $this->assertSame(40000.0, $totals['total_charged']);
    }

    public function test_los_totales_no_arrastran_decimales_flotantes(): void
    {
        $totals = CashSummary::build([
            $this->charge(0.1, true),
            $this->charge(0.2, true),
        ], [], 0);

        // 0.1 + 0.2 en coma flotante da 0.30000000000000004.
        $this->assertSame(0.3, $totals['total_charged']);
        $this->assertSame(0.3, $totals['expected_cash']);
    }
}
