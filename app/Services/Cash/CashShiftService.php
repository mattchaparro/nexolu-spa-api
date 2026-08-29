<?php

namespace App\Services\Cash;

use App\Models\CashShift;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * El turno de caja de una persona: abrir con una base, cobrar durante el
 * turno, contar y cerrar.
 */
class CashShiftService
{
    public function __construct(private readonly CashTotalsService $totals) {}

    public function openFor(User $user): ?CashShift
    {
        return CashShift::where('user_id', $user->id)->whereNull('closed_at')->first();
    }

    public function open(User $user, float $openingCash, ?string $note = null): CashShift
    {
        if ($this->openFor($user) !== null) {
            throw new \DomainException('Ya tienes un turno abierto.');
        }

        if ($openingCash < 0) {
            throw new \DomainException('La base no puede ser negativa.');
        }

        return CashShift::create([
            'business_id' => $user->business_id,
            'user_id' => $user->id,
            'opened_at' => now(),
            'opening_cash' => $openingCash,
            'opening_note' => $note,
        ]);
    }

    /**
     * Cierra el turno con lo que la persona conto fisicamente.
     *
     * La diferencia se guarda siempre, tambien cuando es negativa. Es el dato
     * que importa del cierre; esconderlo o redondearlo a cero lo volveria
     * inutil.
     */
    public function close(CashShift $shift, User $closedBy, float $countedCash, ?string $note = null): CashShift
    {
        if (! $shift->isOpen()) {
            throw new \DomainException('Este turno ya esta cerrado.');
        }

        return DB::transaction(function () use ($shift, $closedBy, $countedCash, $note) {
            $totals = $this->totals->between(
                $shift->business_id,
                CarbonImmutable::parse($shift->opened_at),
                CarbonImmutable::now(),
                (float) $shift->opening_cash,
                $shift->user_id,
            );

            $shift->update([
                'closed_at' => now(),
                'closed_by_user_id' => $closedBy->id,
                'counted_cash' => $countedCash,
                'expected_cash' => $totals['expected_cash'],
                'difference' => round($countedCash - $totals['expected_cash'], 2),
                'closing_note' => $note,
                'total_charged' => $totals['total_charged'],
                'total_cash' => $totals['total_cash'],
                'total_other_methods' => $totals['total_other_methods'],
                'total_expenses' => $totals['total_expenses'],
                'payment_breakdown' => $totals['payment_breakdown'],
            ]);

            return $shift->fresh();
        });
    }

    /** Lo que lleva el turno hasta este momento, sin cerrarlo. */
    public function currentTotals(CashShift $shift): array
    {
        return $this->totals->between(
            $shift->business_id,
            CarbonImmutable::parse($shift->opened_at),
            CarbonImmutable::now(),
            (float) $shift->opening_cash,
            $shift->user_id,
        );
    }
}
