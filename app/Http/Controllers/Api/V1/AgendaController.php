<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Appointment;
use App\Models\Business;
use App\Models\Resource;
use App\Models\ResourceBreak;
use App\Services\Scheduling\AvailabilityService;
use App\Services\Scheduling\TimeWindow;
use App\Support\AgendaScope;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Los datos que necesita la rejilla del calendario, en una sola peticion.
 *
 * Es una vista distinta de la disponibilidad: el calendario pinta la franja
 * laboral como fondo y las citas encima, mientras que /availability responde
 * "donde cabe este servicio". Pedirle a la rejilla que arme lo suyo a partir
 * de huecos libres obligaria a reconstruir por resta lo que aca viene directo.
 */
class AgendaController
{
    public function __construct(private readonly AvailabilityService $availability) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            // Hasta 14 dias: mas que eso ya no es una rejilla, es un reporte.
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],

            /*
             * Que sede se esta mirando. Opcional: sin sede la rejilla trae
             * todas, que es lo correcto para un negocio de un solo local y lo
             * que hacia antes de que existieran las sedes.
             *
             * Una rejilla con las columnas de dos locales mezcladas no se
             * puede leer -- son dos jornadas distintas en el mismo ancho -- asi
             * que la pantalla manda sede casi siempre.
             */
            'location_id' => ['nullable', 'integer'],
        ]);

        $business = $request->user()->business;
        $tz = $business->businessTimezone();

        $from = CarbonImmutable::parse($data['from'], $tz)->startOfDay();
        $to = isset($data['to'])
            ? CarbonImmutable::parse($data['to'], $tz)->startOfDay()
            : $from;

        if ($from->diffInDays($to) > 13) {
            return response()->json(['message' => 'El rango no puede superar 14 dias.'], 422);
        }

        // Sin `citas.ver_todas` la rejilla trae una sola columna: la suya.
        $scope = AgendaScope::for($request->user());

        $resources = Resource::where('type', Resource::TYPE_STAFF)
            ->where('is_active', true)
            // El id ajeno no hace falta filtrarlo a mano: el scope de negocio
            // ya deja fuera las sedes de otros, asi que un id prestado
            // devuelve una rejilla vacia, no la de otro local.
            ->when(
                ! empty($data['location_id']),
                fn ($q) => $q->where('location_id', (int) $data['location_id']),
            )
            ->when($scope->resourceId !== null, fn ($q) => $q->whereKey($scope->resourceId))
            ->when($scope->seesNothing(), fn ($q) => $q->whereRaw('1 = 0'))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        $appointments = $this->appointmentsBetween($from, $to->addDay());

        $days = [];

        for ($date = $from; $date <= $to; $date = $date->addDay()) {
            $days[] = [
                'date' => $date->toDateString(),
                'weekday' => $date->isoWeekday(),
                'resources' => $resources->map(fn (Resource $resource) => [
                    'id' => $resource->id,
                    'name' => $resource->name,
                    'color' => $resource->color,
                    // Franja laboral: horario recurrente + horas extra − bloqueos.
                    'windows' => array_map(
                        fn (TimeWindow $w) => [
                            'start' => $w->start->setTimezone($tz)->format('H:i'),
                            'end' => $w->end->setTimezone($tz)->format('H:i'),
                        ],
                        $this->availability->workingWindowsFor($business, $resource, $date, $tz),
                    ),
                    // Los descansos viajan aparte de las ventanas para que la
                    // rejilla pueda decir "Almuerzo" en vez de dejar un hueco
                    // gris que se lee igual que "ya salio".
                    'breaks' => $this->breaksFor($business, $resource, $date),
                    'appointments' => $this->appointmentsFor($appointments, $resource->id, $date, $tz),
                ])->values(),
            ];
        }

        return response()->json([
            'timezone' => $tz,
            // Envoltura horaria del rango: donde arranca y termina el eje de
            // la rejilla. Se calcula del horario real, no de un 00:00-23:59
            // fijo que dejaria dos tercios de la pantalla vacios.
            'day_start' => $this->edge($days, 'start', '09:00', fn ($a, $b) => $a < $b),
            'day_end' => $this->edge($days, 'end', '18:00', fn ($a, $b) => $a > $b),
            'days' => $days,
        ]);
    }

    /**
     * Los almuerzos y descansos de un recurso ese dia, ya recortados a lo que
     * de verdad se ve en la rejilla.
     *
     * @return list<array{start:string, end:string, label:string}>
     */
    private function breaksFor(Business $business, Resource $resource, CarbonImmutable $date): array
    {
        return ResourceBreak::query()
            ->where('business_id', $business->id)
            ->applyingTo($resource->id, $date->toDateString(), (int) $date->isoWeekday())
            ->orderBy('start_time')
            ->get()
            ->map(fn (ResourceBreak $b) => [
                'start' => substr((string) $b->start_time, 0, 5),
                'end' => substr((string) $b->end_time, 0, 5),
                'label' => $b->label,
            ])
            ->values()
            ->all();
    }

    /** @return Collection<int, Appointment> */
    private function appointmentsBetween(CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return Appointment::query()
            ->with(['items.service', 'items.resource'])
            ->whereIn('status', Appointment::activeStatuses())
            ->where('starts_at', '>=', $from->utc())
            ->where('starts_at', '<', $to->utc())
            ->orderBy('starts_at')
            ->get();
    }

    /**
     * @param  Collection<int, Appointment>  $appointments
     * @return list<array<string, mixed>>
     */
    private function appointmentsFor(Collection $appointments, int $resourceId, CarbonImmutable $date, string $tz): array
    {
        $result = [];

        foreach ($appointments as $appointment) {
            foreach ($appointment->items as $item) {
                if ($item->resource_id !== $resourceId) {
                    continue;
                }

                $start = CarbonImmutable::parse($item->service_starts_at)->setTimezone($tz);

                if (! $start->isSameDay($date)) {
                    continue;
                }

                $end = CarbonImmutable::parse($item->service_ends_at)->setTimezone($tz);

                $result[] = [
                    'id' => $appointment->id,
                    'item_id' => $item->id,
                    'client_name' => $appointment->client_name,
                    'service_name' => $item->service?->name,
                    'service_id' => $item->service_id,
                    'status' => $appointment->status,
                    'is_paid' => $appointment->checked_out_at !== null,
                    'start' => $start->format('H:i'),
                    'end' => $end->format('H:i'),
                    'starts_at' => $start->toIso8601String(),
                    // La ventana ocupada incluye buffers y puede ser mayor que
                    // la visible: la rejilla la usa para no dejar caer otra
                    // cita encima al arrastrar.
                    'occupied_start' => CarbonImmutable::parse($item->starts_at)->setTimezone($tz)->format('H:i'),
                    'occupied_end' => CarbonImmutable::parse($item->ends_at)->setTimezone($tz)->format('H:i'),
                ];
            }
        }

        return $result;
    }

    /** Hora limite del rango, con un default si nadie trabaja ese dia. */
    private function edge(array $days, string $key, string $fallback, callable $better): string
    {
        $edge = null;

        foreach ($days as $day) {
            foreach ($day['resources'] as $resource) {
                foreach ($resource['windows'] as $window) {
                    if ($edge === null || $better($window[$key], $edge)) {
                        $edge = $window[$key];
                    }
                }
            }
        }

        return $edge ?? $fallback;
    }
}
