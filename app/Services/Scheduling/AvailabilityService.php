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
     * Donde cabe una SECUENCIA de servicios, uno detras de otro.
     *
     * Es lo que hace falta para agendar una visita de varias cosas -- manicure
     * y despues pedicure -- y para vender un combo. `slotsForService` no sirve:
     * responde por un servicio suelto, y encadenar sus respuestas a mano deja
     * huecos que se ven libres para el primero y no lo estan para el tercero.
     *
     * Cada eslabon puede caer en una persona distinta: lo que se comprueba es
     * que TODA la cadena entre, no que cada parte por separado tenga hueco.
     *
     * Los servicios corren pegados. Un descanso entre uno y otro seria tiempo
     * que el negocio no puede vender y que el cliente pasa esperando; si hace
     * falta separacion, se expresa con los buffers del servicio, que es donde
     * el resto del sistema ya la busca.
     *
     * @param  list<Service>  $services  En el orden en que se prestan.
     * @return list<array{
     *     starts_at: CarbonImmutable,
     *     ends_at: CarbonImmutable,
     *     label: string,
     *     legs: list<array{service_id:int, resource_id:int, resource_name:string, starts_at:CarbonImmutable}>
     * }>
     */
    public function slotsForChain(
        Business $business,
        array $services,
        CarbonImmutable $date,
        ?CarbonImmutable $now = null,
        ?int $preferredResourceId = null,
    ): array {
        if ($services === []) {
            return [];
        }

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

        /*
         * Las ventanas libres de cada recurso se calculan UNA vez y se van
         * recortando en memoria a medida que la cadena las ocupa. Sin eso, un
         * combo de tres servicios haria tres consultas de disponibilidad por
         * cada hora candidata del dia.
         */
        $freeByResource = [];
        // Quien puede prestar cada eslabon, resuelto una sola vez. Consultarlo
        // dentro del bucle seria una consulta por servicio por cada hora
        // candidata del dia: con granularidad de 15 minutos y un combo de
        // tres, mas de doscientas.
        $candidatesByService = [];

        foreach ($services as $index => $service) {
            $candidates = $this->candidateResources($business, $service, null);

            if ($candidates->isEmpty()) {
                // Un eslabon que nadie presta hace imposible la cadena entera.
                return [];
            }

            $candidatesByService[$index] = $candidates->all();

            foreach ($candidates as $resource) {
                $freeByResource[$resource->id] ??= [
                    'resource' => $resource,
                    'windows' => $this->freeWindowsFor($business, $resource, $date, $tz),
                ];
            }
        }

        $dayStart = $date;
        $dayEnd = $date->addDay();
        $slots = [];

        for ($cursor = $this->ceilToGrid($dayStart, $granularity); $cursor < $dayEnd; $cursor = $cursor->addMinutes($granularity)) {
            if ($cursor < $earliest) {
                continue;
            }

            $legs = $this->fitChain($services, $cursor, $freeByResource, $candidatesByService, $preferredResourceId);

            if ($legs === null) {
                continue;
            }

            $last = $legs[array_key_last($legs)];
            // `array_values` porque `array_unique` conserva las claves, y la
            // comparacion estricta de abajo fallaria por indices, no por
            // personas.
            $people = array_values(array_unique(array_map(fn (array $leg) => $leg['resource']->id, $legs)));

            $slots[] = [
                'starts_at' => $cursor,
                'ends_at' => $last['ends_at'],
                'label' => $cursor->format('g:i a'),
                // Lo que de verdad quiere saber quien reserva: si es todo con
                // la misma persona o lo van a pasar de silla en silla.
                'same_person' => count($people) === 1,
                'preferred_honored' => $preferredResourceId === null
                    ? null
                    : $people === [$preferredResourceId],
                'legs' => array_map(fn (array $leg) => [
                    'service_id' => $leg['service']->id,
                    'service_name' => $leg['service']->name,
                    'resource_id' => $leg['resource']->id,
                    'resource_name' => $leg['resource']->name,
                    'starts_at' => $leg['starts_at'],
                    'changed_reason' => $leg['changed_reason'] ?? null,
                ], $legs),
            ];
        }

        return $slots;
    }

    /**
     * Intenta acomodar la cadena a partir de una hora concreta.
     *
     * Devuelve null en cuanto un eslabon no entra: no tiene sentido seguir
     * probando los siguientes si el segundo servicio ya no cabe.
     *
     * Estrategia por eslabon: la PRIMERA persona libre en el orden del
     * negocio. No es optimizacion global -- podria haber un reparto mejor que
     * dejara la agenda menos fragmentada -- pero es predecible, y una
     * asignacion que cambia de persona segun un calculo que nadie ve es peor
     * que una que a veces reparte de forma menos elegante.
     *
     * @param  list<Service>  $services
     * @param  array<int, array{resource: Resource, windows: list<TimeWindow>}>  $freeByResource
     * @return list<array{service: Service, resource: Resource, starts_at: CarbonImmutable, ends_at: CarbonImmutable}>|null
     */
    private function fitChain(
        array $services,
        CarbonImmutable $start,
        array $freeByResource,
        array $candidatesByService,
        ?int $preferredResourceId = null,
    ): ?array {
        /*
         * Se intenta en orden de lo que el cliente prefiere, no de lo que es
         * facil de calcular:
         *
         *   1. TODO con la persona que se pidio.
         *   2. TODO con una sola persona, la que sea.
         *   3. Repartido, pero pegandose lo mas posible a la anterior.
         *
         * Sin este orden, "manos, pies y cejas" se repartia entre tres
         * personas aunque una sola pudiera hacer las tres y estuviera libre.
         * Nadie va al spa a que lo pasen de silla en silla.
         */
        if ($preferredResourceId !== null) {
            $legs = $this->fitChainWith($services, $start, $freeByResource, $candidatesByService, $preferredResourceId);

            if ($legs !== null) {
                return $legs;
            }
        }

        /*
         * Ojo con el `if`: si se pidio una persona y no pudo con todo, NO se
         * busca otra que si pueda. Cambiarle a la clienta la persona que pidio
         * por una tercera, para que la visita quede "prolija", es exactamente
         * lo que no quiere: prefiere quedarse con quien pidio en los servicios
         * que si pueda y ceder solo el resto.
         */
        if ($preferredResourceId === null) {
            foreach ($this->resourcesAbleToDoAll($candidatesByService, $services) as $resourceId) {
                $legs = $this->fitChainWith($services, $start, $freeByResource, $candidatesByService, $resourceId);

                if ($legs !== null) {
                    return $legs;
                }
            }
        }

        return $this->fitChainGreedy($services, $start, $freeByResource, $candidatesByService, $preferredResourceId);
    }

    /**
     * Intenta la cadena ENTERA con una sola persona.
     *
     * @param  list<Service>  $services
     * @return list<array<string, mixed>>|null
     */
    private function fitChainWith(
        array $services,
        CarbonImmutable $start,
        array $freeByResource,
        array $candidatesByService,
        int $resourceId,
    ): ?array {
        if (! isset($freeByResource[$resourceId])) {
            return null;
        }

        $resource = $freeByResource[$resourceId]['resource'];
        $legs = [];
        $cursor = $start;
        $taken = [];

        foreach ($services as $index => $service) {
            // No basta con que este libre: tiene que prestar ESE servicio.
            if (! $this->canDo($candidatesByService[$index], $resourceId)) {
                return null;
            }

            $before = (int) $service->buffer_before_min;
            $occupied = $service->occupiedMinutesFor($resource);

            /*
             * El alistamiento del siguiente servicio NO puede solaparse con lo
             * que esta misma persona todavia esta haciendo.
             *
             * La primera parte empieza a la hora del hueco y su alistamiento
             * queda antes. De ahi en adelante la ventana arranca donde termino
             * la anterior, y lo que se le dice al cliente es la hora en que de
             * verdad lo sientan: `windowStart + alistamiento`.
             *
             * Sin esto, la ventana de un servicio con `buffer_before` pisaba la
             * del anterior y la cadena completa con UNA sola persona era
             * imposible siempre: se repartia entre dos aunque una estuviera
             * libre todo el dia.
             */
            $windowStart = $index === 0 ? $cursor->subMinutes($before) : $cursor;
            $visible = $windowStart->addMinutes($before);
            $window = new TimeWindow($windowStart, $windowStart->addMinutes($occupied));

            if (! $this->windowFits($window, $freeByResource[$resourceId]['windows'], $taken)) {
                return null;
            }

            $taken[] = $window;

            $legs[] = [
                'service' => $service,
                'resource' => $resource,
                'starts_at' => $visible,
                'ends_at' => $visible->addMinutes($service->durationFor($resource)),
            ];

            $cursor = $windowStart->addMinutes($occupied);
        }

        return $legs;
    }

    /**
     * Reparte la cadena, quedandose con la misma persona mientras se pueda.
     *
     * Es el ultimo recurso, y el que resuelve el caso real: manos y pies con
     * Aleja, y las cejas con quien este libre despues -- porque Aleja no las
     * hace, o porque a esa hora ya tiene otra cita.
     *
     * @param  list<Service>  $services
     * @return list<array<string, mixed>>|null
     */
    private function fitChainGreedy(
        array $services,
        CarbonImmutable $start,
        array $freeByResource,
        array $candidatesByService,
        ?int $preferredResourceId,
    ): ?array {
        $legs = [];
        $cursor = $start;
        // Copia local: lo que la cadena va ocupando no puede ensuciar el
        // calculo de la siguiente hora candidata.
        $taken = [];
        // Con quien viene el cliente del eslabon anterior.
        $previousId = null;

        foreach ($services as $index => $service) {
            $placed = null;
            /*
             * A quien se le ofrece primero este eslabon.
             *
             * Si se pidio una persona, ella sigue siendo el ancla aunque un
             * eslabon se haya tenido que ceder: pedir "todo con Aleja" y que
             * ceder las cejas arrastre tambien el ultimo servicio a quien las
             * hizo seria perder la preferencia por el camino. Sin persona
             * pedida, el ancla es con quien viene el cliente sentado.
             */
            $anchorId = $preferredResourceId ?? $previousId;

            foreach ($this->orderedCandidates($candidatesByService[$index], $anchorId) as $resource) {
                $before = (int) $service->buffer_before_min;
                $occupied = $service->occupiedMinutesFor($resource);

                /*
                 * Si sigue la MISMA persona, su alistamiento va despues de que
                 * termine con lo anterior: no puede estar alistando y
                 * atendiendo a la vez. Si cambia de persona, la que entra puede
                 * ir alistando su puesto mientras la otra termina.
                 */
                $mismaPersona = $index > 0 && $resource->id === $previousId;
                $windowStart = $mismaPersona ? $cursor : $cursor->subMinutes($before);
                $visible = $windowStart->addMinutes($before);
                $window = new TimeWindow($windowStart, $windowStart->addMinutes($occupied));

                if (! $this->windowFits($window, $freeByResource[$resource->id]['windows'], $taken[$resource->id] ?? [])) {
                    continue;
                }

                $placed = [
                    'service' => $service,
                    'resource' => $resource,
                    'starts_at' => $visible,
                    'ends_at' => $visible->addMinutes($service->durationFor($resource)),
                    // Por que cambio de persona, para poder decirlo en
                    // pantalla y por WhatsApp. "Aleja no hace cejas" y "Aleja
                    // esta ocupada" llevan a respuestas distintas del cliente.
                    'changed_reason' => $anchorId === null || $resource->id === $anchorId
                        ? null
                        : ($this->canDo($candidatesByService[$index], $anchorId) ? 'busy' : 'skill'),
                ];

                $taken[$resource->id][] = $window;
                // El siguiente arranca cuando este libera al recurso, buffer
                // de limpieza incluido: si no, el segundo servicio empieza
                // mientras todavia se esta desinfectando el puesto.
                $cursor = $windowStart->addMinutes($occupied);
                $previousId = $resource->id;

                break;
            }

            if ($placed === null) {
                return null;
            }

            $legs[] = $placed;
        }

        return $legs;
    }

    /**
     * Quienes pueden prestar TODOS los servicios de la cadena, en orden del
     * negocio.
     *
     * @param  list<Service>  $services
     * @return list<int>
     */
    private function resourcesAbleToDoAll(array $candidatesByService, array $services): array
    {
        $ids = null;

        foreach (array_keys($services) as $index) {
            $delServicio = array_map(fn (Resource $r) => $r->id, $candidatesByService[$index]);

            $ids = $ids === null
                ? $delServicio
                : array_values(array_intersect($ids, $delServicio));

            if ($ids === []) {
                return [];
            }
        }

        return $ids ?? [];
    }

    /**
     * Los candidatos con la persona anterior de primera.
     *
     * @param  list<Resource>  $candidates
     * @return list<Resource>
     */
    private function orderedCandidates(array $candidates, ?int $previousId): array
    {
        if ($previousId === null) {
            return $candidates;
        }

        $primero = [];
        $resto = [];

        foreach ($candidates as $resource) {
            if ($resource->id === $previousId) {
                $primero[] = $resource;
            } else {
                $resto[] = $resource;
            }
        }

        return [...$primero, ...$resto];
    }

    /** @param list<Resource> $candidates */
    private function canDo(array $candidates, int $resourceId): bool
    {
        foreach ($candidates as $resource) {
            if ($resource->id === $resourceId) {
                return true;
            }
        }

        return false;
    }

    /**
     * Si la ventana cabe entera en alguna franja libre y no pisa lo que la
     * propia cadena ya ocupo.
     *
     * @param  list<TimeWindow>  $free
     * @param  list<TimeWindow>  $taken
     */
    private function windowFits(TimeWindow $window, array $free, array $taken): bool
    {
        foreach ($taken as $busy) {
            if ($window->overlaps($busy)) {
                return false;
            }
        }

        foreach ($free as $open) {
            if ($open->contains($window)) {
                return true;
            }
        }

        return false;
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
