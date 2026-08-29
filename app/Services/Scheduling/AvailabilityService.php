<?php

namespace App\Services\Scheduling;

use App\Models\Business;
use App\Models\Resource;
use App\Models\ResourceBreak;
use App\Models\ResourceOccupancy;
use App\Models\ResourceSchedule;
use App\Models\ScheduleException;
use App\Models\Service;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Calcula disponibilidad. No la almacena.
 *
 * Blue Souls materializaba un TimeSlot por bloque de 120 minutos, por empleada,
 * por dia, generado por cron, y al agendar mutaba la fila y corria las
 * siguientes. Eso congela la granularidad, llena la base de filas muertas y no
 * sabe representar un servicio de 20 minutos ni uno de tres horas.
 *
 * Aca la disponibilidad es una funcion de tres cosas:
 *
 *     horario recurrente  -  excepciones  -  ocupacion
 *
 * evaluada sobre el rango que se consulta. Nada que mantener, nada que
 * regenerar, y cambiar la granularidad de un negocio es cambiar un numero.
 */
class AvailabilityService
{
    /**
     * Huecos donde cabe un servicio en una fecha dada.
     *
     * Devuelve, por recurso, los instantes en que el servicio COMPLETO cabe,
     * buffers incluidos. El instante devuelto es el del inicio del servicio
     * visible para el cliente, no el del buffer previo.
     *
     * @return list<array{resource_id:int, resource_name:string, starts_at:CarbonImmutable, ends_at:CarbonImmutable}>
     */
    public function slotsForService(
        Business $business,
        Service $service,
        CarbonImmutable $date,
        ?Resource $onlyResource = null,
        ?CarbonImmutable $now = null,
    ): array {
        $tz = $business->businessTimezone();
        $now ??= CarbonImmutable::now($tz);
        $date = $date->setTimezone($tz)->startOfDay();

        $granularity = (int) $business->schedulingSetting('slot_granularity_min');
        $notice = (int) $business->schedulingSetting('min_booking_notice_min');
        $horizonDays = (int) $business->schedulingSetting('max_booking_horizon_days');

        if ($date > $now->addDays($horizonDays)->startOfDay()) {
            return [];
        }

        $earliest = $now->addMinutes($notice);
        $resources = $this->candidateResources($business, $service, $onlyResource);

        if ($resources->isEmpty()) {
            return [];
        }

        $slots = [];

        foreach ($resources as $resource) {
            $occupiedMinutes = $service->occupiedMinutesFor($resource);
            $duration = $service->durationFor($resource);

            foreach ($this->freeWindowsFor($business, $resource, $date, $tz) as $window) {
                $cursor = $this->ceilToGrid($window->start, $granularity);

                while ($cursor->addMinutes($occupiedMinutes) <= $window->end) {
                    $serviceStart = $cursor->addMinutes($service->buffer_before_min);

                    if ($serviceStart >= $earliest) {
                        $slots[] = [
                            'resource_id' => $resource->id,
                            'resource_name' => $resource->name,
                            'starts_at' => $serviceStart,
                            'ends_at' => $serviceStart->addMinutes($duration),
                        ];
                    }

                    $cursor = $cursor->addMinutes($granularity);
                }
            }
        }

        usort($slots, fn ($a, $b) => $a['starts_at'] <=> $b['starts_at']);

        return $slots;
    }

    /**
     * Ventanas en las que el recurso trabaja ese dia: su horario recurrente,
     * mas las horas extra, menos los bloqueos. NO resta lo ya ocupado.
     *
     * Es lo que necesita la rejilla del calendario, que pinta la franja
     * laboral como fondo y las citas encima. La disponibilidad para reservar
     * es otra cosa -- ver freeWindowsFor.
     *
     * @return list<TimeWindow>
     */
    public function workingWindowsFor(
        Business $business,
        Resource $resource,
        CarbonImmutable $date,
        ?string $tz = null,
    ): array {
        $tz ??= $business->businessTimezone();
        $date = $date->setTimezone($tz)->startOfDay();

        [$working, $cuts] = $this->scheduleAndBlocks($business, $resource, $date, $tz);

        if ($working === []) {
            return [];
        }

        $windows = TimeWindow::subtractAll($working, $cuts);
        usort($windows, fn ($a, $b) => $a->start <=> $b->start);

        return $windows;
    }

