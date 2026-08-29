<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Models\Resource;
use App\Models\ResourceBreak;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Almuerzos y descansos.
 *
 * Se administran aparte del horario porque son una regla distinta: el horario
 * dice cuando trabaja, el descanso dice cuando no se atiende dentro de ese
 * horario. Y a diferencia de un bloqueo puntual, nadie puede pasarles por
 * encima -- ni con horas extra ni agendando a mano.
 */
class BreakController
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['resource_id' => ['nullable', 'integer']]);

        $rows = ResourceBreak::with('resource')
            ->when(
                isset($data['resource_id']),
                // Los del negocio aplican a todas, asi que al filtrar por una
                // profesional tienen que seguir apareciendo: si no, la
                // pantalla dice que Maria no descansa y el motor la bloquea
                // igual.
                fn ($q) => $q->where(fn ($w) => $w
                    ->where('resource_id', $data['resource_id'])
                    ->orWhereNull('resource_id')),
            )
            ->orderByRaw('resource_id IS NULL DESC')
            ->orderBy('weekday')
            ->orderBy('start_time')
            ->get()
            ->map(fn (ResourceBreak $b) => [
                'id' => $b->id,
                'resource_id' => $b->resource_id,
                'resource_name' => $b->resource?->name,
                'scope' => $b->resource_id === null ? 'business' : 'resource',
                'weekday' => $b->weekday,
                'start_time' => substr((string) $b->start_time, 0, 5),
                'end_time' => substr((string) $b->end_time, 0, 5),
                'label' => $b->label,
                'effective_from' => $b->effective_from?->toDateString(),
                'effective_to' => $b->effective_to?->toDateString(),
                'is_active' => (bool) $b->is_active,
            ]);

        return response()->json($rows);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);

        $break = ResourceBreak::create($this->attributes($request, $data));

        return response()->json(['id' => $break->id], 201);
    }

    public function update(Request $request, ResourceBreak $break): JsonResponse
    {
        $data = $this->validated($request);

        $break->update($this->attributes($request, $data));

        return response()->json(['id' => $break->id]);
    }

    /**
     * Se borra de verdad, no se archiva.
     *
     * Un descanso no es un movimiento de plata: no hay nada historico que
     * explicar. Lo que si se conserva es la cita que se agendo cuando el
     * descanso no existia -- borrarlo no la toca.
     */
    public function destroy(ResourceBreak $break): JsonResponse
    {
        $break->delete();

        return response()->json(['message' => 'Descanso eliminado.']);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            // Nulo = todo el negocio.
            'resource_id' => ['nullable', 'integer'],
            // Nulo = todos los dias.
            'weekday' => ['nullable', 'integer', 'between:1,7'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'after:start_time'],
            'label' => ['nullable', 'string', 'max:60'],
            'effective_from' => ['nullable', 'date_format:Y-m-d'],
            'effective_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:effective_from'],
            'is_active' => ['nullable', 'boolean'],
        ]);
    }

    /** @return array<string, mixed> */
    private function attributes(Request $request, array $data): array
    {
        $business = $request->user()->business;

        // El recurso se resuelve DENTRO del scope del negocio: mandar el id de
        // otra profesional de otro spa tiene que dar 404.
        $resourceId = isset($data['resource_id'])
            ? Resource::findOrFail($data['resource_id'])->id
            : null;

        return [
            'business_id' => $business->id,
            'resource_id' => $resourceId,
            'weekday' => $data['weekday'] ?? null,
            'start_time' => $data['start_time'].':00',
            'end_time' => $data['end_time'].':00',
            'label' => $data['label'] ?? 'Almuerzo',
            'effective_from' => $data['effective_from'] ?? now($business->businessTimezone())->toDateString(),
            'effective_to' => $data['effective_to'] ?? null,
            'is_active' => $data['is_active'] ?? true,
        ];
    }
}
