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

    /**
     * Que descuento se aplica: el que se escribio, o el del combo.
     *
     * @param  array<string, mixed>  $data
     * @return array{0: float, 1: ?string}
     */
    private function discountFor(Appointment $appointment, array $data): array
    {
        if (array_key_exists('discount_amount', $data) && $data['discount_amount'] !== null) {
            return [(float) $data['discount_amount'], $data['discount_reason'] ?? null];
        }

        $package = $appointment->servicePackage;

        if ($package === null) {
            return [0.0, $data['discount_reason'] ?? null];
        }

        /*
         * Se recalcula contra los precios de HOY y no se guarda un monto
         * congelado al agendar. Es lo correcto para un combo de precio
         * cerrado: si el manicure subio entre que se agendo y que se cobro, el
         * combo sigue valiendo lo que dice el cartel, y quien absorbe la
         * diferencia es el negocio -- no el cliente, que reservo por un precio.
         */
        $quote = $package->loadMissing('services')->quote();

        return [$quote['discount'], "Combo {$package->name}"];
    }

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

        /*
         * El descuento del combo se aplica solo, salvo que quien cobra ponga
         * otro a mano.
         *
         * No se suman: un combo con 60.000 de rebaja al que ademas se le
         * escriben 20.000 terminaria descontando 80.000 sin que nadie lo haya
         * decidido. Lo que se escriba MANDA sobre lo que el combo trae, y la
         * pantalla lo muestra prellenado para que se vea que se esta pisando.
         */
        [$discount, $reason] = $this->discountFor($appointment, $data);

        try {
            $cobrada = $this->checkout->checkout(
                $appointment,
                $method,
                $request->user(),
                $discount,
                $reason,
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
