<?php

namespace App\Services\Payroll;

use App\Models\AppointmentItem;
use App\Models\Business;
use App\Models\Expense;
use App\Models\ExpenseType;
use App\Models\PayrollAdjustment;
use App\Models\PayrollSettlement;
use App\Models\PayrollSettlementItem;
use App\Models\Resource;
use App\Models\ServiceRating;
use App\Models\User;
use App\Support\Payroll\AdjustmentCatalog;
use App\Support\Payroll\PayrollCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Liquidar a una profesional: cuanto lleva ganado y cuanto se le entrega.
 *
 * Tres reglas que hacen que esto no se descuadre:
 *
 * 1. **El inicio del periodo lo pone el sistema.** Es el dia siguiente al fin
 *    de su ultima liquidacion, o la fecha desde la que se le liquida si es la
 *    primera. En la app del local el "desde" se tecleaba a mano y nada impedia
 *    pagar dos veces la misma quincena.
 *
 * 2. **La comision se gana cuando se COBRA**, no cuando se presto el servicio.
 *    Es la misma regla del cierre de caja: si no, la nomina y la caja cuentan
 *    la misma plata en dias distintos y nunca cuadran entre si.
 *
 * 3. **Entran TODOS los ajustes pendientes**, no solo los fechados dentro de
 *    la ventana. Un anticipo mas viejo que el periodo seguia pendiente para
 *    siempre porque ninguna ventana lo alcanzaba.
 */
class PayrollService
{
    /**
     * Que le corresponde a esta profesional si se liquidara hasta `$until`.
     *
     * No escribe nada: es lo que se le muestra a quien va a pagar, antes de
     * pagar.
     *
     * @return array<string, mixed>
     */
    public function preview(Business $business, Resource $resource, CarbonImmutable $until): array
    {
        $start = $this->periodStartFor($resource, $business);
        $end = $until->startOfDay();

        if ($end->lt($start)) {
            throw ValidationException::withMessages([
                'until' => "Ya se liquidó hasta el {$start->subDay()->toDateString()}. "
                    ."El siguiente período empieza el {$start->toDateString()}.",
            ]);
        }

        $tz = $business->businessTimezone();

        $items = $this->chargedItems($business, $resource, $start, $end, $tz);
        $adjustments = $this->pendingAdjustments($resource, $end);

        $days = (int) $start->diffInDays($end) + 1;

        $totals = PayrollCalculator::settle(
            $resource->payroll_mode,
            (float) $resource->base_amount,
            $resource->base_period,
            $days,
            $this->daysWithBase($resource, $start, $end),
            (float) $items->sum('commission_amount'),
            (float) $adjustments->where('kind', PayrollAdjustment::KIND_BONUS)->sum('amount'),
            (float) $adjustments->where('kind', PayrollAdjustment::KIND_DEDUCTION)->sum('amount'),
        );

        return [
            'resource' => ['id' => $resource->id, 'name' => $resource->name],
            'mode' => $resource->payroll_mode,
            'base_amount' => (float) $resource->base_amount,
            'base_period' => $resource->base_period,
            'base_until' => $resource->base_until?->toDateString(),
            'period_start' => $start->toDateString(),
            'period_end' => $end->toDateString(),
            'days' => $days,
            'services_count' => $items->count(),
            'charged_total' => round((float) $items->sum('charged'), 2),
            'items' => $items->values()->all(),

            /*
             * Las garantias que RECIBIO en el periodo.
             *
             * Van en la liquidacion porque es el momento en que alguien mira
             * el trabajo de esa persona con la plata delante, y es cuando se
             * decide si hay una conversacion, una capacitacion o un descuento.
             *
             * Se MUESTRAN, no se descuentan solas. Una multa automatica por un
             * numero sin contexto es la clase de regla que convierte un
             * esmalte corrido en un descuento de nomina, y eso se pelea. Si el
             * negocio decide multar, lo hace con un ajuste, que queda firmado
             * por quien lo puso.
             */
            'warranties' => $this->warrantiesFor($business, $resource, $start, $end, $tz),

            /*
             * Como la calificaron en el periodo, al lado de las garantias.
             *
             * Van juntas porque responden la misma pregunta desde dos lados:
             * las garantias dicen cuantas veces hubo que rehacer su trabajo,
             * la calificacion dice que opinaron los que si quedaron conformes.
             * Mirar una sin la otra lleva a conclusiones injustas en las dos
             * direcciones.
             */
            'ratings' => $this->ratingsFor($business, $resource, $start, $end, $tz),
            'adjustments' => $adjustments->map(fn (PayrollAdjustment $a) => [
                'id' => $a->id,
                'date' => $a->date->toDateString(),
                'kind' => $a->kind,
                'category' => $a->category,
                // La etiqueta viaja resuelta: que el front la busque en otro
                // endpoint lo deja mostrando el nombre tecnico mientras esa
                // segunda peticion no llega.
                'category_label' => AdjustmentCatalog::label($a->category),
                'amount' => (float) $a->amount,
                'description' => $a->description,
                // Se marca el que quedo fuera de la ventana para que quien
                // paga entienda por que aparece un anticipo de hace dos meses.
                'outside_period' => $a->date->lt($start),
            ])->values()->all(),
        ] + $totals;
    }

