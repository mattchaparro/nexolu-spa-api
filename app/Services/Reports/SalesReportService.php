<?php

namespace App\Services\Reports;

use App\Models\AppointmentItem;
use App\Models\Business;
use App\Models\Resource;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * El reporte de ventas: cuanto entro, de quien, y por que medio.
 *
 * Es la pregunta que el dueño hace todos los dias -- "¿cuanto entro hoy?",
 * "¿cuanto hizo cada persona?", "¿cuanto de eso es comision?" -- y hasta ahora
 * solo se podia responder mirando el cierre de UN dia.
 *
 * Se apoya en la misma regla que la caja y la nomina: la venta cuenta por
 * CUANDO SE COBRO (`checked_out_at`), no por cuando se agendo ni por cuando se
 * presto. Tres modulos contando la misma plata en dias distintos es como se
 * llega a que ningun numero cuadre con ningun otro.
 *
 * Se lee de `appointment_items` y no de `appointments` porque una cita puede
 * llevar servicios de dos personas distintas: agrupar por cita atribuiria todo
 * a una sola.
 */
class SalesReportService
{
    /**
     * @return array<string, mixed>
     */
    public function build(
        Business $business,
        CarbonImmutable $from,
        CarbonImmutable $to,
        ?int $resourceId = null,
        ?int $paymentMethodId = null,
        ?array $locationIds = null,
    ): array {
        $tz = $business->businessTimezone();

        $items = $this->items($business, $from, $to, $tz, $resourceId, $paymentMethodId, $locationIds);

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'totals' => $this->totals($items),
            /*
             * El corte por sede es el que de verdad estrena el multi-sede: la
             * pregunta del dueno de dos locales no es "cuanto se hizo", es
             * "cual de los dos esta jalando". Va aunque se haya filtrado a una
             * sola sede -- entonces trae una fila -- para que la pantalla no
             * tenga que armar dos formas distintas de la misma respuesta.
             */
            'by_location' => $this->byLocation($items),
            'by_person' => $this->byPerson($items),
            'by_payment_method' => $this->byPaymentMethod($items),
            'by_service' => $this->byService($items),
            'by_day' => $this->byDay($items, $tz),
        ];
    }

    /**
     * Las lineas cobradas del rango.
     *
     * Un solo `get()` y todos los cortes se hacen en memoria: son las lineas
     * de un negocio en un rango de dias, no un data warehouse. Cinco consultas
     * agregadas distintas serian mas rapido en el papel y garantizarian que
     * los subtotales no sumen el total el dia que una de ellas cambie de
     * filtro y las otras no.
     *
     * @return Collection<int, object>
     */
    private function items(
        Business $business,
        CarbonImmutable $from,
        CarbonImmutable $to,
        string $tz,
        ?int $resourceId,
        ?int $paymentMethodId,
        ?array $locationIds = null,
    ): Collection {
        $start = $from->setTimezone($tz)->startOfDay()->utc();
        $end = $to->setTimezone($tz)->endOfDay()->utc();

        return AppointmentItem::query()
            ->join('appointments', 'appointments.id', '=', 'appointment_items.appointment_id')
            ->leftJoin('locations', 'locations.id', '=', 'appointments.location_id')
            ->leftJoin('resources', 'resources.id', '=', 'appointment_items.resource_id')
            ->leftJoin('services', 'services.id', '=', 'appointment_items.service_id')
            ->leftJoin('payment_methods', 'payment_methods.id', '=', 'appointments.payment_method_id')
            ->where('appointment_items.business_id', $business->id)
            ->whereNotNull('appointments.checked_out_at')
            ->whereBetween('appointments.checked_out_at', [$start, $end])
            ->when($resourceId, fn ($q) => $q->where('appointment_items.resource_id', $resourceId))
            ->when($paymentMethodId, fn ($q) => $q->where('appointments.payment_method_id', $paymentMethodId))
            // Por la sede de la CITA, congelada al agendar: si esa persona se
            // traslado, la venta de marzo no se muda de local con ella.
            ->when($locationIds !== null, fn ($q) => $q->whereIn('appointments.location_id', $locationIds))
            ->get([
                'appointment_items.id',
                'appointment_items.resource_id',
                'appointments.location_id',
                'locations.name as location_name',
                'appointment_items.service_id',
                // `final_price` es lo que de verdad se cobro, con el descuento
                // ya repartido. `price` es el de lista y sumaria de mas.
                'appointment_items.final_price',
                'appointment_items.commission_amount',
                'appointments.checked_out_at',
                'appointments.payment_method_id',
                'resources.name as resource_name',
                'services.name as service_name',
                'payment_methods.name as method_name',
                'payment_methods.counts_as_cash',
            ]);
    }

    /** @param Collection<int, object> $items */
    private function totals(Collection $items): array
    {
        $charged = (float) $items->sum(fn ($i) => (float) ($i->final_price ?? 0));
        $commission = (float) $items->sum(fn ($i) => (float) ($i->commission_amount ?? 0));

        return [
            'services' => $items->count(),
            'charged' => round($charged, 2),
            'commission' => round($commission, 2),
            // Lo que le queda al negocio despues de pagar comisiones. No es
            // utilidad -- faltan arriendo, insumos y nomina fija -- y por eso
            // se llama asi y no "ganancia".
            'after_commission' => round($charged - $commission, 2),
            'cash' => round((float) $items->where('counts_as_cash', true)
                ->sum(fn ($i) => (float) ($i->final_price ?? 0)), 2),
            'average_ticket' => $items->isEmpty() ? 0.0 : round($charged / $items->count(), 2),
        ];
    }

    /**
     * Cuanto hizo cada persona y cuanto de eso es su comision.
     *
     * @param  Collection<int, object>  $items
     */
    /**
     * Cuanto hizo cada local.
     *
     * Es el corte que estrena el multi-sede: la pregunta del dueno de dos
     * locales no es "cuanto se hizo", es "cual de los dos esta jalando".
     *
     * @param  Collection<int, object>  $items
     */
    private function byLocation(Collection $items): array
    {
        return $items
            ->groupBy('location_id')
            ->map(function (Collection $group) {
                $charged = (float) $group->sum(fn ($i) => (float) ($i->final_price ?? 0));

                return [
                    'location_id' => $group->first()->location_id,
                    // Lo anterior a las sedes no desaparece del reporte: se
                    // muestra por lo que es, en vez de sumarse en silencio a
                    // un local que no lo atendio.
                    'name' => $group->first()->location_name ?? 'Sin sede',
                    'services' => $group->count(),
                    'charged' => round($charged, 2),
                    'commission' => round(
                        (float) $group->sum(fn ($i) => (float) ($i->commission_amount ?? 0)),
                        2,
                    ),
                ];
            })
            ->sortByDesc('charged')
            ->values()
            ->all();
    }

    /** @param Collection<int, object> $items */
    private function byPerson(Collection $items): array
    {
        return $items
            ->groupBy('resource_id')
            ->map(function (Collection $group) {
                $charged = (float) $group->sum(fn ($i) => (float) ($i->final_price ?? 0));
                $commission = (float) $group->sum(fn ($i) => (float) ($i->commission_amount ?? 0));

                return [
                    'resource_id' => $group->first()->resource_id,
                    'name' => $group->first()->resource_name ?? 'Sin asignar',
                    'services' => $group->count(),
                    'charged' => round($charged, 2),
                    'commission' => round($commission, 2),
                    // El porcentaje efectivo del periodo. Con servicios a
                    // porcentajes distintos no es ninguno de ellos, y es el
                    // numero que de verdad dice cuanto cuesta esa persona.
                    'effective_rate' => $charged > 0 ? round($commission / $charged, 4) : null,
                ];
            })
            ->sortByDesc('charged')
            ->values()
            ->all();
    }

    /** @param Collection<int, object> $items */
    private function byPaymentMethod(Collection $items): array
    {
        return $items
            ->groupBy('payment_method_id')
            ->map(fn (Collection $group) => [
                'payment_method_id' => $group->first()->payment_method_id,
                'name' => $group->first()->method_name ?? 'Sin método',
                'counts_as_cash' => (bool) $group->first()->counts_as_cash,
                'services' => $group->count(),
                'charged' => round((float) $group->sum(fn ($i) => (float) ($i->final_price ?? 0)), 2),
            ])
            ->sortByDesc('charged')
            ->values()
            ->all();
    }

    /** @param Collection<int, object> $items */
    private function byService(Collection $items): array
    {
        return $items
            ->groupBy('service_id')
            ->map(fn (Collection $group) => [
                'service_id' => $group->first()->service_id,
                'name' => $group->first()->service_name ?? 'Sin servicio',
                'services' => $group->count(),
                'charged' => round((float) $group->sum(fn ($i) => (float) ($i->final_price ?? 0)), 2),
            ])
            ->sortByDesc('charged')
            ->values()
            ->all();
    }

    /**
     * Dia por dia, en la zona del negocio.
     *
     * Sin convertir la zona, un cobro de las 8pm en Bogota cae al dia
     * siguiente porque en UTC ya es la 1am.
     *
     * @param  Collection<int, object>  $items
     */
    private function byDay(Collection $items, string $tz): array
    {
        return $items
            ->groupBy(fn ($i) => CarbonImmutable::parse($i->checked_out_at)->setTimezone($tz)->toDateString())
            ->map(fn (Collection $group, string $date) => [
                'date' => $date,
                'services' => $group->count(),
                'charged' => round((float) $group->sum(fn ($i) => (float) ($i->final_price ?? 0)), 2),
                'commission' => round((float) $group->sum(fn ($i) => (float) ($i->commission_amount ?? 0)), 2),
            ])
            ->sortBy('date')
            ->values()
            ->all();
    }

    /** El equipo, para poblar el filtro. */
    public function filterableResources(Business $business): array
    {
        return Resource::where('type', Resource::TYPE_STAFF)
            ->orderBy('sort_order')->orderBy('name')
            ->get()
            ->map(fn (Resource $r) => ['id' => $r->id, 'name' => $r->name])
            ->values()
            ->all();
    }
}
