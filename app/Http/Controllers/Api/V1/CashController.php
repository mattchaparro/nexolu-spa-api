<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Appointment;
use App\Models\CashClosing;
use App\Models\CashShift;
use App\Services\Cash\CashShiftService;
use App\Services\Cash\CashTotalsService;
use App\Services\Cash\DailyClosingService;
use App\Support\LocationScope;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * La caja, por sede.
 *
 * El efectivo es fisico: hay un cajon en Chapinero y otro en Cedritos. Toda
 * peticion de aca pasa por `LocationScope`, que decide que sedes puede mirar
 * quien pregunta -- el dueno todas, los demas las suyas.
 *
 * Una sede prohibida devuelve 422 con su mensaje, NO se degrada en silencio a
 * "sin filtro": convertir un filtro no permitido en "todas" es exactamente al
 * reves de lo que se quiere.
 */
class CashController
{
    public function __construct(
        private readonly CashShiftService $shifts,
        private readonly DailyClosingService $closings,
        private readonly CashTotalsService $totals,
    ) {}

    private function scope(Request $request): LocationScope
    {
        return LocationScope::for($request->user());
    }

    private function requestedLocation(Request $request): ?int
    {
        $raw = $request->query('location_id') ?? $request->input('location_id');

        return $raw === null || $raw === '' ? null : (int) $raw;
    }

    /** El turno propio, con lo que lleva hasta ahora. */
    public function currentShift(Request $request): JsonResponse
    {
        $shift = $this->shifts->openFor($request->user());

        if ($shift === null) {
            return response()->json(['shift' => null]);
        }

        return response()->json([
            'shift' => $this->shiftPayload($shift, $request),
            'totals' => $this->shifts->currentTotals($shift),
        ]);
    }

