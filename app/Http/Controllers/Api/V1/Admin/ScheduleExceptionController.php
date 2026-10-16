<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Models\Appointment;
use App\Models\Resource;
use App\Models\ScheduleException;
use App\Support\LocationScope;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Cambios de un día en el horario de una persona.
 *
 * El horario semanal dice cómo trabaja normalmente; esto es lo puntual:
 * «hoy se queda hasta las 9», «el viernes no trabaja después del mediodía»,
 * «mañana trabaja todo el día aunque no le toque». Antes el motor de
 * disponibilidad ya sabía sumar y restar estas horas (ScheduleException), pero
 * no había cómo crearlas: el único camino era cambiar el horario semanal de
 * toda la vida por un día.
 *
 * Dos clases, que son las únicas que hacen falta:
 * - `extra_hours`: SUMA disponibilidad (trabaja de tal a tal hora ese día).
 * - `block`: RESTA (permiso, no trabaja de tal a tal hora, o todo el día).
 *   Varios días seguidos son `vacation`.
 */
class ScheduleExceptionController
{
    /** Lo que aparece en la pantalla; el resto de clases (festivo) no se toca desde aquí. */
    private const KINDS = [
        ScheduleException::KIND_EXTRA_HOURS,
        ScheduleException::KIND_BLOCK,
        ScheduleException::KIND_VACATION,
    ];

    /** Los cambios que todavía no terminan, en orden. */
    public function index(Request $request, Resource $resource): JsonResponse
    {
        $this->assertVisible($request, $resource);
        $tz = $this->tz($request);

        $rows = ScheduleException::query()
            ->where('resource_id', $resource->id)
            ->whereIn('kind', self::KINDS)
            ->where('ends_at', '>', now()->utc())
            ->orderBy('starts_at')
            ->get();

        return response()->json($rows->map(fn (ScheduleException $e) => $this->present($e, $resource, $tz)));
    }

    public function store(Request $request, Resource $resource): JsonResponse
    {
        $this->assertVisible($request, $resource);
        abort_unless($resource->type === Resource::TYPE_STAFF, 422, 'Solo las personas del equipo tienen horario.');

        $tz = $this->tz($request);
        $today = CarbonImmutable::now($tz)->toDateString();

        $data = $request->validate([
            'kind' => ['required', 'in:'.ScheduleException::KIND_EXTRA_HOURS.','.ScheduleException::KIND_BLOCK],
            'date' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.$today],
            // Varios días seguidos (vacaciones, una incapacidad): solo «todo el día».
            'end_date' => ['nullable', 'date_format:Y-m-d', 'after:date'],
            'all_day' => ['nullable', 'boolean'],
            'start_time' => ['nullable', 'date_format:H:i'],
            'end_time' => ['nullable', 'date_format:H:i'],
            'reason' => ['nullable', 'string', 'max:120'],
        ]);

        $allDay = (bool) ($data['all_day'] ?? false);
        $isBlock = $data['kind'] === ScheduleException::KIND_BLOCK;

        abort_if(! $isBlock && $allDay, 422, 'Para sumar horas indica de qué hora a qué hora trabaja.');
        abort_if(! empty($data['end_date']) && ! ($isBlock && $allDay), 422, 'Varios días seguidos solo se pueden quitar enteros.');

        if ($allDay) {
            $start = CarbonImmutable::parse($data['date'], $tz)->startOfDay();
            $end = CarbonImmutable::parse($data['end_date'] ?? $data['date'], $tz)->addDay()->startOfDay();
        } else {
            abort_if(empty($data['start_time']) || empty($data['end_time']), 422, 'Indica la hora de inicio y la de fin.');
            abort_if($data['end_time'] <= $data['start_time'], 422, 'La hora de fin tiene que ser después de la de inicio.');

            $start = CarbonImmutable::parse($data['date'].' '.$data['start_time'], $tz);
            $end = CarbonImmutable::parse($data['date'].' '.$data['end_time'], $tz);
        }

        $exception = ScheduleException::create([
            'business_id' => $request->user()->business->id,
            'resource_id' => $resource->id,
            'kind' => $isBlock
                ? (! empty($data['end_date']) ? ScheduleException::KIND_VACATION : ScheduleException::KIND_BLOCK)
                : ScheduleException::KIND_EXTRA_HOURS,
            'starts_at' => $start->utc(),
            'ends_at' => $end->utc(),
            'reason' => isset($data['reason']) ? trim($data['reason']) ?: null : null,
        ]);

        return response()->json($this->present($exception, $resource, $tz), 201);
    }

    public function destroy(Request $request, Resource $resource, ScheduleException $exception): JsonResponse
    {
        $this->assertVisible($request, $resource);
        abort_unless($exception->resource_id === $resource->id, 404);

        $exception->delete();

        return response()->json(null, 204);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(ScheduleException $e, Resource $resource, string $tz): array
    {
        $start = CarbonImmutable::parse($e->starts_at)->setTimezone($tz);
        $end = CarbonImmutable::parse($e->ends_at)->setTimezone($tz);
        $allDay = $start->equalTo($start->startOfDay()) && $end->equalTo($end->startOfDay()) && $end > $start;
        $isBlock = $e->kind !== ScheduleException::KIND_EXTRA_HOURS;

        return [
            'id' => $e->id,
            'kind' => $e->kind,
            'date' => $start->toDateString(),
            // El último día que cubre (el fin es el inicio del día siguiente).
            'end_date' => $allDay ? $end->subDay()->toDateString() : $start->toDateString(),
            'all_day' => $allDay,
            'start_time' => $start->format('H:i'),
            'end_time' => $end->format('H:i'),
            'reason' => $e->reason,
            // Citas que quedaron dentro de lo que se quitó: la agenda no las
            // mueve sola, hay que reagendarlas o avisarle a la clienta.
            'conflicts' => $isBlock ? $this->conflicts($resource, $e, $tz) : [],
        ];
    }

    /**
     * @return list<array{appointment_id: int, date: string, time: string, client_name: ?string, service_name: ?string}>
     */
    private function conflicts(Resource $resource, ScheduleException $e, string $tz): array
    {
        return $resource->appointmentItems()
            ->where('starts_at', '<', $e->ends_at)
            ->where('ends_at', '>', $e->starts_at)
            ->whereHas('appointment', fn ($q) => $q->whereNotIn('status', [
                Appointment::STATUS_CANCELLED, Appointment::STATUS_NO_SHOW, Appointment::STATUS_COMPLETED,
            ]))
            ->with(['appointment', 'service'])
            ->orderBy('starts_at')
            ->limit(20)
            ->get()
            ->map(fn ($item) => [
                'appointment_id' => $item->appointment_id,
                'date' => $item->starts_at?->setTimezone($tz)->toDateString(),
                'time' => $item->starts_at?->setTimezone($tz)->format('H:i'),
                'client_name' => $item->appointment?->client_name,
                'service_name' => $item->service?->name,
            ])
            ->values()
            ->all();
    }

    private function tz(Request $request): string
    {
        return $request->user()->business->businessTimezone();
    }

    private function assertVisible(Request $request, Resource $resource): void
    {
        abort_unless(
            LocationScope::for($request->user())->allows($resource->location_id),
            404,
        );
    }
}
