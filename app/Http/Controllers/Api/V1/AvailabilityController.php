<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Resource;
use App\Models\Service;
use App\Services\Scheduling\AvailabilityService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AvailabilityController
{
    public function __construct(private readonly AvailabilityService $availability) {}

    /**
     * Huecos donde cabe un servicio en una fecha.
     *
     * Las horas salen en la zona del negocio, que es como las va a leer una
     * persona. Internamente todo se persiste y se compara en UTC.
     */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'service_id' => ['required', 'integer'],
            'date' => ['required', 'date_format:Y-m-d'],
            'resource_id' => ['nullable', 'integer'],
        ]);

        $business = $request->user()->business;
        $tz = $business->businessTimezone();

        $service = Service::where('business_id', $business->id)
            ->findOrFail($data['service_id']);

        $resource = isset($data['resource_id'])
            ? Resource::where('business_id', $business->id)->findOrFail($data['resource_id'])
            : null;

        $slots = $this->availability->slotsForService(
            $business,
            $service,
            CarbonImmutable::parse($data['date'], $tz),
            $resource,
        );

        return response()->json([
            'date' => $data['date'],
            'timezone' => $tz,
            'service' => [
                'id' => $service->id,
                'name' => $service->name,
                'duration_min' => (int) $service->duration_min,
            ],
            'slots' => array_map(fn (array $slot) => [
                'resource_id' => $slot['resource_id'],
                'resource_name' => $slot['resource_name'],
                'starts_at' => $slot['starts_at']->setTimezone($tz)->toIso8601String(),
                'ends_at' => $slot['ends_at']->setTimezone($tz)->toIso8601String(),
                'label' => $slot['starts_at']->setTimezone($tz)->format('H:i'),
            ], $slots),
        ]);
    }
}
