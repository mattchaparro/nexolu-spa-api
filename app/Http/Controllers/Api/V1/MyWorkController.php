<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Appointment;
use App\Models\AppointmentItem;
use App\Models\Business;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Lo que ve una profesional de si misma.
 *
 * Se organiza alrededor de "cuanto voy a cobrar", no de los numeros del
 * negocio. Es la idea que Blue Souls tenia bien: a una manicurista le importa
 * su comision acumulada y cuanto lleva del periodo, no la facturacion total
 * del spa.
 */
class MyWorkController
{
    public function summary(Request $request): JsonResponse
    {
        $user = $request->user();
        $resource = $user->resource;

        if ($resource === null) {
            return response()->json([
                'resource' => null,
                'message' => 'Tu usuario no está asociado a nadie de la agenda.',
            ]);
        }

        $tz = $user->business->businessTimezone();
        $now = CarbonImmutable::now($tz);

        return response()->json([
            'resource' => ['id' => $resource->id, 'name' => $resource->name],
            'today' => $this->earnedBetween($resource->id, $now->startOfDay(), $now->addDay()->startOfDay()),
            'week' => $this->earnedBetween($resource->id, $now->startOfWeek(), $now->addDay()->startOfDay()),
            'month' => $this->earnedBetween($resource->id, $now->startOfMonth(), $now->addDay()->startOfDay()),
            // Lo que atendio pero todavia no cobro: lo primero que tiene que
            // resolver antes de irse.
            'pending_checkout' => $this->pendingCheckout($user->business, $resource->id, $now),
            'agenda' => $this->agenda($resource->id, $now, $tz),
        ]);
    }

    /**
     * Lo cobrado y la comision en un rango, por fecha de COBRO.
     *
     * @return array{services: int, charged: float, commission: float}
     */
    private function earnedBetween(int $resourceId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $items = AppointmentItem::where('resource_id', $resourceId)
            ->whereHas('appointment', fn ($q) => $q
                ->whereNotNull('checked_out_at')
                ->whereBetween('checked_out_at', [$from->utc(), $to->utc()]))
            ->get();

        return [
            'services' => $items->count(),
            'charged' => round((float) $items->sum('final_price'), 2),
            'commission' => round((float) $items->sum('commission_amount'), 2),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     *
     * Esta lista es la MITAD EN PANTALLA del aviso de fin de servicio. La
     * otra mitad sale por WhatsApp (ver ServiceDoneReminder), y las dos dicen
     * lo mismo a proposito: el WhatsApp la alcanza donde de verdad mira, y
     * esto es lo que ve al abrir el sistema para hacerlo. Un aviso sin un
     * lugar donde resolverlo es un recordatorio de una tarea invisible.
     */
    private function pendingCheckout(Business $business, int $resourceId, CarbonImmutable $now): array
    {
        $tz = $business->businessTimezone();

        return Appointment::query()
            ->whereIn('status', [Appointment::STATUS_PENDING, Appointment::STATUS_CONFIRMED, Appointment::STATUS_IN_PROGRESS])
            ->whereNull('checked_out_at')
            // Ya paso su hora: una cita de mas tarde no esta "pendiente de
            // cobro", simplemente todavia no ocurrio.
            ->where('starts_at', '<=', $now->utc())
            ->whereHas('items', fn ($q) => $q->where('resource_id', $resourceId))
            ->with(['items.service', 'items.photos'])
            ->orderBy('starts_at')
            ->limit(20)
            ->get()
            ->map(function (Appointment $a) use ($business, $tz, $now) {
                // El ultimo item: es el que decide si el TRABAJO termino, no
                // el primero. Mismo criterio que ServiceDoneReminder.
                $item = $a->items->sortByDesc('service_ends_at')->first();
                $termino = $item?->service_ends_at;

                return [
                    'id' => $a->id,
                    'client_name' => $a->client_name,
                    'service_name' => $item?->service?->name,
                    'label' => $a->starts_at?->setTimezone($tz)->format('d/m H:i'),

                    // Cuando quedo listo el trabajo, y si eso ya paso. Es la
                    // diferencia entre "lo estoy haciendo" y "se me quedo sin
                    // registrar", que en pantalla no puede verse igual.
                    'item_id' => $item?->id,
                    'ended_at' => $termino?->setTimezone($tz)->toIso8601String(),
                    'is_done' => $termino !== null && $termino <= $now,

                    /*
                     * Que falta. Se resuelve en el servidor y no en la vista:
                     * la politica del negocio, la bandera del servicio y si ya
                     * hay foto son tres datos que la pantalla no tiene, y
                     * reimplementarlos ahi es como una copia se desincroniza.
                     */
                    'needs_photo' => $item !== null
                        && $business->asksForServicePhoto()
                        && ($item->service?->requires_photo ?? false)
                        && $item->photos->isEmpty(),
                    'has_photo' => $item !== null && $item->photos->isNotEmpty(),
                ];
            })
            ->values()
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function agenda(int $resourceId, CarbonImmutable $now, string $tz): array
    {
        $start = $now->startOfDay();

        return Appointment::query()
            ->whereIn('status', Appointment::activeStatuses())
            ->whereBetween('starts_at', [$start->utc(), $start->addDay()->utc()])
            ->whereHas('items', fn ($q) => $q->where('resource_id', $resourceId))
            ->with(['items.service', 'client'])
            ->orderBy('starts_at')
            ->get()
            ->map(fn (Appointment $a) => [
                'id' => $a->id,
                'time' => $a->starts_at?->setTimezone($tz)->format('H:i'),
                'client_name' => $a->client_name,
                'client_id' => $a->client_id,
                'service_name' => $a->items->first()?->service?->name,
                'status' => $a->status,
                'is_paid' => $a->checked_out_at !== null,
                'total' => $a->total === null ? null : (float) $a->total,
            ])
            ->values()
            ->all();
    }
}
