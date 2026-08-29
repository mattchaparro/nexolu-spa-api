<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\AppointmentResource;
use App\Models\Appointment;
use App\Models\PaymentMethod;
use App\Services\Scheduling\CheckoutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CheckoutController
{
    public function __construct(private readonly CheckoutService $checkout) {}

    public function paymentMethods(Request $request): JsonResponse
    {
        $methods = PaymentMethod::where('is_active', true)
            ->orderBy('sort_order')
            ->get(['id', 'name', 'counts_as_cash']);

        return response()->json($methods);
    }

    public function store(Request $request, Appointment $appointment): JsonResponse
    {
        $data = $request->validate([
            'payment_method_id' => ['required', 'integer'],
            'discount_amount' => ['nullable', 'numeric', 'min:0'],
            'discount_reason' => ['nullable', 'string', 'max:255'],
            'item_prices' => ['nullable', 'array'],
            'item_prices.*' => ['numeric', 'min:0'],
        ]);

        $method = PaymentMethod::where('business_id', $request->user()->business_id)
            ->findOrFail($data['payment_method_id']);

        try {
            $cobrada = $this->checkout->checkout(
                $appointment,
                $method,
                $request->user(),
                (float) ($data['discount_amount'] ?? 0),
                $data['discount_reason'] ?? null,
                $data['item_prices'] ?? [],
            );
        } catch (\DomainException $e) {
            // 422 y no 500: cobrar dos veces o pasarse con el descuento son
            // errores del operador, no fallas del sistema.
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(new AppointmentResource($cobrada));
    }

    public function destroy(Request $request, Appointment $appointment): JsonResponse
    {
        try {
            $revertida = $this->checkout->undo($appointment, $request->user());
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(new AppointmentResource($revertida));
    }
}
