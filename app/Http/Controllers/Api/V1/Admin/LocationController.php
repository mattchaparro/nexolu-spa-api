<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Models\Location;
use App\Support\BusinessPlanLimits;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Las sedes del negocio.
 *
 * Dos reglas que se defienden aca y no en el front, porque son las que
 * protegen los datos:
 *
 * 1. Una sede se APAGA, no se borra. Lo que se atendio ahi -- citas, cierres
 *    de caja, comisiones ya pagadas -- no puede desaparecer porque el local
 *    haya cerrado. Es la misma regla de campanas y programas de sellos.
 *
 * 2. Siempre queda una sede principal activa. Sin ella, cualquier cosa que
 *    nazca sin sede explicita se queda sin donde caer.
 */
class LocationController
{
    public function index(Request $request): JsonResponse
    {
        $business = $request->user()->business;
        $usage = $business->planUsage()[BusinessPlanLimits::MAX_LOCATIONS] ?? null;

        return response()->json([
            'locations' => $business->locations()->withCount([
                'resources as active_resources_count' => fn ($q) => $q->where('is_active', true),
            ])->get()->map(fn (Location $l) => $this->present($l)),
            'limit' => $usage,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $business = $request->user()->business;

        /*
         * El tope se valida al CREAR y con un mensaje que dice el numero. Un
         * 422 que solo diga "limite alcanzado" obliga a adivinar cuantas
         * caben; decirlo evita la llamada a soporte.
         */
        if (! $business->canAddWithinLimit(BusinessPlanLimits::MAX_LOCATIONS)) {
            $limit = $business->limitFor(BusinessPlanLimits::MAX_LOCATIONS);

            return response()->json([
                'message' => "Tu plan permite {$limit} sede(s) activa(s). "
                    .'Apaga una que ya no uses o cambia de plan para abrir otra.',
            ], 422);
        }

        $data = $this->validated($request);

        $location = Location::create($data + [
            'business_id' => $business->id,
            'slug' => $this->uniqueSlug($business->id, $data['name'], null),
            // La primera sede de un negocio es la principal por definicion.
            'is_primary' => $business->locations()->count() === 0,
            'is_active' => true,
        ]);

        return response()->json(['location' => $this->present($location)], 201);
    }

    public function update(Request $request, Location $location): JsonResponse
    {
        $data = $this->validated($request);

        $location->update($data + [
            'slug' => $this->uniqueSlug($location->business_id, $data['name'], $location->id),
        ]);

        return response()->json(['location' => $this->present($location->fresh())]);
    }

    /** Apagar una sede. Nunca la principal, y nunca la ultima activa. */
    public function disable(Request $request, Location $location): JsonResponse
    {
        if ($location->is_primary) {
            return response()->json([
                'message' => 'Esta es la sede principal. Marca otra como principal antes de apagarla.',
            ], 422);
        }

        /*
         * Apagar una sede con gente adentro dejaria a esas personas sin donde
         * atender y sus citas futuras sin local. Se avisa en vez de arrastrar
         * el problema: mover al equipo es una decision de quien administra.
         */
        $conGente = $location->resources()->where('is_active', true)->count();

        if ($conGente > 0) {
            return response()->json([
                'message' => "Todavía hay {$conGente} persona(s) activa(s) en esta sede. "
                    .'Muévelas a otra sede o desactívalas antes de apagarla.',
            ], 422);
        }

        $location->update(['is_active' => false]);

        return response()->json(['location' => $this->present($location->fresh())]);
    }

    /** Cambiar cual es la principal. Es exclusiva: la anterior deja de serlo. */
    public function makePrimary(Request $request, Location $location): JsonResponse
    {
        if (! $location->is_active) {
            return response()->json([
                'message' => 'Una sede apagada no puede ser la principal.',
            ], 422);
        }

        Location::where('business_id', $location->business_id)
            ->where('id', '!=', $location->id)
            ->update(['is_primary' => false]);

        $location->update(['is_primary' => true]);

        return response()->json(['location' => $this->present($location->fresh())]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'address' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'city' => ['nullable', 'string', 'max:120'],
            'maps_url' => ['nullable', 'url', 'max:500'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:999'],
        ]);
    }

    /**
     * El slug de la pagina publica de la sede.
     *
     * Se recalcula al renombrar. Es aceptable porque el enlace de una sede se
     * comparte por WhatsApp y se vuelve a mandar cada vez; lo que no puede
     * cambiar es el `id`, que es lo que llevan las citas.
     */
    private function uniqueSlug(int $businessId, string $name, ?int $ignoreId): string
    {
        $base = Str::slug($name) ?: 'sede';
        $slug = $base;
        $n = 2;

        while (Location::withoutGlobalScopes()
            ->where('business_id', $businessId)
            ->where('slug', $slug)
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->exists()
        ) {
            $slug = "{$base}-{$n}";
            $n++;
        }

        return $slug;
    }

    /** @return array<string, mixed> */
    private function present(Location $l): array
    {
        return [
            'id' => $l->id,
            'name' => $l->name,
            'slug' => $l->slug,
            'address' => $l->address,
            'phone' => $l->phone,
            'city' => $l->city,
            'maps_url' => $l->maps_url,
            'is_primary' => $l->is_primary,
            'is_active' => $l->is_active,
            'sort_order' => $l->sort_order,
            'active_resources_count' => $l->active_resources_count ?? null,
        ];
    }
}
