<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Models\PaymentMethod;
use App\Models\PlatformPaymentMethod;
use App\Services\PaymentMethodProvisioner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Que medios de pago usa ESTE negocio, elegidos del catalogo global.
 *
 * El negocio elige cuales acepta; no define si algo cuenta como efectivo --
 * eso es propiedad del medio y viene del catalogo. Dejarlo por negocio
 * permitiria marcar el datafono como efectivo y descuadrar todos los cierres.
 */
class BusinessPaymentMethodController
{
    public function __construct(private readonly PaymentMethodProvisioner $provisioner) {}

    public function index(Request $request): JsonResponse
    {
        $business = $request->user()->business;

        $enabled = PaymentMethod::withoutGlobalScope('business')
            ->where('business_id', $business->id)
            ->where('is_active', true)
            ->pluck('platform_payment_method_id')
            ->filter()
            ->all();

        return response()->json(
            PlatformPaymentMethod::where('is_active', true)
                ->orderBy('sort_order')
                ->get()
                ->map(fn (PlatformPaymentMethod $m) => [
                    'id' => $m->id,
                    'label' => $m->label,
                    'counts_as_cash' => $m->counts_as_cash,
                    'enabled' => in_array($m->id, $enabled, true),
                ])
        );
    }

    public function sync(Request $request): JsonResponse
    {
        $data = $request->validate([
            'platform_payment_method_ids' => ['present', 'array'],
            'platform_payment_method_ids.*' => ['integer'],
        ]);

        if ($data['platform_payment_method_ids'] === []) {
            // Sin ningun medio no se puede cobrar nada: la caja queda
            // inoperante y el error solo aparece al intentar el primer cobro.
            return response()->json(['message' => 'Deja al menos un medio de pago habilitado.'], 422);
        }

        $this->provisioner->sync($request->user()->business, $data['platform_payment_method_ids']);

        return $this->index($request);
    }
}
