<?php

namespace App\Http\Controllers\Api\V1\SuperAdmin;

use App\Models\PlatformPaymentMethod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * El catalogo global de medios de pago.
 *
 * Un medio nunca se borra: se desactiva. Borrarlo dejaria sin nombre a los
 * cobros historicos y con ellos a los cierres de meses anteriores.
 */
class PaymentMethodCatalogController
{
    public function index(): JsonResponse
    {
        return response()->json(
            PlatformPaymentMethod::orderBy('sort_order')->orderBy('label')->get()
                ->map(fn (PlatformPaymentMethod $m) => [
                    'id' => $m->id,
                    'key' => $m->key,
                    'label' => $m->label,
                    'counts_as_cash' => $m->counts_as_cash,
                    'is_active' => $m->is_active,
                    'businesses' => $m->businessMethods()->where('is_active', true)->count(),
                ])
        );
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'label' => ['required', 'string', 'max:100'],
            'counts_as_cash' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        $method = PlatformPaymentMethod::create($data + [
            'key' => $this->uniqueKey($data['label']),
        ]);

        return response()->json($method, 201);
    }

    public function update(Request $request, PlatformPaymentMethod $method): JsonResponse
    {
        $data = $request->validate([
            'label' => ['sometimes', 'string', 'max:100'],
            // counts_as_cash se puede corregir: si estaba mal, la correccion
            // debe llegar a todos los negocios en su proximo sync.
            'counts_as_cash' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ]);

        $method->update($data);

        return response()->json($method->fresh());
    }

    private function uniqueKey(string $label): string
    {
        $base = Str::slug($label, '_') ?: 'medio';
        $key = $base;
        $i = 2;

        while (PlatformPaymentMethod::where('key', $key)->exists()) {
            $key = "{$base}_{$i}";
            $i++;
        }

        return $key;
    }
}
