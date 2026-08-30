<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Models\LoyaltyProgram;
use App\Models\Service;
use App\Services\Loyalty\LoyaltyService;
use App\Support\Money\LoyaltyCalculator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * La tarjeta de sellos del negocio.
 *
 * UN programa activo por negocio. Dos a la vez obligarian a decidir cual gana
 * el sello de una visita, y esa pregunta no tiene una respuesta que el
 * mostrador pueda explicar en voz alta.
 */
class LoyaltyProgramController
{
    public function __construct(private readonly LoyaltyService $loyalty) {}

    public function show(Request $request): JsonResponse
    {
        $business = $request->user()->business;
        $program = $this->loyalty->activeProgram($business);

        return response()->json([
            'program' => $program === null ? null : $this->detail($program),
            // El catalogo de tipos vive en el backend para que la pantalla no
            // lo duplique: agregar un tipo nuevo no deberia obligar a tocar
            // los dos repos.
            'reward_types' => [
                ['value' => LoyaltyCalculator::REWARD_DISCOUNT_PERCENT, 'label' => 'Un porcentaje de descuento'],
                ['value' => LoyaltyCalculator::REWARD_DISCOUNT_AMOUNT, 'label' => 'Un monto fijo de descuento'],
                ['value' => LoyaltyCalculator::REWARD_FREE_SERVICE, 'label' => 'Un servicio gratis'],
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $business = $request->user()->business;

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'terms' => ['nullable', 'string', 'max:1000'],
            /*
             * Minimo 2 sellos. Una tarjeta de 1 regala en cada visita, y una
             * de 0 lo haria para siempre: no es fidelizacion, es una rebaja
             * permanente que nadie decidio.
             */
            'stamps_required' => ['required', 'integer', 'min:2', 'max:100'],
            'reward_type' => ['required', Rule::in(LoyaltyCalculator::rewardTypes())],
            'reward_value' => ['nullable', 'numeric', 'min:0'],
            'reward_service_id' => ['nullable', 'integer'],
            'min_ticket' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        if ($error = $this->rewardIsUsable($business->id, $data)) {
            return response()->json(['message' => $error], 422);
        }

        $program = $this->loyalty->activeProgram($business)
            ?? LoyaltyProgram::firstOrNew([
                'business_id' => $business->id,
                'name' => $data['name'],
            ]);

        $program->fill($data + ['business_id' => $business->id]);
        $program->is_active = $data['is_active'] ?? true;
        $program->save();

        return response()->json(['program' => $this->detail($program->fresh('rewardService'))]);
    }

    /** Apaga el programa sin borrar la historia de sellos ya ganados. */
    public function destroy(Request $request): JsonResponse
    {
        $program = $this->loyalty->activeProgram($request->user()->business);

        if ($program === null) {
            return response()->json(['message' => 'No hay un programa activo.'], 422);
        }

        /*
         * Se DESACTIVA, no se borra. Los sellos y premios ya ganados quedan:
         * borrar el programa se llevaria por delante la tarjeta de gente que
         * ya hizo las visitas, y eso se descubre en el mostrador.
         */
        $program->update(['is_active' => false]);

        return response()->json(['program' => null]);
    }

    /**
     * Que el premio se pueda entregar de verdad.
     *
     * @return string|null El error, o null si esta bien.
     */
    private function rewardIsUsable(int $businessId, array $data): ?string
    {
        if ($data['reward_type'] === LoyaltyCalculator::REWARD_FREE_SERVICE) {
            $service = Service::where('business_id', $businessId)
                ->where('is_active', true)
                ->find($data['reward_service_id'] ?? 0);

            return $service === null
                ? 'Elige qué servicio se regala, y que esté activo.'
                : null;
        }

        return ($data['reward_value'] ?? 0) > 0
            ? null
            : 'El premio necesita un valor mayor que cero.';
    }

    /** @return array<string, mixed> */
    private function detail(LoyaltyProgram $program): array
    {
        return [
            'id' => $program->id,
            'name' => $program->name,
            'terms' => $program->terms,
            'stamps_required' => (int) $program->stamps_required,
            'reward_type' => $program->reward_type,
            'reward_value' => $program->reward_value === null ? null : (float) $program->reward_value,
            'reward_service_id' => $program->reward_service_id,
            'reward_label' => $program->rewardLabel(),
            'min_ticket' => (float) $program->min_ticket,
            'is_active' => (bool) $program->is_active,
        ];
    }
}
