<?php

namespace App\Support\Money;

/**
 * La aritmetica del cuadre de caja, sin base de datos.
 *
 * Recibe filas planas y devuelve totales. Separarlo del servicio que consulta
 * la base permite probar las reglas del cierre -- que es donde se descuadra un
 * negocio -- con casos escritos a mano en vez de sembrando citas por HTTP.
 */
final class CashSummary
{
    /**
     * @param  list<array{amount: float, method_id: int|null, method_label: string, counts_as_cash: bool}>  $charges
     * @param  list<array{value: float, counts_as_cash: bool, operational?: bool}>  $expenses
     * @return array{
     *   total_charged: float, total_cash: float, total_other_methods: float,
     *   total_expenses: float, opening_cash: float, expected_cash: float,
     *   payment_breakdown: list<array{id:int|null, label:string, counts_as_cash:bool, total:float}>,
     * }
     */
    public static function build(array $charges, array $expenses, float $openingCash = 0): array
    {
        $breakdown = [];
        $totalCharged = 0.0;
        $totalCash = 0.0;

        foreach ($charges as $charge) {
            $amount = (float) $charge['amount'];
            $totalCharged += $amount;

            // Agrupado por metodo, no por nombre: dos metodos pueden llamarse
            // parecido y siguen siendo cuentas distintas.
            $key = $charge['method_id'] ?? 0;

            $breakdown[$key] ??= [
                'id' => $charge['method_id'],
                'label' => $charge['method_label'],
                'counts_as_cash' => (bool) $charge['counts_as_cash'],
                'total' => 0.0,
            ];
            $breakdown[$key]['total'] = round($breakdown[$key]['total'] + $amount, 2);

            if ($charge['counts_as_cash']) {
                $totalCash += $amount;
            }
        }

        $totalExpenses = 0.0;
        $cashExpenses = 0.0;

        foreach ($expenses as $expense) {
            $value = (float) $expense['value'];

            /*
             * Dos preguntas distintas sobre el mismo gasto, y por eso dos
             * acumuladores:
             *
             * - "Que gasto el negocio operando hoy" -> solo lo OPERACIONAL. El
             *   arriendo y la nomina son del mes, no del dia; meterlos aca
             *   haria ver un martes cualquiera en perdida.
             *
             * - "Cuanta plata falta en el cajon" -> todo lo pagado en
             *   EFECTIVO, sea operacional o no. Si le pagaste la quincena a
             *   una profesional con billetes de la caja, esos billetes no
             *   estan, y el cierre tiene que saberlo.
             */
            if ($expense['operational'] ?? true) {
                $totalExpenses += $value;
            }

            if ($expense['counts_as_cash']) {
                $cashExpenses += $value;
            }
        }

        // El mayor primero: al cuadrar se mira antes de que se cobro mas.
        usort($breakdown, fn ($a, $b) => $b['total'] <=> $a['total']);

        return [
            'total_charged' => round($totalCharged, 2),
            'total_cash' => round($totalCash, 2),
            'total_other_methods' => round($totalCharged - $totalCash, 2),
            'total_expenses' => round($totalExpenses, 2),
            'opening_cash' => round($openingCash, 2),
            'expected_cash' => round($openingCash + $totalCash - $cashExpenses, 2),
            'payment_breakdown' => array_values($breakdown),
        ];
    }
}
