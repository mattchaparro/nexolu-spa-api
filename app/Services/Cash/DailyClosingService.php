<?php

namespace App\Services\Cash;

use App\Models\AppointmentItem;
use App\Models\Business;
use App\Models\CashClosing;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * El cierre del dia del negocio entero, distinto del turno de una persona.
 *
 * Un dia puede tener varios turnos; el cierre diario es lo que responde
 * "cuanto entro y cuanto deberia haber en la caja al final del dia".
 */
class DailyClosingService
{
    public function __construct(private readonly CashTotalsService $totals) {}

    /**
     * Vista previa: lo que daria el cierre si se hiciera ahora.
     *
     * Existe para que nadie cierre a ciegas. Es la parte que vale la pena
     * conservar del flujo de Blue Souls, donde el cierre se confirmaba sin
     * ver antes contra que se estaba comparando.
     */
    public function preview(Business $business, CarbonImmutable $date): array
    {
        $tz = $business->businessTimezone();
        $local = $date->setTimezone($tz)->startOfDay();

        $totals = $this->totals->forDate(
            $business->id,
            $local,
            $this->openingCashFor($business, $local),
        );

        return $totals + [
            'date' => $local->toDateString(),
            'already_closed' => $this->closingFor($business, $local) !== null,
            // Lo que reporto cada profesional. Es contra esto que se cuadra:
            // el cierre del dia no es un ritual de caja, es comprobar que lo
            // que hay coincide con lo que cada una registro.
            'by_resource' => $this->byResource($business, $local, $tz),
        ];
    }

    /**
     * @return list<array{name: string, appointments: int, charged: float, cash: float, other: float, commission: float}>
     */
    public function byResource(Business $business, CarbonImmutable $date, string $tz): array
    {
        $start = $date->setTimezone($tz)->startOfDay();

        $items = AppointmentItem::withoutGlobalScope('business')
            ->where('business_id', $business->id)
            ->whereHas('appointment', function ($q) use ($start) {
                $q->whereNotNull('checked_out_at')
                    ->whereBetween('checked_out_at', [$start->utc(), $start->addDay()->utc()]);
            })
            ->with(['resource', 'appointment.paymentMethod'])
            ->get();

        $rows = [];

        foreach ($items as $item) {
            $name = $item->resource?->name ?? 'Sin asignar';
            $charged = (float) ($item->final_price ?? 0);
            $isCash = (bool) ($item->appointment?->paymentMethod?->counts_as_cash ?? false);

            $rows[$name] ??= [
                'name' => $name,
                'appointments' => 0,
                'charged' => 0.0,
                // Separado a proposito: lo que una profesional debe ENTREGAR
                // es lo que cobro en efectivo, no todo lo que facturo.
                'cash' => 0.0,
                'other' => 0.0,
                'commission' => 0.0,
            ];

            $rows[$name]['appointments']++;
            $rows[$name]['charged'] = round($rows[$name]['charged'] + $charged, 2);
            $rows[$name][$isCash ? 'cash' : 'other'] = round($rows[$name][$isCash ? 'cash' : 'other'] + $charged, 2);
            $rows[$name]['commission'] = round(
                $rows[$name]['commission'] + (float) ($item->commission_amount ?? 0),
                2,
            );
        }

        usort($rows, fn ($a, $b) => $b['charged'] <=> $a['charged']);

        return array_values($rows);
    }

    public function close(Business $business, User $user, CarbonImmutable $date, float $actualCash, ?string $note = null): CashClosing
    {
        $tz = $business->businessTimezone();
        $local = $date->setTimezone($tz)->startOfDay();

        if ($local->isFuture()) {
            throw new \DomainException('No se puede cerrar un dia que no ha pasado.');
        }

        if ($this->closingFor($business, $local) !== null) {
            throw new \DomainException('Este dia ya fue cerrado.');
        }

        return DB::transaction(function () use ($business, $user, $local, $actualCash, $note) {
            $opening = $this->openingCashFor($business, $local);
            $totals = $this->totals->forDate($business->id, $local, $opening);

            return CashClosing::create([
                'business_id' => $business->id,
                'date' => $local->toDateString(),
                'total_charged' => $totals['total_charged'],
                'total_cash' => $totals['total_cash'],
                'total_other_methods' => $totals['total_other_methods'],
                'payment_breakdown' => $totals['payment_breakdown'],
                'opening_cash' => $opening,
                'total_expenses' => $totals['total_expenses'],
                'total_commissions' => $totals['total_commissions'],
                'expected_cash' => $totals['expected_cash'],
                'actual_cash' => $actualCash,
                'difference' => round($actualCash - $totals['expected_cash'], 2),
                // Lo contado es lo que queda de base manana, no lo esperado:
                // el dia siguiente arranca con la plata que HAY, no con la que
                // deberia haber.
                'base_for_next_day' => $actualCash,
                'note' => $note,
                'closed_by_user_id' => $user->id,
            ]);
        });
    }

    /**
     * Deshace un cierre.
     *
     * Solo el ultimo: deshacer uno del medio dejaria a los dias siguientes con
     * una base que ya no corresponde, y nadie se enteraria.
     */
    public function undo(Business $business, CashClosing $closing): void
    {
        $last = CashClosing::where('business_id', $business->id)->orderByDesc('date')->first();

        if ($last === null || $last->id !== $closing->id) {
            throw new \DomainException('Solo se puede deshacer el ultimo cierre.');
        }

        $closing->delete();
    }

    /**
     * Dias sin cerrar hacia atras.
     *
     * Solo cuenta los que tuvieron movimiento: marcar como pendiente un lunes
     * en que el spa no abrio genera una lista que nadie mira.
     *
     * @return list<string>
     */
    public function pendingDates(Business $business, int $lookbackDays = 30): array
    {
        $tz = $business->businessTimezone();
        $today = CarbonImmutable::now($tz)->startOfDay();

        $closed = CashClosing::where('business_id', $business->id)
            ->where('date', '>=', $today->subDays($lookbackDays)->toDateString())
            ->pluck('date')
            ->map(fn ($d) => CarbonImmutable::parse($d)->toDateString())
            ->all();

        $pending = [];

        for ($i = 1; $i <= $lookbackDays; $i++) {
            $day = $today->subDays($i);
            $iso = $day->toDateString();

            if (in_array($iso, $closed, true)) {
                continue;
            }

            if ($this->totals->forDate($business->id, $day)['appointments'] > 0) {
                $pending[] = $iso;
            }
        }

        return $pending;
    }

    private function closingFor(Business $business, CarbonImmutable $date): ?CashClosing
    {
        return CashClosing::where('business_id', $business->id)
            ->whereDate('date', $date->toDateString())
            ->first();
    }

    /**
     * La base del dia es lo que quedo contado en el ultimo cierre anterior.
     * Sin esto cada dia arrancaria en cero y el cuadre nunca daria.
     */
    private function openingCashFor(Business $business, CarbonImmutable $date): float
    {
        $previous = CashClosing::where('business_id', $business->id)
            ->whereDate('date', '<', $date->toDateString())
            ->orderByDesc('date')
            ->first();

        return (float) ($previous?->base_for_next_day ?? 0);
    }
}
