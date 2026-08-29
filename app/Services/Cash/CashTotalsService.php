<?php

namespace App\Services\Cash;

use App\Models\Appointment;
use App\Models\AppointmentItem;
use App\Models\Expense;
use App\Models\PaymentMethod;
use App\Support\Money\CashSummary;
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
     *   cash_out: float,
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

        /*
         * Un gasto administrativo (arriendo, nomina, impuestos) no cuenta
         * contra la caja del mostrador... salvo que se haya pagado EN
         * EFECTIVO, porque entonces la plata si salio del cajon.
         *
         * Excluirlo por su clasificacion contable era el bug: pagarle a una
         * profesional en efectivo dejaba el cierre del dia corto por ese monto
         * sin nada que lo explicara. Lo que sale del cajon sale del cajon; el
         * alcance sirve para los reportes, no para contar billetes.
         */
        $expenses = Expense::withoutGlobalScope('business')
            ->where('business_id', $businessId)
            ->where(function ($q) {
                $q->where('scope', Expense::SCOPE_OPERATIONAL)
                    ->orWhereHas('paymentMethod', fn ($m) => $m->where('counts_as_cash', true))
                    // Sin metodo se asume efectivo, igual que abajo.
                    ->orWhereNull('payment_method_id');
            })
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

        // Este servicio solo traduce filas de la base a la forma que espera el
        // calculo. La aritmetica del cuadre vive en CashSummary, sin base de
        // datos, para poder probarla con casos escritos a mano.
        $charges = $appointments->map(function (Appointment $appointment) use ($methods) {
            $method = $appointment->payment_method_id !== null
                ? $methods->get($appointment->payment_method_id)
                : null;

            return [
                'amount' => (float) ($appointment->total ?? 0),
                'method_id' => $method?->id,
                // Una cita cobrada sin metodo no deberia existir, pero si
                // aparece se muestra en vez de desaparecer del cuadre.
                'method_label' => $method?->name ?? 'Sin método',
                'counts_as_cash' => (bool) ($method?->counts_as_cash ?? false),
            ];
        })->values()->all();

        $expenseRows = $expenses->map(fn (Expense $expense) => [
            'value' => (float) $expense->value,
            // Sin metodo se asume efectivo: es lo mas comun en un gasto de
            // mostrador, y asumir lo contrario dejaria caja de mas.
            'counts_as_cash' => $expense->paymentMethod?->counts_as_cash ?? true,
            // Solo lo operacional entra al "gasto del dia". Lo administrativo
            // igual descuenta caja si se pago en efectivo.
            'operational' => $expense->scope === Expense::SCOPE_OPERATIONAL,
        ])->values()->all();

        $commissions = (float) AppointmentItem::withoutGlobalScope('business')
            ->whereIn('appointment_id', $appointments->pluck('id'))
            ->sum('commission_amount');

        return CashSummary::build($charges, $expenseRows, $openingCash) + [
            'total_commissions' => round($commissions, 2),
            'appointments' => $appointments->count(),
        ];
    }
}
