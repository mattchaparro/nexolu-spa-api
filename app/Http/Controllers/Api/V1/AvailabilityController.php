<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Resource;
use App\Models\Service;
use App\Models\ServicePackage;
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
    /**
     * Donde cabe una visita de VARIOS servicios, uno detras de otro.
     *
     * Encadenar la respuesta de `index()` a mano no sirve: da huecos que estan
     * libres para el primer servicio y no para el tercero. Aca se comprueba
     * que entre la cadena completa, con cada eslabon posiblemente en manos de
     * una persona distinta.
     */
    public function chain(Request $request): JsonResponse
    {
        $data = $request->validate([
            'service_ids' => ['required_without:package_id', 'array', 'min:1'],
            'service_ids.*' => ['integer'],
            'package_id' => ['nullable', 'integer'],
            'date' => ['required', 'date_format:Y-m-d'],
            // "Quiero que todo sea con Aleja". Es preferencia, no filtro: si
            // ella no presta uno de los servicios o no esta libre, la hora se
            // ofrece igual diciendo quien toma ese tramo.
            'resource_id' => ['nullable', 'integer'],
            // Toda la cadena en el mismo local. Sin esto la continuidad podria
            // "resolverse" mandando el manicure a un local y el pedicure al
            // otro -- algo que nadie hace y que `BookingService` rechaza, pero
            // recien despues de que la clienta eligio esa hora.
            'location_id' => ['nullable', 'integer'],
        ]);

        $business = $request->user()->business;
        $tz = $business->businessTimezone();

        $package = isset($data['package_id'])
            ? ServicePackage::with('services')->findOrFail($data['package_id'])
            : null;

        $services = $package
            ? $package->services->all()
            : $this->orderedServices($business->id, $data['service_ids']);

        if ($services === []) {
            return response()->json(['message' => 'Elige al menos un servicio.'], 422);
        }

        $preferred = isset($data['resource_id'])
            ? Resource::where('business_id', $business->id)->findOrFail($data['resource_id'])
            : null;

        $slots = $this->availability->slotsForChain(
            $business,
            $services,
            CarbonImmutable::parse($data['date'], $tz),
            preferredResourceId: $preferred?->id,
            locationId: $data['location_id'] ?? null,
        );

        $quote = $package?->quote();

        return response()->json([
            'date' => $data['date'],
            'timezone' => $tz,
            'services' => array_map(fn (Service $s) => [
                'id' => $s->id,
                'name' => $s->name,
                'duration_min' => (int) $s->duration_min,
                'price' => (float) $s->price,
            ], $services),
            'total_minutes' => array_sum(array_map(
                fn (Service $s) => $s->occupiedMinutesFor(),
                $services,
            )),
            // Solo cuando viene de un combo: una selección suelta de servicios
            // se cobra a precio de lista.
            'package' => $package ? [
                'id' => $package->id,
                'name' => $package->name,
            ] + $quote : null,
            'preferred_resource' => $preferred ? ['id' => $preferred->id, 'name' => $preferred->name] : null,
            'slots' => array_map(fn (array $slot) => [
                'starts_at' => $slot['starts_at']->toIso8601String(),
                'ends_at' => $slot['ends_at']->toIso8601String(),
                'label' => $slot['label'],
                'same_person' => $slot['same_person'],
                'preferred_honored' => $slot['preferred_honored'],
                'legs' => array_map(fn (array $leg) => [
                    'service_id' => $leg['service_id'],
                    'service_name' => $leg['service_name'],
                    'resource_id' => $leg['resource_id'],
                    'resource_name' => $leg['resource_name'],
                    'starts_at' => $leg['starts_at']->toIso8601String(),
                    'label' => $leg['starts_at']->format('H:i'),
                    // `skill` = no presta ese servicio; `busy` = no esta libre.
                    // Son dos conversaciones distintas con el cliente.
                    'changed_reason' => $leg['changed_reason'],
                ], $slot['legs']),
            ], $slots),
        ]);
    }

    /**
     * Los servicios EN EL ORDEN QUE PIDIO quien llama.
     *
     * `whereIn` devuelve en orden de base de datos, no en el del array. Con
     * eso, pedir "pedicure y despues manicure" agendaria al reves.
     *
     * @param  list<int>  $ids
     * @return list<Service>
     */
    private function orderedServices(int $businessId, array $ids): array
    {
        $found = Service::where('business_id', $businessId)
            ->where('is_active', true)
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');

        return collect($ids)
            ->map(fn (int $id) => $found->get($id))
            ->filter()
            ->values()
            ->all();
    }

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'service_id' => ['required', 'integer'],
            'date' => ['required', 'date_format:Y-m-d'],
            'resource_id' => ['nullable', 'integer'],
            /*
             * En que local. Se agenda EN una sede, igual que se cobra en una
             * caja: nadie se hace las manos en Chapinero y los pies en
             * Cedritos, y ofrecer horas del otro local es ofrecer un viaje.
             *
             * Sigue siendo opcional para no romper al negocio de un solo
             * local, que nunca lo manda.
             */
            'location_id' => ['nullable', 'integer'],
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
            locationId: $data['location_id'] ?? null,
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
