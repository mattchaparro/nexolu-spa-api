<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Models\Service;
use App\Models\ServiceCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Categorias de servicios, y la comision de cada familia.
 *
 * Existen sobre todo por la comision: con 20 servicios de manicure y 8 de
 * pestañas, cambiar el porcentaje de una familia era editar 28 fichas y
 * acordarse de todas. La categoria es el ultimo escalon de la cascada (ver
 * CommissionResolver), asi que el servicio que no define el suyo hereda el de
 * su familia.
 */
class ServiceCategoryController
{
    public function index(): JsonResponse
    {
        $categories = ServiceCategory::withCount('services')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(fn (ServiceCategory $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'commission_rate' => $c->commission_rate === null ? null : (float) $c->commission_rate,
                'services_count' => $c->services_count,
                'is_active' => (bool) $c->is_active,
                'sort_order' => $c->sort_order,
            ]);

        return response()->json($categories);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);

        $category = ServiceCategory::create([
            'business_id' => $request->user()->business->id,
            'name' => $data['name'],
            'commission_rate' => $this->rate($data),
            'sort_order' => $data['sort_order'] ?? 0,
            'is_active' => true,
        ]);

        return response()->json(['id' => $category->id], 201);
    }

    public function update(Request $request, ServiceCategory $category): JsonResponse
    {
        $data = $this->validated($request);

        $category->update([
            'name' => $data['name'],
            'commission_rate' => $this->rate($data),
            'sort_order' => $data['sort_order'] ?? $category->sort_order,
            'is_active' => $data['is_active'] ?? $category->is_active,
        ]);

        return response()->json(['id' => $category->id]);
    }

    /**
     * Borra la categoria; los servicios que colgaban de ella quedan sin
     * categoria, no se borran.
     *
     * Y con eso pierden el porcentaje que heredaban, que es exactamente el
     * tipo de cambio silencioso que descuadra una liquidacion. Por eso se
     * avisa cuantos servicios quedan afectados antes de dejar borrar.
     */
    public function destroy(Request $request, ServiceCategory $category): JsonResponse
    {
        $afectados = Service::where('service_category_id', $category->id)->count();

        if ($afectados > 0 && ! $request->boolean('confirm')) {
            return response()->json([
                'message' => "{$afectados} servicio(s) heredan la comisión de esta categoría y se "
                    .'quedarían sin ella. Confirma si quieres borrarla igual.',
                'requires_confirmation' => true,
                'affected_services' => $afectados,
            ], 409);
        }

        $category->delete();

        return response()->json(['message' => 'Categoría eliminada.']);
    }

    /**
     * Cambia el porcentaje de varios servicios de una vez.
     *
     * Es lo que evita entrar a 20 fichas. `commission_rate` nulo los deja
     * heredando de su categoria -- que es distinto de ponerles 0, y por eso el
     * campo acepta null explicitamente.
     */
    public function bulkCommission(Request $request): JsonResponse
    {
        $data = $request->validate([
            'service_ids' => ['required', 'array', 'min:1'],
            'service_ids.*' => ['integer'],
            'commission_rate' => ['present', 'nullable', 'numeric', 'min:0', 'max:1'],
            // Opcional: mover los mismos servicios a una categoria de paso.
            'service_category_id' => ['nullable', 'integer'],
        ]);

        if (! empty($data['service_category_id'])) {
            // Dentro del scope del negocio: mandar el id de otra categoria no
            // puede colar servicios propios en la familia de otro spa.
            ServiceCategory::findOrFail($data['service_category_id']);
        }

        $cambios = ['commission_rate' => $data['commission_rate']];

        if (array_key_exists('service_category_id', $data)) {
            $cambios['service_category_id'] = $data['service_category_id'];
        }

        // Con el scope del negocio puesto: los ids ajenos simplemente no
        // coinciden y no se tocan.
        $afectados = DB::transaction(
            fn () => Service::whereIn('id', $data['service_ids'])->update($cambios),
        );

        return response()->json([
            'updated' => $afectados,
            'message' => $afectados === 1
                ? 'Se actualizó 1 servicio.'
                : "Se actualizaron {$afectados} servicios.",
        ]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:80'],
            // Nulo = sin porcentaje de familia; los servicios de esta
            // categoria caen a "sin comision" si tampoco tienen el suyo.
            'commission_rate' => ['present', 'nullable', 'numeric', 'min:0', 'max:1'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ]);
    }

    private function rate(array $data): ?float
    {
        return $data['commission_rate'] === null ? null : (float) $data['commission_rate'];
    }
}
