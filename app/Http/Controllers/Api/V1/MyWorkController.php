<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Appointment;
use App\Models\AppointmentItem;
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
            'pending_checkout' => $this->pendingCheckout($resource->id, $now),
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

    /** @return list<array<string, mixed>> */
    private function pendingCheckout(int $resourceId, CarbonImmutable $now): array
    {
        return Appointment::query()
            ->whereIn('status', [Appointment::STATUS_PENDING, Appointment::STATUS_CONFIRMED, Appointment::STATUS_IN_PROGRESS])
            ->whereNull('checked_out_at')
            // Ya paso su hora: una cita de mas tarde no esta "pendiente de
            // cobro", simplemente todavia no ocurrio.
            ->where('starts_at', '<=', $now->utc())
            ->whereHas('items', fn ($q) => $q->where('resource_id', $resourceId))
            ->with('items.service')
            ->orderBy('starts_at')
            ->limit(20)
            ->get()
            ->map(fn (Appointment $a) => [
                'id' => $a->id,
                'client_name' => $a->client_name,
                'service_name' => $a->items->first()?->service?->name,
                'label' => $a->starts_at?->setTimezone($a->business->businessTimezone())->format('d/m H:i'),
            ])
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
