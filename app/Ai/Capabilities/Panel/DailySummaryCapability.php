<?php

namespace App\Ai\Capabilities\Panel;

use App\Ai\AiCaller;
use App\Ai\Capability;
use App\Models\Appointment;
use App\Services\Messaging\AvisoCitaNueva;
use App\Services\Reports\DailySummaryService;

/**
 * «¿Cuánto vendí hoy?», «¿cómo nos fue ayer?»: el resumen de un día.
 *
 * La misma cuenta de la pantalla de Resumen (DailySummaryService), más las
 * citas que ENTRARON ese día por cada canal: web, WhatsApp o panel.
 */
class DailySummaryCapability implements Capability
{
    use PanelDates;

    public function __construct(private readonly DailySummaryService $summary) {}

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
        return ['fecha' => ['nullable', 'string', 'max:40']];
    }

    public function execute(AiCaller $caller, array $arguments): array
    {
        $business = $caller->business;
        $tz = $business->businessTimezone();
        $dia = $this->dia($arguments['fecha'] ?? null, $tz);

        $r = $this->summary->build($business, $dia);

        $entraron = Appointment::withoutGlobalScopes()
            ->where('business_id', $business->id)
            ->whereBetween('created_at', [$dia->utc(), $dia->addDay()->utc()])
            ->get()
            ->groupBy('source')
            ->map(fn ($g, $source) => [
                'canal' => AvisoCitaNueva::CANALES[$source] ?? $source,
                'citas' => $g->count(),
            ])->values()->all();

        return [
            'fecha' => $dia->toDateString(),
            'dia' => $dia->locale('es')->isoFormat('dddd D [de] MMMM'),
            'vendido_servicios' => $r['totals']['total_charged'] ?? 0,
            'por_medio_de_pago' => collect($r['totals']['payment_breakdown'] ?? [])
                ->map(fn ($p) => ['medio' => $p['label'], 'total' => $p['total']])->values()->all(),
            'comisiones' => $r['totals']['total_commissions'] ?? 0,
            'gastos' => $r['totals']['total_expenses'] ?? 0,
            'productos' => $r['products'],
            'citas_del_dia' => [
                'total' => $r['appointments']['total'],
                'completadas' => $r['appointments']['completed'],
                'canceladas' => $r['appointments']['cancelled'],
                'no_asistio' => $r['appointments']['no_show'],
                'sin_cobrar' => $r['appointments']['pending_checkout'],
            ],
            'por_persona' => collect($r['by_resource'])->map(fn ($p) => [
                'nombre' => $p['name'],
                'servicios' => $p['appointments'],
                'vendido' => round($p['charged'], 2),
                'comision' => round($p['commission'], 2),
            ])->values()->all(),
            'citas_agendadas_ese_dia_por_canal' => $entraron,
        ];
    }
}
