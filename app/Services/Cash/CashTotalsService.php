<?php

namespace App\Services\Cash;

use App\Models\Appointment;
use App\Models\AppointmentItem;
use App\Models\Expense;
use App\Models\PaymentMethod;
use App\Models\ProductSale;
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
    /**
     * @param  list<int>|null  $locationIds  null = todas las sedes del negocio.
     */
    public function between(
        int $businessId,
        CarbonImmutable $from,
        CarbonImmutable $to,
        float $openingCash = 0,
        ?int $userId = null,
        ?array $locationIds = null,
    ): array {
        /*
         * El efectivo es FISICO: hay un cajon en Chapinero y otro en
         * Cedritos. Un total que sume los dos no se puede cuadrar contra
         * ninguno, y la diferencia -- el unico dato que importa de un cierre
         * -- deja de significar nada.
         *
         * La cita filtra por SU sede congelada, no por la de quien atendio:
         * si esa persona se traslado, la caja de ese dia no puede cambiar de
         * local a posteriori.
         */
        /*
         * El borde de arriba: un dia completo llega como [00:00, 00:00 del
         * siguiente) y se cierra ANTES de `to` -- con whereBetween, un cobro a
         * medianoche en punto entraba en los dos dias. Un turno llega hasta
         * AHORA y ahi `to` si cuenta: lo que se cobro en este segundo es de
         * este turno.
         */
        $antesDe = $to->equalTo($to->startOfDay()) ? '<' : '<=';

        $porSede = fn ($q) => $q->when(
            $locationIds !== null,
            fn ($qq) => $qq->whereIn('location_id', $locationIds),
        );

        $appointments = Appointment::withoutGlobalScope('business')
            ->where('business_id', $businessId)
            ->whereNotNull('checked_out_at')
            ->where('checked_out_at', '>=', $from->utc())
            ->where('checked_out_at', $antesDe, $to->utc())
            ->tap($porSede)
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
            /*
             * El gasto SIN sede es del negocio entero -- la contadora, el
             * dominio -- y por eso entra en cualquier cierre... o en ninguno.
             *
             * Entra en ninguno: descontarlo del cajon de Chapinero le dejaria
             * el cierre corto por una plata que ese cajon nunca tuvo. Se ve en
             * el reporte general, que es donde ese gasto significa algo.
             */
            ->when(
                $locationIds !== null,
                fn ($q) => $q->whereIn('location_id', $locationIds),
            )
            ->where(function ($q) {
                $q->where('scope', Expense::SCOPE_OPERATIONAL)
                    ->orWhereHas('paymentMethod', fn ($m) => $m->where('counts_as_cash', true))
                    // Sin metodo se asume efectivo, igual que abajo.
                    ->orWhereNull('payment_method_id');
            })
            /*
             * Hasta el dia ANTERIOR a `to` cuando `to` es medianoche. El dia
             * completo llega como [hoy 00:00, mañana 00:00), y el whereBetween
             * por fecha metia tambien los gastos de MAÑANA: un gasto fechado
             * mañana se descontaba en los dos cierres.
             */
            ->whereBetween('date', [
                $from->toDateString(),
                ($to->equalTo($to->startOfDay()) ? $to->subDay() : $to)->toDateString(),
            ])
            ->with('paymentMethod')
            ->get();

        /*
         * Los abonos que ENTRARON en esta ventana, aunque su cita se cobre
         * otro dia -- o no se cobre nunca.
         *
         * La plata llego el dia que llego. Contarla el dia del servicio dejaria
         * el cierre de hoy largo y el de la semana entrante corto, sin nada que
         * lo explique.
         *
         * Solo en el total del DIA, no en el de un turno: quien confirma una
         * transferencia no es necesariamente quien esta en caja, y meterla en
         * su turno le cuadraria mal el cajon a esa persona.
         */
        $deposits = $userId !== null
            ? collect()
            : Appointment::withoutGlobalScope('business')
                ->where('business_id', $businessId)
                ->whereNotNull('deposit_paid_at')
                ->where('deposit_paid_at', '>=', $from->utc())
                ->where('deposit_paid_at', $antesDe, $to->utc())
                ->tap($porSede)
                ->with('depositPaymentMethod')
                ->get();

        /*
         * El producto que se vendio en la ventana.
         *
         * No entraba en la caja: la pantalla de cobro le dice a quien cobra
         * «a cobrar en total» servicio + crema, la crema se pagaba en
         * efectivo, y el cajon quedaba largo por esa plata sin que el
         * cierre la esperara. Las sin sede cuentan, igual que en el resumen:
         * nula es "del negocio", no "de ninguno".
         */
        $products = ProductSale::withoutGlobalScope('business')
            ->where('business_id', $businessId)
            ->where('sold_at', '>=', $from->utc())
            ->where('sold_at', $antesDe, $to->utc())
            ->when($locationIds !== null, fn ($q) => $q->where(
                fn ($qq) => $qq->whereIn('location_id', $locationIds)->orWhereNull('location_id'),
            ))
            ->when($userId, fn ($q) => $q->where('sold_by_user_id', $userId))
            ->get();

        return $this->build($businessId, $appointments, $expenses, $openingCash, $deposits, $products);
    }

    /**
     * Totales de un dia completo, en la zona del negocio.
     *
     * @param  list<int>|null  $locationIds  null = todas las sedes.
     */
    public function forDate(
        int $businessId,
        CarbonImmutable $date,
        float $openingCash = 0,
        ?array $locationIds = null,
    ): array {
        $start = $date->startOfDay();

        return $this->between($businessId, $start, $start->addDay(), $openingCash, null, $locationIds);
    }

    /**
     * @param  Collection<int, Appointment>  $appointments
     * @param  Collection<int, Expense>  $expenses
     * @param  Collection<int, Appointment>  $deposits  Abonos recibidos en la ventana.
     */
    private function build(
        int $businessId,
        Collection $appointments,
        Collection $expenses,
        float $openingCash,
        Collection $deposits = new Collection,
        Collection $products = new Collection,
    ): array {
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
                /*
                 * Lo que entro POR ESTE METODO hoy, no la venta completa.
                 *
                 * Si el cliente abono 30.000 la semana pasada por Nequi, en el
                 * mostrador solo dejo el resto. Cobrar el total contra el
                 * metodo del cierre dejaria la caja del dia larga por el
                 * abono, todos los dias, sin nada que lo explique.
                 */
                'amount' => round((float) ($appointment->total ?? 0) - $appointment->depositPaid(), 2),
                'method_id' => $method?->id,
                // Una cita cobrada sin metodo no deberia existir, pero si
                // aparece se muestra en vez de desaparecer del cuadre.
                'method_label' => $method?->name ?? 'Sin método',
                'counts_as_cash' => (bool) ($method?->counts_as_cash ?? false),
            ];
        })->values()->all();

        foreach ($deposits as $abono) {
            $method = $abono->depositPaymentMethod;

            $charges[] = [
                'amount' => (float) $abono->deposit_amount,
                'method_id' => $method?->id,
                'method_label' => $method?->name ?? 'Abono sin método',
                'counts_as_cash' => (bool) ($method?->counts_as_cash ?? false),
            ];
        }

        foreach ($products as $venta) {
            $method = $venta->payment_method_id !== null ? $methods->get($venta->payment_method_id) : null;

            $charges[] = [
                'amount' => (float) $venta->total,
                'method_id' => $method?->id,
                'method_label' => $method?->name ?? 'Sin método',
                'counts_as_cash' => (bool) ($method?->counts_as_cash ?? false),
            ];
        }

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
