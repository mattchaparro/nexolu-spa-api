<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Models\DiscountCampaign;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Support\Money\CampaignCalculator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Campanas de temporada: el mes de la madre, la semana de pestanas.
 */
class CampaignController
{
    public function index(Request $request): JsonResponse
    {
        $business = $request->user()->business;
        $hoy = now($business->businessTimezone())->toDateString();

        $campaigns = DiscountCampaign::where('business_id', $business->id)
            ->with(['services:id,name', 'categories:id,name'])
            ->orderByDesc('starts_on')
            ->get()
            ->map(fn (DiscountCampaign $c) => $this->detail($c, $hoy));

        return response()->json([
            'campaigns' => $campaigns,
            // El catalogo vive en el backend para que la pantalla no lo
            // duplique: agregar un tipo nuevo no deberia obligar a tocar los
            // dos repos.
            'types' => [
                ['value' => CampaignCalculator::TYPE_PERCENT, 'label' => 'Un porcentaje'],
                ['value' => CampaignCalculator::TYPE_AMOUNT, 'label' => 'Un monto fijo'],
            ],
            'scopes' => [
                ['value' => CampaignCalculator::APPLIES_ALL, 'label' => 'Todos los servicios'],
                ['value' => CampaignCalculator::APPLIES_SERVICES, 'label' => 'Servicios que yo elija'],
                ['value' => CampaignCalculator::APPLIES_CATEGORIES, 'label' => 'Categorías completas'],
            ],
        ]);
    }

    public function store(Request $request, ?DiscountCampaign $campaign = null): JsonResponse
    {
        $business = $request->user()->business;
        $data = $this->validated($request);

        if ($error = $this->esUsable($data)) {
            return response()->json(['message' => $error], 422);
        }

        $campaign ??= new DiscountCampaign(['business_id' => $business->id]);
        $campaign->fill($data + ['business_id' => $business->id]);
        $campaign->is_active = $data['is_active'] ?? true;
        $campaign->save();

        $this->syncScope($campaign, $business->id, $data);

        return response()->json([
            'campaign' => $this->detail(
                $campaign->fresh(['services:id,name', 'categories:id,name']),
                now($business->businessTimezone())->toDateString(),
            ),
        ]);
    }

    /**
     * Apaga la campana sin borrarla.
     *
     * Se DESACTIVA, no se borra: las citas cobradas guardan a que campana se
     * les aplico el descuento, y borrarla dejaria un reporte de hace tres
     * meses diciendo que hubo una rebaja que nadie sabe de donde salio.
     */
    public function destroy(DiscountCampaign $campaign): JsonResponse
    {
        $campaign->update(['is_active' => false]);

        return response()->json(['ok' => true]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:500'],
            'discount_type' => ['required', Rule::in(CampaignCalculator::types())],
            'discount_value' => ['required', 'numeric', 'min:1'],
            'applies_to' => ['required', Rule::in(CampaignCalculator::scopes())],
            'service_ids' => ['nullable', 'array'],
            'service_ids.*' => ['integer'],
            'category_ids' => ['nullable', 'array'],
            'category_ids.*' => ['integer'],
            'starts_on' => ['required', 'date_format:Y-m-d'],
            // Puede no tener fin: corre hasta que se apague a mano.
            'ends_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
            'is_active' => ['nullable', 'boolean'],
        ]);
    }

    /** @return string|null El error, o null si esta bien. */
    private function esUsable(array $data): ?string
    {
        if ($data['discount_type'] === CampaignCalculator::TYPE_PERCENT && $data['discount_value'] > 100) {
            return 'Un porcentaje no puede pasar de 100.';
        }

        if ($data['applies_to'] === CampaignCalculator::APPLIES_SERVICES && empty($data['service_ids'])) {
            return 'Elige a qué servicios aplica.';
        }

        if ($data['applies_to'] === CampaignCalculator::APPLIES_CATEGORIES && empty($data['category_ids'])) {
            return 'Elige a qué categorías aplica.';
        }

        return null;
    }

    /**
     * Guarda el alcance, filtrando lo que no sea del negocio.
     *
     * Un id ajeno le aplicaria la campana de este local al servicio de otro.
     */
    private function syncScope(DiscountCampaign $campaign, int $businessId, array $data): void
    {
        $campaign->services()->sync(
            $data['applies_to'] === CampaignCalculator::APPLIES_SERVICES
                ? Service::where('business_id', $businessId)
                    ->whereIn('id', $data['service_ids'] ?? [])->pluck('id')
                : [],
        );

        $campaign->categories()->sync(
            $data['applies_to'] === CampaignCalculator::APPLIES_CATEGORIES
                ? ServiceCategory::where('business_id', $businessId)
                    ->whereIn('id', $data['category_ids'] ?? [])->pluck('id')
                : [],
        );
    }

    /** @return array<string, mixed> */
    private function detail(DiscountCampaign $campaign, string $hoy): array
    {
        return [
            'id' => $campaign->id,
            'name' => $campaign->name,
            'description' => $campaign->description,
            'discount_type' => $campaign->discount_type,
            'discount_value' => (float) $campaign->discount_value,
            'applies_to' => $campaign->applies_to,
            'service_ids' => $campaign->services->pluck('id')->all(),
            'category_ids' => $campaign->categories->pluck('id')->all(),
            'starts_on' => $campaign->starts_on?->toDateString(),
            'ends_on' => $campaign->ends_on?->toDateString(),
            'is_active' => (bool) $campaign->is_active,
            'label' => $campaign->label(),
            // Si esta corriendo HOY. Es lo que la pantalla necesita para poder
            // decir "vigente" en vez de hacer que alguien compare fechas.
            'running' => $campaign->runsOn($hoy),
        ];
    }
}
