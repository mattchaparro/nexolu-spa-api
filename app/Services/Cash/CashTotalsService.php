<?php

namespace App\Services\Cash;

use App\Models\Appointment;
use App\Models\AppointmentItem;
use App\Models\Expense;
use App\Models\PaymentMethod;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Cuanto efectivo deberia haber en la caja.
 *
 * El motor viene de nexolu-pos-api (CashClosingService), con dos cambios que
 * el dominio impone:
 *
 * 1. El ingreso de un spa es UNA fuente -- citas cobradas -- y no las cuatro
 *    del POS (ventas, fiados, abonos a servicio, abonos a apartado). Menos
 *    piezas, misma logica.
 *
 * 2. El POS guarda el metodo de pago como texto ('Efectivo', 'Nequi') porque
 *    comparte la tabla con el monolito legacy. Aca es una fila con
 *    `counts_as_cash`, asi que que algo entre al cajon lo decide el dato y no
 *    una lista de strings que hay que mantener sincronizada.
 *
 * Las dos reglas que SI se conservan tal cual, porque son las que hacen que
 * un cierre cuadre:
 *
 * - El ingreso cuenta por CUANDO SE COBRO (checked_out_at), no por cuando se
 *   agendo. Una cita agendada la semana pasada y cobrada hoy es plata que
 *   entro hoy.
 * - El gasto cuenta por su FECHA CONTABLE (date), no por cuando se registro.
 *   Un gasto de ayer digitado hoy no puede descontarse de la caja de hoy, que
 *   nunca perdio esa plata.
 */
class CashTotalsService
{
    /**
     * @return array{
     *   total_charged: float,
     *   total_cash: float,
     *   total_other_methods: float,
     *   total_expenses: float,
     *   total_commissions: float,
     *   opening_cash: float,
     *   expected_cash: float,
     *   appointments: int,
     *   payment_breakdown: list<array{id:int|null, label:string, counts_as_cash:bool, total:float}>,
     * }
     */
    public function between(
        int $businessId,
        CarbonImmutable $from,
        CarbonImmutable $to,
        float $openingCash = 0,
        ?int $userId = null,
    ): array {
        $appointments = Appointment::withoutGlobalScope('business')
            ->where('business_id', $businessId)
            ->whereNotNull('checked_out_at')
            ->whereBetween('checked_out_at', [$from->utc(), $to->utc()])
            // Por quien COBRO, no por quien agendo: el efectivo de un turno es
            // lo que esa persona recibio en su ventana.
            ->when($userId, fn ($q) => $q->where('checked_out_by_user_id', $userId))
            ->with('paymentMethod')
            ->get();

        $expenses = Expense::withoutGlobalScope('business')
            ->where('business_id', $businessId)
            ->where('scope', Expense::SCOPE_OPERATIONAL)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->with('paymentMethod')
            ->get();

        return $this->build($businessId, $appointments, $expenses, $openingCash);
    }

    /** Totales de un dia completo, en la zona del negocio. */
    public function forDate(int $businessId, CarbonImmutable $date, float $openingCash = 0): array
    {
        $start = $date->startOfDay();

        return $this->between($businessId, $start, $start->addDay(), $openingCash);
    }

    /**
     * @param  Collection<int, Appointment>  $appointments
     * @param  Collection<int, Expense>  $expenses
     */
    private function build(int $businessId, Collection $appointments, Collection $expenses, float $openingCash): array
    {
        $methods = PaymentMethod::withoutGlobalScope('business')
            ->where('business_id', $businessId)
            ->get()
            ->keyBy('id');

        $breakdown = [];
        $totalCharged = 0.0;
        $totalCash = 0.0;

        foreach ($appointments as $appointment) {
            $total = (float) ($appointment->total ?? 0);
            $totalCharged += $total;

            $method = $appointment->payment_method_id !== null
                ? $methods->get($appointment->payment_method_id)
                : null;

            $key = $method?->id ?? 0;

            $breakdown[$key] ??= [
                'id' => $method?->id,
                // Una cita cobrada sin metodo no deberia existir, pero si
                // aparece se muestra en vez de desaparecer del cuadre.
                'label' => $method?->name ?? 'Sin método',
                'counts_as_cash' => (bool) ($method?->counts_as_cash ?? false),
                'total' => 0.0,
            ];
            $breakdown[$key]['total'] += $total;

            if ($method?->counts_as_cash) {
                $totalCash += $total;
            }
        }

        // Solo los gastos pagados en efectivo salen del cajon. Uno pagado por
        // transferencia es gasto del negocio pero no toca la caja fisica.
        $totalExpenses = 0.0;
        $cashExpenses = 0.0;

        foreach ($expenses as $expense) {
            $value = (float) $expense->value;
            $totalExpenses += $value;

            if ($expense->paymentMethod?->counts_as_cash ?? true) {
                $cashExpenses += $value;
            }
        }

        $commissions = (float) AppointmentItem::withoutGlobalScope('business')
            ->whereIn('appointment_id', $appointments->pluck('id'))
            ->sum('commission_amount');

        usort($breakdown, fn ($a, $b) => $b['total'] <=> $a['total']);

        return [
            'total_charged' => round($totalCharged, 2),
            'total_cash' => round($totalCash, 2),
            'total_other_methods' => round($totalCharged - $totalCash, 2),
            'total_expenses' => round($totalExpenses, 2),
            'total_commissions' => round($commissions, 2),
            'opening_cash' => round($openingCash, 2),
            // Lo que deberia haber fisicamente: la base, mas lo cobrado en
            // efectivo, menos lo que salio del cajon.
            'expected_cash' => round($openingCash + $totalCash - $cashExpenses, 2),
            'appointments' => $appointments->count(),
            'payment_breakdown' => array_values($breakdown),
        ];
    }
}
