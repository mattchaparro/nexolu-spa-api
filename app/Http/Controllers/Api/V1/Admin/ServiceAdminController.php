<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Resources\ServiceResource;
use App\Models\Service;
use App\Support\ImageStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ServiceAdminController
{
    public function store(Request $request): JsonResponse
    {
        $business = $request->user()->business;
        $data = $this->validated($request, $business->id);

        $service = Service::create($data + [
            'business_id' => $business->id,
            'slug' => $this->uniqueSlug($business->id, $data['name']),
        ]);

        if ($request->hasFile('image')) {
            $service->update([
                'image_path' => ImageStorage::store($request->file('image'), $business->id, 'servicios'),
            ]);
        }

        $this->syncResources($service, $request);

        return response()->json(new ServiceResource($service->load('resources', 'category')), 201);
    }

    public function update(Request $request, Service $service): JsonResponse
    {
        $business = $request->user()->business;
        $data = $this->validated($request, $business->id, $service->id);

        $service->update($data);

        if ($request->hasFile('image')) {
            // Se borra la anterior recien cuando la nueva ya esta guardada: si
            // la subida falla, el servicio conserva la imagen que tenia.
            $anterior = $service->image_path;
            $service->update([
                'image_path' => ImageStorage::store($request->file('image'), $business->id, 'servicios'),
            ]);
            ImageStorage::delete($anterior);
        } elseif ($request->boolean('remove_image')) {
            ImageStorage::delete($service->image_path);
            $service->update(['image_path' => null]);
        }

        $this->syncResources($service, $request);

        return response()->json(new ServiceResource($service->fresh(['resources', 'category'])));
    }

    /**
     * Desactiva un servicio en vez de borrarlo.
     *
     * Borrarlo dejaria sin nombre a las citas historicas que lo referencian, y
     * con ellas los reportes de meses anteriores. Un servicio que ya no se
     * presta se apaga; deja de ofrecerse y conserva su pasado.
     */
    public function destroy(Service $service): JsonResponse
    {
        $service->update(['is_active' => false]);

        return response()->json(['message' => 'Servicio desactivado.']);
    }

    private function validated(Request $request, int $businessId, ?int $ignoreId = null): array
    {
        return $request->validate([
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('services', 'name')
                    ->where('business_id', $businessId)
                    ->ignore($ignoreId)
                    ->whereNull('deleted_at'),
            ],
            'description' => ['nullable', 'string', 'max:2000'],
            'service_category_id' => ['nullable', 'integer'],
            'duration_min' => ['required', 'integer', 'min:5', 'max:600'],
            'buffer_before_min' => ['nullable', 'integer', 'min:0', 'max:120'],
            'buffer_after_min' => ['nullable', 'integer', 'min:0', 'max:120'],
            'price' => ['required', 'numeric', 'min:0'],
            // Se guarda como fraccion (0.30), pero el formulario pide 30.
            'commission_rate' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'is_bookable_online' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'image' => ImageStorage::rules(),
        ]);
    }

    /**
     * Actualiza quien presta el servicio, con la duracion y el porcentaje
     * propios de cada profesional.
     *
     * Solo se toca si el request trae `resources`: editar el precio de un
     * servicio no debe borrar sin querer sus asignaciones.
     */
    private function syncResources(Service $service, Request $request): void
    {
        if (! $request->has('resources')) {
            return;
        }

        $request->validate([
            'resources' => ['array'],
            'resources.*.resource_id' => ['required', 'integer'],
            'resources.*.duration_override_min' => ['nullable', 'integer', 'min:5', 'max:600'],
            'resources.*.commission_rate_override' => ['nullable', 'numeric', 'min:0', 'max:1'],
        ]);

        $validos = $service->business->resources()->pluck('id');

        $sync = collect($request->input('resources', []))
            // Un recurso de otro negocio no puede colarse aunque venga en el
            // payload: el scope de tenancy no cubre una tabla pivote.
            ->filter(fn (array $row) => $validos->contains($row['resource_id']))
            ->mapWithKeys(fn (array $row) => [
                $row['resource_id'] => [
                    'duration_override_min' => $row['duration_override_min'] ?? null,
                    'commission_rate_override' => $row['commission_rate_override'] ?? null,
                ],
            ])
            ->all();

        $service->resources()->sync($sync);
    }

    private function uniqueSlug(int $businessId, string $name): string
    {
        $base = Str::slug($name);
        $slug = $base;
        $i = 2;

        while (Service::withTrashed()->where('business_id', $businessId)->where('slug', $slug)->exists()) {
            $slug = "{$base}-{$i}";
            $i++;
        }

        return $slug;
    }
}
