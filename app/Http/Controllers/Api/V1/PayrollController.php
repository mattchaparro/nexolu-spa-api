<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\PayrollAdjustment;
use App\Models\PayrollSettlement;
use App\Models\Resource;
use App\Services\Payroll\PayrollService;
use App\Support\Money\CommissionPolicy;
use App\Support\Payroll\AdjustmentCatalog;
use App\Support\Payroll\BasePeriod;
use App\Support\Payroll\PayrollMode;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Nomina: lo que se le paga a cada profesional.
 *
 * El flujo tiene tres pasos y en ese orden importa: se mira lo pendiente de
 * todo el equipo, se abre el detalle de una, y recien ahi se paga. Pagar sin
 * haber visto el detalle es como se firma una liquidacion con un anticipo
 * olvidado adentro.
 */
class PayrollController
{
    public function __construct(private readonly PayrollService $payroll) {}

    /** Lo pendiente de todas, para decidir a quien se le paga hoy. */
    public function pending(Request $request): JsonResponse
    {
        $data = $request->validate(['until' => ['nullable', 'date_format:Y-m-d']]);

        $business = $request->user()->business;
        $until = $this->until($data['until'] ?? null, $business->businessTimezone());

        return response()->json([
            'until' => $until->toDateString(),
            'resources' => $this->payroll->pending($business, $until),
        ]);
    }

    /** El detalle de una: servicio por servicio y ajuste por ajuste. */
    public function preview(Request $request, Resource $resource): JsonResponse
    {
        $data = $request->validate(['until' => ['nullable', 'date_format:Y-m-d']]);

        $business = $request->user()->business;

        return response()->json($this->payroll->preview(
            $business,
            $resource,
            $this->until($data['until'] ?? null, $business->businessTimezone()),
        ));
    }

