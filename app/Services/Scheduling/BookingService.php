<?php

namespace App\Services\Scheduling;

use App\Models\Appointment;
use App\Models\AppointmentItem;
use App\Models\Business;
use App\Models\Client;
use App\Models\Resource;
use App\Models\ResourceOccupancy;
use App\Models\Service;
use App\Services\Scheduling\Exceptions\SlotUnavailableException;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Crea, reagenda y cancela citas.
 *
 * La garantia contra doble reserva no vive aca: vive en el indice unico
 * (resource_id, slot_start) de resource_occupancy. Este servicio solo se
 * encarga de escribir esas filas DENTRO de la misma transaccion que la cita, y
 * de traducir la violacion de constraint a un error de dominio.
 *
 * Es una diferencia importante. Un chequeo previo tipo "esta libre?" siempre
 * deja una ventana entre la lectura y la escritura; con reserva online abierta
 * y un agente de WhatsApp agendando en paralelo, esa ventana se materializa.
 * Aca la base rechaza la segunda escritura y no hay carrera que ganar.
 */
class BookingService
{
    public function __construct(private readonly AvailabilityService $availability) {}

    /**
     * @param  list<array{service_id:int, resource_id:int, starts_at:string|CarbonImmutable}>  $items
     */
    public function book(
        Business $business,
        array $items,
        ?Client $client,
        ?string $clientName = null,
        ?string $clientPhone = null,
        string $source = Appointment::SOURCE_ADMIN,
        ?string $notes = null,
    ): Appointment {
        if ($items === []) {
            throw new \InvalidArgumentException('Una cita necesita al menos un servicio.');
        }

        $tz = $business->businessTimezone();
        $granularity = (int) $business->schedulingSetting('slot_granularity_min');

        return DB::transaction(function () use ($business, $items, $client, $clientName, $clientPhone, $source, $notes, $tz, $granularity) {
            $resolved = $this->resolveItems($business, $items, $tz);

            $appointment = Appointment::create([
                'business_id' => $business->id,
                'client_id' => $client?->id,
                'client_name' => $clientName ?? $client?->fullName(),
                'client_phone' => $clientPhone ?? $client?->phone,
                'starts_at' => min(array_column($resolved, 'service_starts_at')),
                'ends_at' => max(array_column($resolved, 'service_ends_at')),
                'status' => Appointment::STATUS_PENDING,
                'source' => $source,
                'notes' => $notes,
            ]);

            foreach ($resolved as $index => $row) {
                $item = AppointmentItem::create([
                    'business_id' => $business->id,
                    'appointment_id' => $appointment->id,
                    'service_id' => $row['service']->id,
                    'resource_id' => $row['resource']->id,
                    'starts_at' => $row['starts_at'],
                    'ends_at' => $row['ends_at'],
                    'service_starts_at' => $row['service_starts_at'],
                    'service_ends_at' => $row['service_ends_at'],
                    'price' => $row['service']->price,
                    'commission_rate' => $row['service']->commission_rate,
                    'sort_order' => $index,
                ]);

                $this->claimOccupancy($business, $item, $granularity);
            }

            return $appointment->load('items');
        });
    }

    public function reschedule(Appointment $appointment, CarbonImmutable $newStart, ?Resource $newResource = null): Appointment
    {
        $business = $appointment->business;
        $tz = $business->businessTimezone();
        $granularity = (int) $business->schedulingSetting('slot_granularity_min');

        return DB::transaction(function () use ($appointment, $newStart, $newResource, $business, $tz, $granularity) {
            $items = $appointment->items()->with(['service', 'resource'])->get();

            if ($items->count() !== 1) {
                // Reagendar una cita encadenada exige recalcular toda la
                // secuencia. Se resuelve cancelando y volviendo a reservar,
                // no moviendo piezas sueltas.
                throw new \DomainException('Solo se puede reagendar directamente una cita de un solo servicio.');
            }

            $item = $items->first();
            $resource = $newResource ?? $item->resource;
            $service = $item->service;

            // Liberar antes de reclamar: si el hueco nuevo se solapa con el
            // viejo (mover 30 minutos), el indice unico rechazaria una fila
            // que este mismo item ya posee.
            ResourceOccupancy::where('appointment_item_id', $item->id)->delete();

            $start = $newStart->setTimezone($tz);
            $window = $this->windowFor($service, $resource, $start);

            $item->update([
                'resource_id' => $resource->id,
                'starts_at' => $window['starts_at'],
                'ends_at' => $window['ends_at'],
                'service_starts_at' => $window['service_starts_at'],
                'service_ends_at' => $window['service_ends_at'],
            ]);

            $this->claimOccupancy($business, $item->fresh(), $granularity);

            $appointment->update([
                'starts_at' => $window['service_starts_at'],
                'ends_at' => $window['service_ends_at'],
            ]);

            return $appointment->fresh('items');
        });
    }

