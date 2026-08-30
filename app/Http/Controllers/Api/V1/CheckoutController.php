<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\AppointmentResource;
use App\Models\Appointment;
use App\Models\LoyaltyReward;
use App\Models\PaymentMethod;
use App\Services\Loyalty\LoyaltyService;
use App\Services\Scheduling\CheckoutService;
use App\Support\Money\LoyaltyCalculator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CheckoutController
{
    public function __construct(
        private readonly CheckoutService $checkout,
        private readonly LoyaltyService $loyalty,
    ) {}

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

    /**
     * El premio que se quiere usar, validado contra ESTE cliente.
     *
     * Se busca por el negocio y por el cliente de la cita, no solo por id: sin
     * eso, mandar un id ajeno canjearia el premio de otra persona.
     */
    private function rewardFor(Request $request, Appointment $appointment, array $data): ?LoyaltyReward
    {
        if (empty($data['loyalty_reward_id']) || $appointment->client_id === null) {
            return null;
        }

        return LoyaltyReward::where('business_id', $request->user()->business_id)
            ->where('client_id', $appointment->client_id)
            ->where('status', LoyaltyReward::STATUS_AVAILABLE)
            ->with('rewardService')
            ->findOrFail($data['loyalty_reward_id']);
    }

    /**
     * Suma el premio al descuento que ya venia.
     *
     * Aca SI se suma, al reves que el descuento a mano sobre el del combo, y
     * la diferencia no es caprichosa: alla el riesgo era descontar de mas sin
     * que nadie lo decidiera. Un premio, en cambio, alguien lo decidio dos
     * veces -- la clienta se lo gano visita a visita y quien cobra eligio
     * aplicarlo. Negarselo por haber reservado un combo seria cambiarle las
     * reglas en el mostrador.
     *
     * @return array{0: float, 1: ?string}
     */
    private function withReward(
        Appointment $appointment,
        ?LoyaltyReward $reward,
        float $discount,
        ?string $reason,
        array $data,
    ): array {
        if ($reward === null) {
            return [$discount, $reason];
        }

        $subtotal = (float) $appointment->items()->sum('price');
        $premio = $reward->reward_type === LoyaltyCalculator::REWARD_FREE_SERVICE
            // El servicio gratis vale lo que valga ESA linea en ESTA cita: se
            // descuenta la mas cara que coincida, no un precio de catalogo que
            // pudo cambiar.
            ? (float) $appointment->items()
                ->where('service_id', $reward->reward_service_id)
                ->max('price')
            : $reward->discountFor(max(0, $subtotal - $discount));

        // Tope duro contra el subtotal: el descuento nunca puede dejar al
        // negocio devolviendo plata, y `CheckoutService` lo rechazaria.
        $total = min($subtotal, round($discount + $premio, 2));
        $texto = trim(($reason ? $reason.' + ' : '').$reward->label());

        return [$total, $texto ?: null];
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
            // Un premio de la tarjeta de sellos que la clienta quiere usar hoy.
            'loyalty_reward_id' => ['nullable', 'integer'],
        ]);

        $method = PaymentMethod::where('business_id', $request->user()->business_id)
            ->findOrFail($data['payment_method_id']);

        $reward = $this->rewardFor($request, $appointment, $data);

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
        [$discount, $reason] = $this->withReward($appointment, $reward, $discount, $reason, $data);

        try {
            $cobrada = $this->checkout->checkout(
                $appointment,
                $method,
                $request->user(),
                $discount,
                $reason,
                $data['item_prices'] ?? [],
            );

            // Se marca DESPUES de cobrar: si el cobro falla, el premio tiene
            // que seguir disponible para el siguiente intento.
            if ($reward !== null) {
                $this->loyalty->markUsed($reward, $cobrada);
            }
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
