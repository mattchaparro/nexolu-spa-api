<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\PaymentMethod;
use App\Services\Reports\SalesReportService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Reporte de ventas con filtros.
 *
 * Distinto del cierre del dia, que responde "¿cuanto efectivo deberia haber en
 * la caja ahora?". Esto responde "¿como nos fue?" sobre un rango: la semana,
 * la quincena, el mes, o una sola persona.
 */
class SalesReportController
{
    public function __construct(private readonly SalesReportService $report) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'resource_id' => ['nullable', 'integer'],
            'payment_method_id' => ['nullable', 'integer'],
        ]);

        $business = $request->user()->business;
        $tz = $business->businessTimezone();
        $hoy = CarbonImmutable::now($tz)->startOfDay();

        // Por defecto, hoy. Es la pregunta que se hace al cerrar la jornada, y
        // abrir el reporte en un rango vacio obliga a configurarlo cada vez.
        $from = isset($data['from']) ? CarbonImmutable::parse($data['from'], $tz) : $hoy;
        $to = isset($data['to']) ? CarbonImmutable::parse($data['to'], $tz) : $from;

        if ($from->diffInDays($to) > 366) {
            return response()->json([
                'message' => 'El rango no puede superar un año.',
            ], 422);
        }

        return response()->json(
            $this->report->build(
                $business,
                $from,
                $to,
                $data['resource_id'] ?? null,
                $data['payment_method_id'] ?? null,
            )
            + [
                'filters' => [
                    'resources' => $this->report->filterableResources($business),
                    'payment_methods' => PaymentMethod::where('is_active', true)
                        ->orderBy('sort_order')
                        ->get()
                        ->map(fn (PaymentMethod $m) => ['id' => $m->id, 'name' => $m->name])
                        ->values(),
                ],
            ]
        );
    }
}
