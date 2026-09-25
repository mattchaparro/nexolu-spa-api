<?php

namespace App\Ai\Capabilities\Panel;

use App\Ai\AiCaller;
use App\Ai\Capability;
use App\Ai\Resolves;
use App\Services\Reports\SalesReportService;

/**
 * Ventas de un periodo: total, por persona, por medio de pago y los
 * servicios más hechos. «¿Qué servicios se hicieron más esta semana?»,
 * «¿cuánto vendió Marcela este mes?».
 *
 * Es el reporte de Ventas del panel (SalesReportService): se cuenta por
 * fecha de COBRO, igual que la caja y la nómina.
 */
class SalesCapability implements Capability
{
    use PanelDates, Resolves;

    public function __construct(private readonly SalesReportService $report) {}

    public function requiredPermission(): ?string
    {
        return 'reportes.ver';
    }

    public function requiredFeature(): ?string
    {
        return null;
    }

    public function allowsCustomers(): bool
    {
        return false;
    }

    public function rules(): array
    {
        return [
            'periodo' => ['nullable', 'string', 'max:40'],
            'desde' => ['nullable', 'string', 'max:40'],
            'hasta' => ['nullable', 'string', 'max:40'],
            'persona' => ['nullable', 'string', 'max:120'],
        ];
    }

    public function execute(AiCaller $caller, array $arguments): array
    {
        $business = $caller->business;
        $tz = $business->businessTimezone();
        [$desde, $hasta] = $this->rango($arguments['periodo'] ?? null, $arguments['desde'] ?? null, $arguments['hasta'] ?? null, $tz);

        $persona = empty($arguments['persona'])
            ? null
            : $this->resolveResource($business->id, $arguments['persona']);

        $r = $this->report->build($business, $desde, $hasta, $persona?->id);

        return [
            'desde' => $desde->toDateString(),
            'hasta' => $hasta->toDateString(),
            'persona' => $persona?->name,
            'totales' => [
                'servicios' => $r['totals']['services'],
                'vendido' => $r['totals']['charged'],
                'comisiones' => $r['totals']['commission'],
                'queda_al_negocio' => $r['totals']['after_commission'],
                'ticket_promedio' => $r['totals']['average_ticket'],
            ],
            'por_persona' => collect($r['by_person'])->map(fn ($p) => [
                'nombre' => $p['name'], 'servicios' => $p['services'],
                'vendido' => $p['charged'], 'comision' => $p['commission'],
            ])->values()->all(),
            'por_medio_de_pago' => $r['by_payment_method'],
            'servicios_mas_hechos' => collect($r['by_service'])
                ->sortByDesc('services')->take(15)
                ->map(fn ($s) => ['servicio' => $s['name'], 'veces' => $s['services'], 'vendido' => $s['charged'] ?? null])
                ->values()->all(),
        ];
    }
}