    public function settle(Request $request, Resource $resource): JsonResponse
    {
        $data = $request->validate([
            'until' => ['nullable', 'date_format:Y-m-d'],
            'payment_method_id' => ['nullable', 'integer', 'exists:payment_methods,id'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $business = $request->user()->business;

        $settlement = $this->payroll->settle(
            $business,
            $resource,
            $this->until($data['until'] ?? null, $business->businessTimezone()),
            $request->user(),
            $data['payment_method_id'] ?? null,
            $data['notes'] ?? null,
        );

        return response()->json($this->show($settlement), 201);
    }

    /** Historial de liquidaciones del negocio. */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['resource_id' => ['nullable', 'integer']]);

        $settlements = PayrollSettlement::with('resource')
            ->when(isset($data['resource_id']), fn ($q) => $q->where('resource_id', $data['resource_id']))
            ->orderByDesc('paid_at')
            ->limit(100)
            ->get()
            ->map(fn (PayrollSettlement $s) => [
                'id' => $s->id,
                'resource_name' => $s->resource?->name,
                'period_start' => $s->period_start->toDateString(),
                'period_end' => $s->period_end->toDateString(),
                'services_count' => $s->services_count,
                'commission_total' => (float) $s->commission_total,
                'base_total' => (float) $s->base_total,
                'bonus_total' => (float) $s->bonus_total,
                'deduction_total' => (float) $s->deduction_total,
                'net_total' => (float) $s->net_total,
                'paid_at' => $s->paid_at->toIso8601String(),
            ]);

        return response()->json($settlements);
    }

    /** El comprobante congelado. */
    public function showSettlement(PayrollSettlement $settlement): JsonResponse
    {
        return response()->json($this->show($settlement->load(['items', 'adjustments', 'resource'])));
    }

    public function destroySettlement(PayrollSettlement $settlement): JsonResponse
    {
        $this->payroll->undo($settlement);

        return response()->json(['message' => 'Liquidación deshecha.']);
    }

    /*
    |--------------------------------------------------------------------------
    | Anticipos, descuentos y bonos
    |--------------------------------------------------------------------------
    */

    public function adjustments(Request $request): JsonResponse
    {
        $data = $request->validate([
            'resource_id' => ['nullable', 'integer'],
            'pending' => ['nullable', 'boolean'],
        ]);

        $rows = PayrollAdjustment::with('resource')
            ->when(isset($data['resource_id']), fn ($q) => $q->where('resource_id', $data['resource_id']))
            ->when($data['pending'] ?? false, fn ($q) => $q->whereNull('settlement_id'))
            ->orderByDesc('date')
            ->limit(200)
            ->get()
            ->map(fn (PayrollAdjustment $a) => [
                'id' => $a->id,
                'resource_id' => $a->resource_id,
                'resource_name' => $a->resource?->name,
                'date' => $a->date->toDateString(),
                'kind' => $a->kind,
                'category' => $a->category,
                'category_label' => AdjustmentCatalog::label($a->category),
                'amount' => (float) $a->amount,
                'description' => $a->description,
                'settled' => $a->settlement_id !== null,
            ]);

        return response()->json([
            'catalog' => AdjustmentCatalog::all(),
            'adjustments' => $rows,
        ]);
    }

    public function storeAdjustment(Request $request): JsonResponse
    {
        $data = $request->validate([
            'resource_id' => ['required', 'integer'],
            'date' => ['required', 'date_format:Y-m-d'],
            'category' => ['required', Rule::in(AdjustmentCatalog::names())],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'description' => ['nullable', 'string', 'max:255'],
        ]);

        // El recurso se resuelve DENTRO del scope del negocio: mandar el id de
        // otro spa tiene que dar 404, no crearle un descuento a un tercero.
        $resource = Resource::findOrFail($data['resource_id']);

        $adjustment = PayrollAdjustment::create([
            'business_id' => $request->user()->business->id,
            'resource_id' => $resource->id,
            'date' => $data['date'],
            // El tipo lo pone el catalogo, no quien llama: un "anticipo" que
            // llegue marcado como bono sumaria en vez de restar.
            'kind' => AdjustmentCatalog::kindOf($data['category']),
            'category' => $data['category'],
            'amount' => $data['amount'],
            'description' => $data['description'] ?? null,
            'created_by_user_id' => $request->user()->id,
        ]);

        return response()->json(['id' => $adjustment->id], 201);
    }

    public function destroyAdjustment(PayrollAdjustment $adjustment): JsonResponse
    {
        abort_if(
            $adjustment->settlement_id !== null,
            422,
            'Este movimiento ya se liquidó. Deshaz la liquidación si necesitas corregirlo.',
        );

        $adjustment->delete();

        return response()->json(['message' => 'Movimiento eliminado.']);
    }

    /** Como se le paga a cada profesional: modo, base y vigencia. */
    public function compensation(Request $request): JsonResponse
    {
        $resources = Resource::where('type', Resource::TYPE_STAFF)
            ->orderBy('sort_order')->orderBy('name')->get()
            ->map(fn (Resource $r) => [
                'id' => $r->id,
                'name' => $r->name,
                'is_active' => (bool) $r->is_active,
                'payroll_mode' => $r->payroll_mode,
                'base_amount' => (float) $r->base_amount,
                'base_period' => $r->base_period,
                'base_until' => $r->base_until?->toDateString(),
                'payroll_started_on' => $r->payroll_started_on?->toDateString(),
            ]);

        return response()->json([
            'resources' => $resources,
            'modes' => array_map(fn (string $m) => [
                'name' => $m, 'label' => PayrollMode::label($m), 'uses_base' => PayrollMode::usesBase($m),
            ], PayrollMode::all()),
            'base_periods' => array_map(fn (string $p) => [
                'name' => $p, 'label' => BasePeriod::label($p), 'days' => BasePeriod::days($p),
            ], BasePeriod::all()),

            /*
             * Sobre que valor se paga comision cuando hubo descuento. Vive en
             * esta pantalla y no en la de servicios porque es una regla de
             * NOMINA: quien busca como se le paga al equipo la busca aca.
             */
            'commission_bases' => $request->user()->business->commissionBases(),
            'commission_sources' => array_map(
                fn (string $s) => ['key' => $s] + CommissionPolicy::labels()[$s],
                CommissionPolicy::sources(),
            ),
            'commission_base_options' => array_values(CommissionPolicy::baseLabels()),
        ]);
    }

    /** Guarda sobre que valor se paga comision para cada origen de descuento. */
    public function updateCommissionBases(Request $request): JsonResponse
    {
        $data = $request->validate([
            'commission_bases' => ['required', 'array'],
            'commission_bases.*' => [Rule::in(CommissionPolicy::bases())],
        ]);

        $business = $request->user()->business;

        // Solo origenes del catalogo: uno inventado quedaria guardado para
        // siempre sin que nada lo lea, y quien lo escribio creeria que aplico.
        $limpio = array_intersect_key(
            $data['commission_bases'],
            array_flip(CommissionPolicy::sources()),
        );

        $business->update([
            'commission_settings' => collect($limpio)
                ->mapWithKeys(fn (string $base, string $source) => [
                    CommissionPolicy::settingKey($source) => $base,
                ])
                ->all(),
        ]);

        return response()->json(['commission_bases' => $business->fresh()->commissionBases()]);
    }

    public function updateCompensation(Request $request, Resource $resource): JsonResponse
    {
        $data = $request->validate([
            'payroll_mode' => ['required', Rule::in(PayrollMode::all())],
            'base_amount' => ['nullable', 'numeric', 'min:0'],
            'base_period' => ['nullable', Rule::in(BasePeriod::all())],
            'base_until' => ['nullable', 'date_format:Y-m-d'],
            'payroll_started_on' => ['nullable', 'date_format:Y-m-d'],
        ]);

        // Mover la fecha de arranque despues de una liquidacion dejaria un
        // hueco entre lo pagado y lo que se pagara.
        if (isset($data['payroll_started_on']) && $resource->settlements()->exists()) {
            unset($data['payroll_started_on']);
        }

        $resource->update($data + ['base_period' => $data['base_period'] ?? $resource->base_period]);

        return response()->json([
            'id' => $resource->id,
            'payroll_mode' => $resource->payroll_mode,
            'base_amount' => (float) $resource->base_amount,
            'base_period' => $resource->base_period,
            'base_until' => $resource->base_until?->toDateString(),
            'payroll_started_on' => $resource->payroll_started_on?->toDateString(),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Internos
    |--------------------------------------------------------------------------
    */

    private function until(?string $value, string $tz): CarbonImmutable
    {
        return $value
            ? CarbonImmutable::parse($value, $tz)->startOfDay()
            : CarbonImmutable::now($tz)->startOfDay();
    }

    /** @return array<string, mixed> */
    private function show(PayrollSettlement $settlement): array
    {
        return [
            'id' => $settlement->id,
            'resource_name' => $settlement->resource?->name,
            'period_start' => $settlement->period_start->toDateString(),
            'period_end' => $settlement->period_end->toDateString(),
            'mode' => $settlement->mode,
            'mode_label' => PayrollMode::label($settlement->mode),
            'services_count' => $settlement->services_count,
            'charged_total' => (float) $settlement->charged_total,
            'commission_total' => (float) $settlement->commission_total,
            'base_total' => (float) $settlement->base_total,
            'bonus_total' => (float) $settlement->bonus_total,
            'deduction_total' => (float) $settlement->deduction_total,
            'net_total' => (float) $settlement->net_total,
            'paid_at' => $settlement->paid_at->toIso8601String(),
            'notes' => $settlement->notes,
            'items' => $settlement->items->map(fn ($i) => [
                'charged_at' => $i->charged_at->toIso8601String(),
                'service_name' => $i->service_name,
                'client_name' => $i->client_name,
                'charged' => (float) $i->charged,
                'commission_rate' => $i->commission_rate === null ? null : (float) $i->commission_rate,
                'commission_amount' => (float) $i->commission_amount,
            ])->values(),
            'adjustments' => $settlement->adjustments->map(fn ($a) => [
                'date' => $a->date->toDateString(),
                'kind' => $a->kind,
                'category_label' => AdjustmentCatalog::label($a->category),
                'amount' => (float) $a->amount,
                'description' => $a->description,
            ])->values(),
        ];
    }
}