    /**
     * Ventanas libres de un recurso en una fecha: su horario del dia, mas las
     * horas extra, menos los bloqueos, menos lo que ya esta ocupado.
     *
     * @return list<TimeWindow>
     */
    public function freeWindowsFor(
        Business $business,
        Resource $resource,
        CarbonImmutable $date,
        ?string $tz = null,
    ): array {
        $tz ??= $business->businessTimezone();
        $date = $date->setTimezone($tz)->startOfDay();
        $dayEnd = $date->addDay();

        [$working, $cuts] = $this->scheduleAndBlocks($business, $resource, $date, $tz);

        if ($working === []) {
            return [];
        }

        foreach ($this->occupiedWindows($resource, $date, $dayEnd, $tz) as $busy) {
            $cuts[] = $busy;
        }

        $free = TimeWindow::subtractAll($working, $cuts);
        usort($free, fn ($a, $b) => $a->start <=> $b->start);

        return $free;
    }

    /**
     * Horario del dia y bloqueos que lo recortan, sin tocar la ocupacion.
     *
     * @return array{0: list<TimeWindow>, 1: list<TimeWindow>}
     */
    private function scheduleAndBlocks(
        Business $business,
        Resource $resource,
        CarbonImmutable $date,
        string $tz,
    ): array {
        $dayEnd = $date->addDay();
        $working = $this->workingWindows($resource, $date, $tz);

        $exceptions = ScheduleException::query()
            ->where('business_id', $business->id)
            ->where(fn ($q) => $q->where('resource_id', $resource->id)->orWhereNull('resource_id'))
            ->where('starts_at', '<', $dayEnd->utc())
            ->where('ends_at', '>', $date->utc())
            ->get();

        // Las horas extra suman antes de que los bloqueos resten: un turno
        // extra puntual tambien puede quedar parcialmente bloqueado.
        foreach ($exceptions->where('kind', ScheduleException::KIND_EXTRA_HOURS) as $extra) {
            $working[] = new TimeWindow(
                CarbonImmutable::parse($extra->starts_at)->setTimezone($tz),
                CarbonImmutable::parse($extra->ends_at)->setTimezone($tz),
            );
        }

        $cuts = [];

        foreach ($exceptions as $exception) {
            if ($exception->kind === ScheduleException::KIND_EXTRA_HOURS) {
                continue;
            }

            $cuts[] = new TimeWindow(
                CarbonImmutable::parse($exception->starts_at)->setTimezone($tz),
                CarbonImmutable::parse($exception->ends_at)->setTimezone($tz),
            );
        }

        /*
         * Los descansos entran como cortes, igual que un bloqueo, y por eso
         * ninguna hora extra los puede reabrir: la resta corre DESPUES de que
         * las horas extra se sumaron al horario. Es la propiedad que se quiere
         * -- el almuerzo no se negocia con una excepcion puntual.
         */
        foreach ($this->breakWindows($business, $resource, $date, $tz) as $rest) {
            $cuts[] = $rest;
        }

        return [$working, $cuts];
    }

    /**
     * Los almuerzos y descansos que aplican a este recurso ese dia.
     *
     * @return list<TimeWindow>
     */
    private function breakWindows(
        Business $business,
        Resource $resource,
        CarbonImmutable $date,
        string $tz,
    ): array {
        return ResourceBreak::query()
            ->where('business_id', $business->id)
            ->applyingTo($resource->id, $date->toDateString(), (int) $date->isoWeekday())
            ->get()
            ->map(fn (ResourceBreak $rest) => new TimeWindow(
                $this->atTime($date, (string) $rest->start_time, $tz),
                $this->atTime($date, (string) $rest->end_time, $tz),
            ))
            ->values()
            ->all();
    }

