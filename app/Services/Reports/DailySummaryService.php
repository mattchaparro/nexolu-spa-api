<?php

namespace App\Services\Reports;

use App\Models\Appointment;
use App\Models\Business;
use App\Models\ProductSale;
use App\Models\PayrollSettlementItem;
use App\Services\Cash\CashTotalsService;
use Carbon\CarbonImmutable;

/**
 * «¿Cómo nos fue hoy?»: lo cobrado, las citas y cada persona.
 *
 * Salió del controlador del resumen diario para que el asistente del panel
 * conteste con la MISMA cuenta que la pantalla. Dos cálculos del mismo día
 * terminan diciendo cifras distintas, y entonces ninguno se cree.
 */
class DailySummaryService
{
    /**
     * @param  list<int>|null  $sedes  null = todas.
     * @return array<string, mixed>
     */
    public function build(Business $business, CarbonImmutable $date, ?array $sedes = null): array
    {
        $totals = app(CashTotalsService::class)->forDate($business->id, $date, 0, $sedes);

        $appointments = Appointment::query()
            ->with(['items.service', 'items.resource'])
            ->when($sedes !== null, fn ($q) => $q->whereIn('location_id', $sedes))
            ->whereBetween('starts_at', [$date->startOfDay()->utc(), $date->addDay()->startOfDay()->utc()])
            ->get();

        /*
         * Por profesional: lo que COBRO hoy, en la misma base que los totales
         * de arriba -- fecha de cobro, igual que la caja y la nomina. Con la
         * fecha de la cita, cobrar hoy una cita de manana hacia que "entro
         * 25.000" conviviera con una tabla por persona en ceros.
         */
        $cobradas = Appointment::query()
            ->with(['items.resource', 'items.service', 'paymentMethod'])
            ->when($sedes !== null, fn ($q) => $q->whereIn('location_id', $sedes))
            ->whereNotNull('checked_out_at')
            ->where('checked_out_at', '>=', $date->startOfDay()->utc())
            ->where('checked_out_at', '<', $date->addDay()->startOfDay()->utc())
            ->get();

        $byResource = [];

        foreach ($cobradas as $appointment) {
            foreach ($appointment->items as $item) {
                $name = $item->resource?->name ?? 'Sin asignar';

                $byResource[$name] ??= ['name' => $name, 'appointments' => 0, 'charged' => 0.0, 'commission' => 0.0];
                $byResource[$name]['appointments']++;
                $byResource[$name]['charged'] += $item->charged();
                $byResource[$name]['commission'] += (float) ($item->commission_amount ?? 0);
            }
        }

        usort($byResource, fn ($a, $b) => $b['charged'] <=> $a['charged']);

        /*
         * Cada servicio cobrado, uno por uno: qué se hizo, quién, cuánto se
         * cobró, por dónde y cuánto se lleva de comisión. Es la lista que
         * se revisa al final del día para encontrar lo que se subió de más
         * o con el valor equivocado, y desde donde se corrige.
         */
        $tz = $business->businessTimezone();
        $itemIds = $cobradas->flatMap(fn ($a) => $a->items->pluck('id'));
        $liquidadas = PayrollSettlementItem::withoutGlobalScope('business')
            ->whereIn('appointment_item_id', $itemIds)
            ->pluck('appointment_item_id')
            ->flip();

        $lines = $cobradas
            ->sortBy('checked_out_at')
            ->flatMap(fn (Appointment $a) => $a->items->map(fn ($item) => [
                'appointment_id' => $a->id,
                'item_id' => $item->id,
                'charged_at' => CarbonImmutable::parse($a->checked_out_at)->setTimezone($tz)->format('H:i'),
                'client_name' => $a->client_name,
                'service_id' => $item->service_id,
                'service_name' => $item->service?->name ?? 'Servicio',
                'resource_name' => $item->resource?->name ?? 'Sin asignar',
                'price' => (float) $item->price,
                'charged' => $item->charged(),
                'payment_method_id' => $a->payment_method_id,
                'payment_method' => $a->paymentMethod?->name ?? 'Sin método',
                'commission' => (float) ($item->commission_amount ?? 0),
                'discount_reason' => $a->discount_reason,
                'items_in_appointment' => $a->items->count(),
                // Ya pagada en una nómina: no se corrige desde aquí.
                'settled' => $liquidadas->has($item->id),
            ]))
            ->values()
            ->all();

        /*
         * La venta de producto tambien es ingreso del dia.
         *
         * En el sistema viejo esto existia y NO entraba en ningun reporte: se
         * vendio 1.070.000 en producto en año y medio y el dueño no lo veia
         * por ningun lado. Un cierre que solo cuenta servicios dice que entro
         * menos plata de la que entro.
         */
        $producto = ProductSale::query()
            /*
             * Las sin sede cuentan igual.
             *
             * `whereIn` deja fuera los nulos, y una venta sin sede -- una
             * importada, o hecha antes de que el negocio tuviera sedes -- se
             * caia del cierre sin que nadie lo notara. Nulo significa "del
             * negocio", no "de ninguno".
             */
            ->when($sedes !== null, fn ($q) => $q->where(
                fn ($qq) => $qq->whereIn('location_id', $sedes)->orWhereNull('location_id'),
            ))
            ->where('sold_at', '>=', $date->startOfDay()->utc())
            ->where('sold_at', '<', $date->addDay()->startOfDay()->utc())
            ->get();

        return [
            'date' => $date->toDateString(),
            'totals' => $totals,

            // Aparte de `totals` y no sumado adentro: quien mira el cierre
            // necesita saber CUANTO entro por servicio y cuanto por producto,
            // que son dos negocios con margenes distintos.
            'products' => [
                'sales' => $producto->count(),
                'units' => (int) $producto->sum('quantity'),
                'charged' => round((float) $producto->sum('total'), 2),
            ],

            'appointments' => [
                'total' => $appointments->count(),
                'completed' => $appointments->where('status', 'completed')->count(),
                'cancelled' => $appointments->where('status', 'cancelled')->count(),
                'no_show' => $appointments->where('status', 'no_show')->count(),
                /*
                 * Lo que todavia falta cobrar hoy: la accion pendiente mas
                 * comun al cerrar la jornada.
                 *
                 * Se mide por `checked_out_at`, NO por el estado. Marcar
                 * "Completada" no cobra -- son dos actos distintos a
                 * proposito -- asi que filtrar por estado dejaba fuera
                 * justo el caso peligroso: el servicio atendido, marcado
                 * como listo, y nunca cobrado. Desaparecia del cierre sin
                 * que nadie lo notara.
                 */
                'pending_checkout' => $appointments
                    ->whereNotIn('status', ['cancelled', 'no_show'])
                    ->whereNull('checked_out_at')
                    ->count(),
            ],
            'by_resource' => array_values($byResource),
            'lines' => $lines,
        ];
    }
}
