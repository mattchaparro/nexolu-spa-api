<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Models\LoyaltyProgram;
use App\Models\LoyaltyTier;
use App\Models\Service;
use App\Services\Loyalty\LoyaltyService;
use App\Support\Money\LoyaltyCalculator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * La tarjeta de sellos del negocio.
 *
 * UN programa activo por negocio. Dos a la vez obligarian a decidir cual gana
 * el sello de una visita, y esa pregunta no tiene una respuesta que el
 * mostrador pueda explicar en voz alta.
 *
 * DOS FORMAS de premiar, y el negocio elige una:
 *
 *   - `card`: junta 5, el sexto va con premio, y vuelve a empezar.
 *   - `ladder`: a las 5 un premio, a las 10 otro, a las 15 otro. Los sellos
 *     no se gastan nunca.
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
            'modes' => [
                [
                    'value' => LoyaltyProgram::MODE_CARD,
                    'label' => 'Tarjeta que se reinicia',
                    'help' => 'Junta N visitas, se lleva el premio, y la tarjeta vuelve a empezar.',
                ],
                [
                    'value' => LoyaltyProgram::MODE_LADDER,
                    'label' => 'Escalera de hitos',
                    'help' => 'A las 5 visitas un premio, a las 10 otro, a las 15 otro. '
                        .'Las visitas se acumulan para siempre.',
                ],
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $business = $request->user()->business;

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'mode' => ['nullable', Rule::in([LoyaltyProgram::MODE_CARD, LoyaltyProgram::MODE_LADDER])],
            'terms' => ['nullable', 'string', 'max:1000'],
            /*
             * Minimo 2 sellos. Una tarjeta de 1 regala en cada visita, y una
             * de 0 lo haria para siempre: no es fidelizacion, es una rebaja
             * permanente que nadie decidio.
             */
            'stamps_required' => ['required_without:tiers', 'nullable', 'integer', 'min:2', 'max:100'],
            'reward_type' => ['required_without:tiers', 'nullable', Rule::in(LoyaltyCalculator::rewardTypes())],
            'reward_value' => ['nullable', 'numeric', 'min:0'],
            'reward_service_id' => ['nullable', 'integer'],
            'min_ticket' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['nullable', 'boolean'],

            /*
             * Minimo dos escalones. Una escalera de uno solo es una tarjeta
             * que no se reinicia -- se entrega una vez y nunca mas -- y eso
             * no es lo que nadie quiere decir al elegir "escalera".
             */
            'tiers' => ['nullable', 'array', 'min:2', 'max:20'],
            'tiers.*.stamps_required' => ['required', 'integer', 'min:2', 'max:500'],
            'tiers.*.reward_type' => ['required', Rule::in(LoyaltyCalculator::rewardTypes())],
            'tiers.*.reward_value' => ['nullable', 'numeric', 'min:0'],
            'tiers.*.reward_service_id' => ['nullable', 'integer'],
        ]);

        $esEscalera = ($data['mode'] ?? LoyaltyProgram::MODE_CARD) === LoyaltyProgram::MODE_LADDER;

        if ($esEscalera && empty($data['tiers'])) {
            return response()->json(['message' => 'Una escalera necesita al menos dos escalones.'], 422);
        }

        if ($error = $this->escalonesUsables($business->id, $esEscalera, $data)) {
            return response()->json(['message' => $error], 422);
        }

        $program = $this->loyalty->activeProgram($business)
            ?? LoyaltyProgram::firstOrNew([
                'business_id' => $business->id,
                'name' => $data['name'],
            ]);

        return DB::transaction(function () use ($program, $data, $business, $esEscalera) {
            $campos = collect($data)->except('tiers')->all();

            /*
             * En la escalera `stamps_required` del programa no manda nada --
             * cada escalon trae el suyo -- pero la columna no admite nulo. Se
             * guarda el primer escalon para que quien lea la fila suelta vea
             * un numero coherente y no un cero.
             */
            if ($esEscalera) {
                $campos['stamps_required'] = collect($data['tiers'])->min('stamps_required');
                $campos['reward_type'] = collect($data['tiers'])->first()['reward_type'];
                $campos['reward_value'] = collect($data['tiers'])->first()['reward_value'] ?? null;
            }

            $program->fill($campos + ['business_id' => $business->id]);
            $program->mode = $esEscalera ? LoyaltyProgram::MODE_LADDER : LoyaltyProgram::MODE_CARD;
            $program->is_active = $data['is_active'] ?? true;
            $program->save();

            if ($esEscalera) {
                $this->sincronizarEscalones($program, $data['tiers']);
            }

            return response()->json([
                'program' => $this->detail($program->fresh(['rewardService', 'tiers.rewardService'])),
            ]);
        });
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
     * Deja los escalones como los pidio el negocio.
     *
     * Se emparejan POR NUMERO DE SELLOS, no por posicion ni por id: el
     * escalon de las 10 visitas sigue siendo el mismo aunque cambie su premio
     * o el orden en que llego el formulario.
     *
     * Y los que ya no vienen se APAGAN, no se borran. Borrarlos dejaria sin
     * rastro los premios que entregaron, y si el negocio vuelve a poner ese
     * escalon manana, todas las clientas que ya lo ganaron lo ganarian otra
     * vez.
     *
     * @param  list<array<string, mixed>>  $tiers
     */
    private function sincronizarEscalones(LoyaltyProgram $program, array $tiers): void
    {
        $pedidos = [];

        foreach ($tiers as $tier) {
            $sellos = (int) $tier['stamps_required'];
            $pedidos[] = $sellos;

            LoyaltyTier::withoutGlobalScope('business')->updateOrCreate(
                ['program_id' => $program->id, 'stamps_required' => $sellos],
                [
                    'business_id' => $program->business_id,
                    'reward_type' => $tier['reward_type'],
                    'reward_value' => $tier['reward_value'] ?? null,
                    'reward_service_id' => $tier['reward_service_id'] ?? null,
                    'is_active' => true,
                ],
            );
        }

        LoyaltyTier::withoutGlobalScope('business')
            ->where('program_id', $program->id)
            ->whereNotIn('stamps_required', $pedidos)
            ->update(['is_active' => false]);
    }

    /**
     * Que cada premio se pueda entregar de verdad.
     *
     * @param  array<string, mixed>  $data
     * @return string|null El error, o null si esta bien.
     */
    private function escalonesUsables(int $businessId, bool $esEscalera, array $data): ?string
    {
        if (! $esEscalera) {
            return $this->rewardIsUsable($businessId, $data);
        }

        $vistos = [];

        foreach ($data['tiers'] as $tier) {
            $sellos = (int) $tier['stamps_required'];

            if (isset($vistos[$sellos])) {
                return "Hay dos escalones para {$sellos} visitas. Cada número de visitas premia una sola vez.";
            }

            $vistos[$sellos] = true;

            if ($error = $this->rewardIsUsable($businessId, $tier)) {
                return "El escalón de {$sellos} visitas: ".lcfirst($error);
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return string|null El error, o null si esta bien.
     */
    private function rewardIsUsable(int $businessId, array $data): ?string
    {
        if (($data['reward_type'] ?? null) === LoyaltyCalculator::REWARD_FREE_SERVICE) {
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
            'mode' => $program->mode,
            'terms' => $program->terms,
            'stamps_required' => (int) $program->stamps_required,
            'reward_type' => $program->reward_type,
            'reward_value' => $program->reward_value === null ? null : (float) $program->reward_value,
            'reward_service_id' => $program->reward_service_id,
            'reward_label' => $program->rewardLabel(),
            'min_ticket' => (float) $program->min_ticket,
            'is_active' => (bool) $program->is_active,
            'tiers' => $program->tiers
                ->map(fn (LoyaltyTier $t) => [
                    'stamps_required' => (int) $t->stamps_required,
                    'reward_type' => $t->reward_type,
                    'reward_value' => $t->reward_value === null ? null : (float) $t->reward_value,
                    'reward_service_id' => $t->reward_service_id,
                    'reward_label' => $t->rewardLabel(),
                ])->values()->all(),
        ];
    }
}
