<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Models\Service;
use App\Models\ServicePackage;
use App\Support\ImageStorage;
use App\Support\Money\PackagePricing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Combos: varios servicios que se venden juntos, normalmente con descuento.
 */
class ServicePackageController
{
    public function index(): JsonResponse
    {
        $packages = ServicePackage::with('services')
            ->orderBy('sort_order')->orderBy('name')
            ->get()
            ->map(fn (ServicePackage $p) => $this->present($p));

        return response()->json([
            'packages' => $packages,
            'discount_types' => array_map(fn (string $t) => [
                'value' => $t,
                'label' => PackagePricing::label($t),
            ], PackagePricing::types()),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $business = $request->user()->business;

        $package = DB::transaction(function () use ($data, $business, $request) {
            $package = ServicePackage::create([
                'business_id' => $business->id,
                'name' => $data['name'],
                'slug' => $this->uniqueSlug($business->id, $data['name']),
                'description' => $data['description'] ?? null,
                'discount_type' => $data['discount_type'],
                'discount_value' => $data['discount_value'] ?? null,
                'is_active' => $data['is_active'] ?? true,
                'is_bookable_online' => $data['is_bookable_online'] ?? true,
                'sort_order' => $data['sort_order'] ?? 0,
            ]);

            if ($request->hasFile('image')) {
                $package->update([
                    'image_path' => ImageStorage::store($request->file('image'), $business->id, 'combos'),
                ]);
            }

            $this->syncServices($package, $data['service_ids']);

            return $package;
        });

        return response()->json($this->present($package->fresh('services')), 201);
    }

    public function update(Request $request, ServicePackage $package): JsonResponse
    {
        $data = $this->validated($request);
        $business = $request->user()->business;

        DB::transaction(function () use ($package, $data, $business, $request) {
            $package->update([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'discount_type' => $data['discount_type'],
                'discount_value' => $data['discount_value'] ?? null,
                'is_active' => $data['is_active'] ?? $package->is_active,
                'is_bookable_online' => $data['is_bookable_online'] ?? $package->is_bookable_online,
                'sort_order' => $data['sort_order'] ?? $package->sort_order,
            ]);

            if ($request->hasFile('image')) {
                $anterior = $package->image_path;
                $package->update([
                    'image_path' => ImageStorage::store($request->file('image'), $business->id, 'combos'),
                ]);
                ImageStorage::delete($anterior);
            }

            $this->syncServices($package, $data['service_ids']);
        });

        return response()->json($this->present($package->fresh('services')));
    }

    /**
     * Se desactiva, no se borra.
     *
     * Las citas vendidas con este combo lo referencian, y borrarlo dejaria un
     * cobro sin explicacion de por que se rebajo. Desactivarlo lo saca del
     * catalogo y de la pagina publica sin tocar el historial.
     */
    public function destroy(ServicePackage $package): JsonResponse
    {
        $package->update(['is_active' => false, 'is_bookable_online' => false]);

        return response()->json(['message' => 'Combo desactivado.']);
    }

    /*
    |--------------------------------------------------------------------------
    | Internos
    |--------------------------------------------------------------------------
    */

    /** @param list<int> $serviceIds */
    private function syncServices(ServicePackage $package, array $serviceIds): void
    {
        // Con el scope del negocio puesto: un id ajeno simplemente no aparece
        // y no se cuela en el combo.
        $validos = Service::whereIn('id', $serviceIds)->pluck('id')->all();

        // El orden que llego, no el de la base: es la secuencia con la que se
        // va a agendar. Primero el manicure, despues el pedicure.
        $pivot = [];

        foreach ($serviceIds as $order => $id) {
            if (in_array($id, $validos, true)) {
                $pivot[$id] = ['sort_order' => $order];
            }
        }

        $package->services()->sync($pivot);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:500'],
            // Al menos dos: un "combo" de un solo servicio es un servicio con
            // otro precio, y eso ya se puede hacer editando el servicio.
            'service_ids' => ['required', 'array', 'min:2'],
            'service_ids.*' => ['integer'],
            'discount_type' => ['required', Rule::in(PackagePricing::types())],
            'discount_value' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
            'is_bookable_online' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'image' => ImageStorage::rules(),
        ]);
    }

    private function uniqueSlug(int $businessId, string $name): string
    {
        $base = Str::slug($name) ?: 'combo';
        $slug = $base;
        $i = 2;

        while (ServicePackage::withoutGlobalScope('business')
            ->where('business_id', $businessId)->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }

    /** @return array<string, mixed> */
    private function present(ServicePackage $package): array
    {
        return [
            'id' => $package->id,
            'name' => $package->name,
            'slug' => $package->slug,
            'description' => $package->description,
            'image_url' => ImageStorage::url($package->image_path),
            'discount_type' => $package->discount_type,
            'discount_value' => $package->discount_value === null ? null : (float) $package->discount_value,
            'is_active' => (bool) $package->is_active,
            'is_bookable_online' => (bool) $package->is_bookable_online,
            'sort_order' => $package->sort_order,
            'total_minutes' => $package->totalMinutes(),
            'services' => $package->services->map(fn (Service $s) => [
                'id' => $s->id,
                'name' => $s->name,
                'price' => (float) $s->price,
                'duration_min' => (int) $s->duration_min,
            ])->values(),
        ] + $package->quote();
    }
}