    /**
     * Registra el pago. Congela las lineas, reclama los ajustes pendientes y
     * deja el gasto correspondiente en la caja del negocio.
     */
    public function settle(
        Business $business,
        Resource $resource,
        CarbonImmutable $until,
        User $actor,
        ?int $paymentMethodId = null,
        ?string $notes = null,
    ): PayrollSettlement {
        $preview = $this->preview($business, $resource, $until);

        return DB::transaction(function () use ($business, $resource, $preview, $actor, $paymentMethodId, $notes) {
            $settlement = PayrollSettlement::create([
                'business_id' => $business->id,
                'resource_id' => $resource->id,
                'period_start' => $preview['period_start'],
                'period_end' => $preview['period_end'],
                'mode' => $preview['mode'],
                'base_amount' => $preview['base_amount'],
                'base_period' => $preview['base_period'],
                'services_count' => $preview['services_count'],
                'charged_total' => $preview['charged_total'],
                'commission_total' => $preview['commission_total'],
                'base_total' => $preview['base_total'],
                'bonus_total' => $preview['bonus_total'],
                'deduction_total' => $preview['deduction_total'],
                'net_total' => $preview['net_total'],
                'paid_at' => CarbonImmutable::now(),
                'payment_method_id' => $paymentMethodId,
                'created_by_user_id' => $actor->id,
                'notes' => $notes,
            ]);

            foreach ($preview['items'] as $item) {
                PayrollSettlementItem::create([
                    'business_id' => $business->id,
                    'settlement_id' => $settlement->id,
                    'appointment_item_id' => $item['appointment_item_id'],
                    'charged_at' => $item['charged_at'],
                    'service_name' => $item['service_name'],
                    'client_name' => $item['client_name'],
                    'charged' => $item['charged'],
                    'commission_rate' => $item['commission_rate'],
                    'commission_amount' => $item['commission_amount'],
                ]);
            }

            // Reclamar los ajustes: a partir de aca ya no estan pendientes.
            PayrollAdjustment::withoutGlobalScope('business')
                ->whereIn('id', array_column($preview['adjustments'], 'id'))
                ->whereNull('settlement_id')
                ->update(['settlement_id' => $settlement->id]);

            $expense = $this->recordExpense($business, $resource, $settlement, $actor, $paymentMethodId);
            $settlement->update(['expense_id' => $expense?->id]);

            return $settlement->fresh(['items', 'adjustments', 'resource']);
        });
    }