    public function cancel(Appointment $appointment, ?int $byUserId = null, ?string $reason = null): Appointment
    {
        return DB::transaction(function () use ($appointment, $byUserId, $reason) {
            ResourceOccupancy::whereIn('appointment_item_id', $appointment->items()->pluck('id'))->delete();

            $appointment->update([
                'status' => Appointment::STATUS_CANCELLED,
                'cancelled_at' => now(),
                'cancelled_by_user_id' => $byUserId,
                'cancellation_reason' => $reason,
            ]);

            return $appointment;
        });
    }

    /**
     * Escribe una fila por unidad de granularidad que el item ocupa. Si otra
     * transaccion ya reclamo cualquiera de esas unidades, el indice unico
     * revienta y la cita entera se deshace.
     */
    private function claimOccupancy(Business $business, AppointmentItem $item, int $granularity): void
    {
        $rows = [];
        $cursor = CarbonImmutable::parse($item->starts_at);
        $end = CarbonImmutable::parse($item->ends_at);

        while ($cursor < $end) {
            $rows[] = [
                'business_id' => $business->id,
                'resource_id' => $item->resource_id,
                'appointment_item_id' => $item->id,
                'slot_start' => $cursor,
            ];

            $cursor = $cursor->addMinutes($granularity);
        }

        try {
            ResourceOccupancy::insert($rows);
        } catch (QueryException $e) {
            if ($this->isUniqueViolation($e)) {
                throw new SlotUnavailableException(
                    'Ese horario acaba de ser tomado. Elige otro.',
                    previous: $e,
                );
            }

            throw $e;
        }
    }

    /**
     * @param  list<array{service_id:int, resource_id:int, starts_at:string|CarbonImmutable}>  $items
     * @return list<array{service:Service, resource:Resource, starts_at:CarbonImmutable, ends_at:CarbonImmutable, service_starts_at:CarbonImmutable, service_ends_at:CarbonImmutable}>
     */
    private function resolveItems(Business $business, array $items, string $tz): array
    {
        $resolved = [];

        foreach ($items as $raw) {
            $service = Service::where('business_id', $business->id)->findOrFail($raw['service_id']);
            $resource = Resource::where('business_id', $business->id)->findOrFail($raw['resource_id']);

            if (! $resource->is_active) {
                throw new \DomainException("El recurso {$resource->name} no esta activo.");
            }

            $start = $raw['starts_at'] instanceof CarbonImmutable
                ? $raw['starts_at']->setTimezone($tz)
                : CarbonImmutable::parse($raw['starts_at'], $tz);

            $resolved[] = array_merge(
                ['service' => $service, 'resource' => $resource],
                $this->windowFor($service, $resource, $start),
            );
        }

        return $resolved;
    }

    /**
     * Ventanas de un item, SIEMPRE en UTC.
     *
     * Laravel formatea un Carbon al bindearlo usando la zona que el propio
     * objeto lleva, sin convertirla. Si un negocio persistiera en su hora
     * local y otro en UTC, las comparaciones entre ambos serian silenciosamente
     * incorrectas -- y el sintoma (huecos ocupados que aparecen libres) no
     * apunta a la causa en absoluto. La regla es una sola: se persiste en UTC,
     * y la zona del negocio se usa solo para interpretar la entrada y para
     * presentar la salida.
     *
     * @return array{starts_at:CarbonImmutable, ends_at:CarbonImmutable, service_starts_at:CarbonImmutable, service_ends_at:CarbonImmutable}
     */
    private function windowFor(Service $service, Resource $resource, CarbonImmutable $serviceStart): array
    {
        $duration = $service->durationFor($resource);

        return [
            // La ventana ocupada incluye los buffers; la del servicio, no.
            'starts_at' => $serviceStart->subMinutes($service->buffer_before_min)->utc(),
            'ends_at' => $serviceStart->addMinutes($duration + $service->buffer_after_min)->utc(),
            'service_starts_at' => $serviceStart->utc(),
            'service_ends_at' => $serviceStart->addMinutes($duration)->utc(),
        ];
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        // 23000 en MySQL, 23505 en Postgres. Se cubren los dos por si el
        // motor cambia: el codigo de dominio no deberia enterarse.
        return in_array($e->getCode(), ['23000', '23505'], true);
    }
}