    /**
     * Si el intervalo pedido cabe entero dentro de la jornada del recurso.
     *
     * Lo usa BookingService antes de escribir. Sin este chequeo, la lista de
     * huecos oculta el almuerzo pero la API acepta cualquier `starts_at`, y
     * arrastrar una cita en el calendario o mandar la hora a mano la mete
     * igual. Que la pantalla no ofrezca algo no es lo mismo que el sistema no
     * lo permita.
     */
    public function windowIsWorkable(
        Business $business,
        Resource $resource,
        TimeWindow $window,
        ?string $tz = null,
    ): bool {
        $tz ??= $business->businessTimezone();
        $start = $window->start->setTimezone($tz);

        [$working, $cuts] = $this->scheduleAndBlocks($business, $resource, $start->startOfDay(), $tz);

        if ($working === []) {
            return false;
        }

        foreach (TimeWindow::subtractAll($working, $cuts) as $open) {
            if ($open->contains($window)) {
                return true;
            }
        }

        return false;
    }

    /**
     * El horario recurrente del recurso, resuelto para una fecha concreta.
     *
     * @return list<TimeWindow>
     */
    private function workingWindows(Resource $resource, CarbonImmutable $date, string $tz): array
    {
        return ResourceSchedule::query()
            ->where('resource_id', $resource->id)
            ->where('weekday', (int) $date->isoWeekday())
            ->whereDate('effective_from', '<=', $date->toDateString())
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date->toDateString()))
            ->get()
            ->map(fn (ResourceSchedule $schedule) => new TimeWindow(
                $this->atTime($date, (string) $schedule->start_time, $tz),
                $this->atTime($date, (string) $schedule->end_time, $tz),
            ))
            ->values()
            ->all();
    }

    /**
     * Lo que ya esta ocupado. Se lee la misma tabla que impone el indice
     * unico, asi que lo que se muestra como libre y lo que la base acepta
     * escribir no pueden divergir.
     *
     * Los limites van convertidos a UTC porque asi se persisten (ver
     * BookingService::windowFor). Bindear un Carbon en hora local aca
     * desplazaria la comparacion las horas de diferencia de la zona, y el
     * sintoma serian huecos ocupados apareciendo como libres.
     *
     * @return list<TimeWindow>
     */
    private function occupiedWindows(Resource $resource, CarbonImmutable $dayStart, CarbonImmutable $dayEnd, string $tz): array
    {
        return ResourceOccupancy::query()
            ->select('appointment_items.starts_at', 'appointment_items.ends_at')
            ->join('appointment_items', 'appointment_items.id', '=', 'resource_occupancy.appointment_item_id')
            ->where('resource_occupancy.resource_id', $resource->id)
            ->where('resource_occupancy.slot_start', '>=', $dayStart->utc())
            ->where('resource_occupancy.slot_start', '<', $dayEnd->utc())
            ->distinct()
            ->get()
            ->map(fn ($row) => new TimeWindow(
                CarbonImmutable::parse($row->starts_at)->setTimezone($tz),
                CarbonImmutable::parse($row->ends_at)->setTimezone($tz),
            ))
            ->values()
            ->all();
    }

    /** @return Collection<int, Resource> */
    private function candidateResources(Business $business, Service $service, ?Resource $onlyResource): Collection
    {
        if ($onlyResource !== null) {
            return collect([$onlyResource])->filter(fn (Resource $r) => $r->is_active)->values();
        }

        return $service->resources()
            ->where('resources.business_id', $business->id)
            ->where('resources.is_active', true)
            ->get();
    }

    private function atTime(CarbonImmutable $date, string $time, string $tz): CarbonImmutable
    {
        [$hour, $minute] = array_pad(explode(':', $time), 2, '0');

        return $date->setTimezone($tz)->setTime((int) $hour, (int) $minute);
    }

    /** Redondea hacia arriba hasta el siguiente punto de la rejilla del dia. */
    private function ceilToGrid(CarbonImmutable $moment, int $granularity): CarbonImmutable
    {
        $dayStart = $moment->startOfDay();
        $minutesIntoDay = (int) $dayStart->diffInMinutes($moment);
        $remainder = $minutesIntoDay % $granularity;

        if ($remainder === 0) {
            return $dayStart->addMinutes($minutesIntoDay);
        }

        return $dayStart->addMinutes($minutesIntoDay - $remainder + $granularity);
    }
}