    /**
     * Deshace la ultima liquidacion de una profesional.
     *
     * Solo la ultima: si se pudiera borrar una del medio, el periodo siguiente
     * quedaria arrancando despues de un hueco que nadie liquido nunca.
     */
    public function undo(PayrollSettlement $settlement): void
    {
        $ultima = PayrollSettlement::withoutGlobalScope('business')
            ->where('resource_id', $settlement->resource_id)
            ->orderByDesc('period_end')
            ->first();

        if ($ultima?->id !== $settlement->id) {
            throw ValidationException::withMessages([
                'settlement' => 'Solo se puede deshacer la última liquidación de esta persona.',
            ]);
        }

        DB::transaction(function () use ($settlement) {
            // Los ajustes vuelven a estar pendientes; las lineas se van con la
            // liquidacion (cascadeOnDelete).
            PayrollAdjustment::withoutGlobalScope('business')
                ->where('settlement_id', $settlement->id)
                ->update(['settlement_id' => null]);

            if ($settlement->expense_id) {
                Expense::withoutGlobalScope('business')->whereKey($settlement->expense_id)->delete();
            }

            $settlement->delete();
        });
    }

    /**
     * Que hay pendiente para cada profesional activa, sin escribir nada.
     *
     * Es lo que el dueno mira antes de sacar la plata: quien lleva cuanto.
     *
     * @return list<array<string, mixed>>
     */
    /**
     * @param  list<int>|null  $locationIds  null = todas las sedes.
     */
    public function pending(Business $business, CarbonImmutable $until, ?array $locationIds = null): array
    {
        return Resource::where('type', Resource::TYPE_STAFF)
            ->where('is_active', true)
            /*
             * Por la sede de LA PERSONA, no la de sus citas.
             *
             * Es la unica lectura que no descuadra la liquidacion: lo que se
             * le paga sale de todo lo que atendio, y una persona que se
             * traslado a mitad de quincena tiene comisiones de los dos
             * locales. Partirlas por la sede de cada cita dejaria media
             * liquidacion en cada lista y ninguna de las dos se podria pagar.
             */
            ->when($locationIds !== null, fn ($q) => $q->whereIn('location_id', $locationIds))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(function (Resource $resource) use ($business, $until) {
                try {
                    $preview = $this->preview($business, $resource, $until);
                } catch (ValidationException) {
                    // Ya se le liquido mas alla de esta fecha: no tiene nada
                    // pendiente, no es un error.
                    return null;
                }

                return [
                    'resource_id' => $resource->id,
                    'name' => $resource->name,
                    'mode' => $preview['mode'],
                    'period_start' => $preview['period_start'],
                    'period_end' => $preview['period_end'],
                    'services_count' => $preview['services_count'],
                    'commission_total' => $preview['commission_total'],
                    'base_total' => $preview['base_total'],
                    'bonus_total' => $preview['bonus_total'],
                    'deduction_total' => $preview['deduction_total'],
                    'net_total' => $preview['net_total'],
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /*
    |--------------------------------------------------------------------------
    | Internos
    |--------------------------------------------------------------------------
    */

    /**
     * El dia en que arranca el periodo. No lo elige quien liquida.
     */
    private function periodStartFor(Resource $resource, Business $business): CarbonImmutable
    {
        $ultima = PayrollSettlement::withoutGlobalScope('business')
            ->where('resource_id', $resource->id)
            ->orderByDesc('period_end')
            ->first();

        if ($ultima) {
            return CarbonImmutable::parse($ultima->period_end)->addDay()->startOfDay();
        }

        if ($resource->payroll_started_on) {
            return CarbonImmutable::parse($resource->payroll_started_on)->startOfDay();
        }

        // Sin fecha de arranque, desde que existe la ficha. Nunca "desde
        // siempre": eso barreria con servicios que ya se pagaron por fuera.
        return CarbonImmutable::parse($resource->created_at)
            ->setTimezone($business->businessTimezone())
            ->startOfDay();
    }

    /**
     * Cuantos dias del periodo tenian base vigente.
     *
     * Solo importa cuando la base es temporal -- la que se le da a quien entra
     * mientras arma clientela. Si `base_until` cae en mitad del periodo, se
     * paga la parte proporcional y no el periodo completo.
     */
    private function daysWithBase(Resource $resource, CarbonImmutable $start, CarbonImmutable $end): int
    {
        if (! $resource->base_until) {
            return (int) $start->diffInDays($end) + 1;
        }

        $limit = CarbonImmutable::parse($resource->base_until)->startOfDay();

        if ($limit->lt($start)) {
            return 0;
        }

        $effective = $limit->lt($end) ? $limit : $end;

        return (int) $start->diffInDays($effective) + 1;
    }

    /**
     * Los servicios COBRADOS en la ventana, con su comision congelada.
     *
     * Por `checked_out_at` y no por la fecha del servicio: un servicio prestado
     * el lunes y cobrado el miercoles es plata del miercoles, igual que en el
     * cierre de caja.
     *
     * @return Collection<int, array<string, mixed>>
     */
    /**
     * Las garantias que esa persona RECIBIO en el periodo.
     *
     * Se filtra por `warranty_for_resource_id` y no por quien la atendio: la
     * garantia es de quien hizo el trabajo que fallo, aunque la rehaga otra.
     *
     * Por FECHA DE LA CITA y no por fecha de cobro, a diferencia de las
     * comisiones: una garantia no se cobra, asi que nunca tendria
     * `checked_out_at` y no aparecería nunca en ninguna liquidacion.
     *
     * @return array<string, mixed>
     */
    private function warrantiesFor(
        Business $business,
        Resource $resource,
        CarbonImmutable $start,
        CarbonImmutable $end,
        string $tz,
    ): array {
        $from = $start->setTimezone($tz)->startOfDay()->utc();
        $to = $end->setTimezone($tz)->endOfDay()->utc();

        $rows = AppointmentItem::withoutGlobalScope('business')
            ->where('appointment_items.business_id', $business->id)
            ->where('appointment_items.warranty_for_resource_id', $resource->id)
            ->where('appointment_items.is_warranty', true)
            ->whereBetween('appointment_items.starts_at', [$from, $to])
            ->with(['service', 'resource', 'appointment'])
            ->orderBy('appointment_items.starts_at')
            ->get();

        return [
            'count' => $rows->count(),
            'items' => $rows->map(fn (AppointmentItem $item) => [
                'appointment_item_id' => $item->id,
                'date' => CarbonImmutable::parse($item->starts_at)->setTimezone($tz)->toDateString(),
                'service_name' => $item->service?->name,
                'client_name' => $item->appointment?->client_name,
                // Quien la rehizo: puede no ser la misma persona.
                'done_by' => $item->resource?->name,
                'note' => $item->warranty_note,
            ])->values()->all(),
        ];
    }

    /**
     * Como la calificaron en el periodo.
     *
     * El promedio se calcula sobre las notas que EXISTEN. Tratar una encuesta
     * sin puntualidad como un cero le bajaria el promedio a alguien por algo
     * que el cliente simplemente no contesto.
     *
     * @return array<string, mixed>
     */
    private function ratingsFor(
        Business $business,
        Resource $resource,
        CarbonImmutable $start,
        CarbonImmutable $end,
        string $tz,
    ): array {
        $rows = ServiceRating::withoutGlobalScope('business')
            ->where('business_id', $business->id)
            ->where('resource_id', $resource->id)
            ->whereBetween('created_at', [
                $start->setTimezone($tz)->startOfDay()->utc(),
                $end->setTimezone($tz)->endOfDay()->utc(),
            ])
            ->orderByDesc('created_at')
            ->get();

        $promedio = fn (string $campo) => $rows->whereNotNull($campo)->isEmpty()
            ? null
            : round((float) $rows->whereNotNull($campo)->avg($campo), 2);

        return [
            'count' => $rows->count(),
            'staff_average' => $promedio('staff_rating'),
            'service_average' => $promedio('service_rating'),
            'punctuality_average' => $promedio('punctuality_rating'),
            // Solo los comentarios escritos: una lista de nulos no dice nada.
            'comments' => $rows->whereNotNull('comment')->take(10)->map(fn (ServiceRating $r) => [
                'comment' => $r->comment,
                'staff_rating' => $r->staff_rating,
                'date' => $r->created_at?->setTimezone($tz)->toDateString(),
            ])->values()->all(),
        ];
    }

    private function chargedItems(
        Business $business,
        Resource $resource,
        CarbonImmutable $start,
        CarbonImmutable $end,
        string $tz,
    ): Collection {
        $from = $start->setTimezone($tz)->startOfDay()->utc();
        $to = $end->setTimezone($tz)->endOfDay()->utc();

        return AppointmentItem::withoutGlobalScope('business')
            ->where('appointment_items.business_id', $business->id)
            ->where('appointment_items.resource_id', $resource->id)
            ->join('appointments', 'appointments.id', '=', 'appointment_items.appointment_id')
            ->whereNotNull('appointments.checked_out_at')
            ->whereBetween('appointments.checked_out_at', [$from, $to])
            ->with(['service'])
            ->orderBy('appointments.checked_out_at')
            ->get([
                'appointment_items.*',
                'appointments.checked_out_at as checked_out_at',
                'appointments.client_name as appointment_client_name',
            ])
            ->map(fn (AppointmentItem $item) => [
                'appointment_item_id' => $item->id,
                'charged_at' => CarbonImmutable::parse($item->checked_out_at)->setTimezone($tz)->toIso8601String(),
                'service_name' => $item->service?->name ?? 'Servicio',
                'client_name' => $item->appointment_client_name,
                'charged' => (float) ($item->final_price ?? $item->price),
                'commission_rate' => $item->commission_rate === null ? null : (float) $item->commission_rate,
                'commission_amount' => (float) ($item->commission_amount ?? 0),
            ]);
    }

    /**
     * Ajustes sin liquidar con fecha hasta el fin del periodo.
     *
     * Sin piso inferior a proposito: lo pendiente es pendiente aunque sea mas
     * viejo que la ventana.
     *
     * @return Collection<int, PayrollAdjustment>
     */
    private function pendingAdjustments(Resource $resource, CarbonImmutable $end): Collection
    {
        return PayrollAdjustment::withoutGlobalScope('business')
            ->where('resource_id', $resource->id)
            ->whereNull('settlement_id')
            ->whereDate('date', '<=', $end->toDateString())
            ->orderBy('date')
            ->get();
    }

    /**
     * El gasto que este pago genera.
     *
     * Alcance administrativo: la nomina no es gasto de operar el dia. Pero si
     * se pago en efectivo igual descuenta del cajon -- de eso se encarga
     * CashSummary, que mira el metodo y no la clasificacion.
     */
    private function recordExpense(
        Business $business,
        Resource $resource,
        PayrollSettlement $settlement,
        User $actor,
        ?int $paymentMethodId,
    ): ?Expense {
        if ((float) $settlement->net_total <= 0) {
            // Neto cero o negativo: no salio plata, no hay gasto que registrar.
            return null;
        }

        $type = ExpenseType::withoutGlobalScope('business')
            ->where('business_id', $business->id)
            ->where('name', self::EXPENSE_TYPE)
            ->first()
            ?? ExpenseType::create([
                'business_id' => $business->id,
                'name' => self::EXPENSE_TYPE,
                'is_active' => true,
                'sort_order' => 90,
            ]);

        return Expense::create([
            'business_id' => $business->id,
            /*
             * El cajon de SU sede.
             *
             * Pagarle en efectivo a la manicurista de Cedritos saca billetes
             * del cajon de Cedritos, no del de Chapinero. Sin esta linea el
             * gasto quedaba sin sede y desaparecia de los dos cierres: el dia
             * quedaba corto por el monto de la liquidacion sin nada que lo
             * explicara. Es el mismo bug que ya habia costado el gasto
             * administrativo pagado en efectivo, por otro camino.
             */
            'location_id' => $resource->location_id,
            'expense_type_id' => $type->id,
            'date' => CarbonImmutable::parse($settlement->paid_at)
                ->setTimezone($business->businessTimezone())
                ->toDateString(),
            'description' => "Liquidación de {$resource->name} "
                ."({$settlement->period_start->toDateString()} a {$settlement->period_end->toDateString()})",
            'value' => $settlement->net_total,
            'scope' => Expense::SCOPE_ADMINISTRATIVE,
            'payment_method_id' => $paymentMethodId,
            'created_by_user_id' => $actor->id,
        ]);
    }

    private const EXPENSE_TYPE = 'Nómina';
}