    public function openShift(Request $request): JsonResponse
    {
        $data = $request->validate([
            'opening_cash' => ['required', 'numeric', 'min:0'],
            'note' => ['nullable', 'string', 'max:255'],
            'location_id' => ['nullable', 'integer'],
        ]);

        try {
            $shift = $this->shifts->open(
                $request->user(),
                (float) $data['opening_cash'],
                $data['note'] ?? null,
                // Un turno ocurre en UN cajon. Quien solo puede estar en una
                // sede no tiene que decirlo; quien puede estar en varias -- la
                // dueña incluida -- si.
                $this->scope($request)->resolveOneFor(
                    $request->user()->business,
                    $data['location_id'] ?? null,
                    'el turno',
                ),
            );
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($this->shiftPayload($shift, $request), 201);
    }

    public function closeShift(Request $request): JsonResponse
    {
        $data = $request->validate([
            'counted_cash' => ['required', 'numeric', 'min:0'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $shift = $this->shifts->openFor($request->user());

        if ($shift === null) {
            return response()->json(['message' => 'No tienes un turno abierto.'], 422);
        }

        $closed = $this->shifts->close(
            $shift,
            $request->user(),
            (float) $data['counted_cash'],
            $data['note'] ?? null,
        );

        return response()->json($this->shiftPayload($closed, $request));
    }

    /** Vista previa del cierre del dia: nadie deberia cerrar a ciegas. */
    public function closingPreview(Request $request): JsonResponse
    {
        $business = $request->user()->business;
        $date = $this->dateFrom($request, $business->businessTimezone());

        try {
            // El cierre es de UN cajon: si esta persona ve varias sedes y no
            // dijo cual, hay que preguntarle.
            $locationId = $this->scope($request)
                ->resolveOne($this->requestedLocation($request), 'el cierre');
            $this->closings->assertLocationChosen($business, $locationId);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(
            $this->closings->preview($business, $date, $locationId) + [
                'pending_dates' => $this->closings->pendingDates($business, 30, $locationId),
            ]
        );
    }

    public function closeDay(Request $request): JsonResponse
    {
        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'actual_cash' => ['required', 'numeric', 'min:0'],
            'note' => ['nullable', 'string', 'max:255'],
            'location_id' => ['nullable', 'integer'],
        ]);

        $business = $request->user()->business;

        try {
            $closing = $this->closings->close(
                $business,
                $request->user(),
                CarbonImmutable::parse($data['date'], $business->businessTimezone()),
                (float) $data['actual_cash'],
                $data['note'] ?? null,
                $this->scope($request)->resolveOne($data['location_id'] ?? null, 'el cierre'),
            );
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($closing, 201);
    }

    public function closings(Request $request): JsonResponse
    {
        try {
            $sedes = $this->scope($request)->filterFor($this->requestedLocation($request));
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(
            CashClosing::with(['closedBy', 'location'])
                // Sin filtro vienen las de todas sus sedes: quien administra
                // dos locales espera ver los dos en el historial.
                ->when($sedes !== null, fn ($q) => $q->whereIn('location_id', $sedes))
                ->orderByDesc('date')
                ->limit(60)
                ->get()
                ->map(fn (CashClosing $c) => [
                    'id' => $c->id,
                    'date' => $c->date?->toDateString(),
                    'location_id' => $c->location_id,
                    'location_name' => $c->location?->name,
                    'total_charged' => (float) $c->total_charged,
                    'total_cash' => (float) $c->total_cash,
                    'total_expenses' => (float) $c->total_expenses,
                    'total_commissions' => (float) $c->total_commissions,
                    'expected_cash' => (float) $c->expected_cash,
                    'actual_cash' => (float) $c->actual_cash,
                    'difference' => (float) $c->difference,
                    'closed_by' => $c->closedBy?->fullName(),
                    'note' => $c->note,
                ])
        );
    }

    public function undoClosing(Request $request, CashClosing $closing): JsonResponse
    {
        // Deshacer el cierre de una sede ajena rehace la base de un cajon que
        // esta persona ni siquiera puede ver.
        if (! $this->scope($request)->allows($closing->location_id)) {
            return response()->json(['message' => 'No tienes acceso a esa sede.'], 422);
        }

        try {
            $this->closings->undo($request->user()->business, $closing);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => 'Cierre deshecho.']);
    }

    /**
     * El resumen del dia: lo que el dueno mira al final de la jornada.
     */
    public function dailySummary(Request $request): JsonResponse
    {
        $business = $request->user()->business;
        $tz = $business->businessTimezone();
        $date = $this->dateFrom($request, $tz);

        try {
            /*
             * Aca SI se puede mirar todo junto, a diferencia del cierre. El
             * resumen no se cuadra contra un cajon: responde "como nos fue
             * hoy", y para el dueno de dos locales esa pregunta es de los dos.
             */
            $sedes = $this->scope($request)->filterFor($this->requestedLocation($request));
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $totals = $this->totals->forDate($business->id, $date, 0, $sedes);

        $appointments = Appointment::query()
            ->with(['items.service', 'items.resource'])
            ->when($sedes !== null, fn ($q) => $q->whereIn('location_id', $sedes))
            ->whereBetween('starts_at', [$date->startOfDay()->utc(), $date->addDay()->startOfDay()->utc()])
            ->get();

        // Por profesional: lo que atendio y lo que se gano. Es la pregunta que
        // sigue inmediatamente a "cuanto se hizo hoy".
        $byResource = [];

        foreach ($appointments as $appointment) {
            foreach ($appointment->items as $item) {
                $name = $item->resource?->name ?? 'Sin asignar';

                $byResource[$name] ??= ['name' => $name, 'appointments' => 0, 'charged' => 0.0, 'commission' => 0.0];
                $byResource[$name]['appointments']++;
                $byResource[$name]['charged'] += (float) ($item->final_price ?? 0);
                $byResource[$name]['commission'] += (float) ($item->commission_amount ?? 0);
            }
        }

        usort($byResource, fn ($a, $b) => $b['charged'] <=> $a['charged']);

        return response()->json([
            'date' => $date->toDateString(),
            'totals' => $totals,
            'appointments' => [
                'total' => $appointments->count(),
                'completed' => $appointments->where('status', 'completed')->count(),
                'cancelled' => $appointments->where('status', 'cancelled')->count(),
                'no_show' => $appointments->where('status', 'no_show')->count(),
                // Lo que todavia falta cobrar hoy: la accion pendiente mas
                // comun al cerrar la jornada.
                'pending_checkout' => $appointments
                    ->whereIn('status', ['pending', 'confirmed', 'in_progress'])
                    ->count(),
            ],
            'by_resource' => array_values($byResource),
        ]);
    }

    private function dateFrom(Request $request, string $tz): CarbonImmutable
    {
        $raw = $request->query('date');

        return $raw
            ? CarbonImmutable::parse((string) $raw, $tz)->startOfDay()
            : CarbonImmutable::now($tz)->startOfDay();
    }

    /** @return array<string, mixed> */
    private function shiftPayload(CashShift $shift, Request $request): array
    {
        $tz = $request->user()->business->businessTimezone();

        return [
            'id' => $shift->id,
            'user' => $shift->user?->fullName(),
            'opened_at' => $shift->opened_at?->setTimezone($tz)->toIso8601String(),
            'closed_at' => $shift->closed_at?->setTimezone($tz)->toIso8601String(),
            'opening_cash' => (float) $shift->opening_cash,
            'counted_cash' => $shift->counted_cash === null ? null : (float) $shift->counted_cash,
            'expected_cash' => $shift->expected_cash === null ? null : (float) $shift->expected_cash,
            'difference' => $shift->difference === null ? null : (float) $shift->difference,
            'total_charged' => $shift->total_charged === null ? null : (float) $shift->total_charged,
            'payment_breakdown' => $shift->payment_breakdown,
            'is_open' => $shift->isOpen(),
        ];
    }
}
