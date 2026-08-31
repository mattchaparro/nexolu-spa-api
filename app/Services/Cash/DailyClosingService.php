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
 *
 * CON VARIAS SEDES, cada local cierra el suyo. No es una preferencia: el
 * efectivo es fisico, hay un cajon en Chapinero y otro en Cedritos, y un
 * cierre que sume los dos no se puede cuadrar contra ninguno -- la diferencia,
 * que es el unico dato que importa, deja de significar nada.
 *
 * De ahi sale la regla menos obvia de este archivo: la BASE de manana sale del
 * cierre anterior DE ESA SEDE. Si Cedritos heredara la base de Chapinero,
 * arrancaria el dia con una plata que su cajon nunca tuvo y no volveria a
 * cuadrar jamas.
 */
class DailyClosingService
{
    public function __construct(private readonly CashTotalsService $totals) {}

    /** @return list<int>|null Filtro de sede para los totales. */
    private function scope(?int $locationId): ?array
    {
        return $locationId === null ? null : [$locationId];
    }

    /**
     * Con dos locales abiertos hay que decir cual se esta cerrando.
     *
     * Un cierre sin sede sumaria los dos cajones y no cuadraria contra
     * ninguno. Se pide el dato en vez de guardar un numero que no significa
     * nada, y se pide tambien en la VISTA PREVIA: enseñarle a alguien un
     * cuadre que luego no va a poder confirmar es peor que preguntarle antes.
     *
     * @throws \DomainException
     */
    public function assertLocationChosen(Business $business, ?int $locationId): void
    {
        if ($locationId !== null) {
            return;
        }

        if ($business->locations()->where('is_active', true)->count() > 1) {
            throw new \DomainException(
                'Tienes más de una sede: dinos cuál estás cerrando. Cada local cuadra su propio cajón.'
            );
        }
    }

    /**
     * Vista previa: lo que daria el cierre si se hiciera ahora.
     *
     * Existe para que nadie cierre a ciegas. Es la parte que vale la pena
     * conservar del flujo de Blue Souls, donde el cierre se confirmaba sin
     * ver antes contra que se estaba comparando.
     */
    public function preview(Business $business, CarbonImmutable $date, ?int $locationId = null): array
    {
        $tz = $business->businessTimezone();
        $local = $date->setTimezone($tz)->startOfDay();

        $totals = $this->totals->forDate(
            $business->id,
            $local,
            $this->openingCashFor($business, $local, $locationId),
            $this->scope($locationId),
        );

        return $totals + [
            'date' => $local->toDateString(),
            'location_id' => $locationId,
            'already_closed' => $this->closingFor($business, $local, $locationId) !== null,
            // Lo que reporto cada profesional. Es contra esto que se cuadra:
            // el cierre del dia no es un ritual de caja, es comprobar que lo
            // que hay coincide con lo que cada una registro.
            'by_resource' => $this->byResource($business, $local, $tz, $locationId),
        ];
    }

    /**
     * @return list<array{name: string, appointments: int, charged: float, cash: float, other: float, commission: float}>
     */
    public function byResource(Business $business, CarbonImmutable $date, string $tz, ?int $locationId = null): array
    {
        $start = $date->setTimezone($tz)->startOfDay();

        $items = AppointmentItem::withoutGlobalScope('business')
            ->where('business_id', $business->id)
            ->whereHas('appointment', function ($q) use ($start, $locationId) {
                $q->whereNotNull('checked_out_at')
                    ->whereBetween('checked_out_at', [$start->utc(), $start->addDay()->utc()])
                    // Por la sede de la CITA, no la de quien atendio: si esa
                    // persona se traslado, el cierre de ese dia no puede
                    // cambiar de local a posteriori.
                    ->when($locationId !== null, fn ($qq) => $qq->where('location_id', $locationId));
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

    public function close(
        Business $business,
        User $user,
        CarbonImmutable $date,
        float $actualCash,
        ?string $note = null,
        ?int $locationId = null,
    ): CashClosing {
        $tz = $business->businessTimezone();
        $local = $date->setTimezone($tz)->startOfDay();

        if ($local->isFuture()) {
            throw new \DomainException('No se puede cerrar un dia que no ha pasado.');
        }

        $this->assertLocationChosen($business, $locationId);

        if ($this->closingFor($business, $local, $locationId) !== null) {
            throw new \DomainException('Este dia ya fue cerrado.');
        }

        return DB::transaction(function () use ($business, $user, $local, $actualCash, $note, $locationId) {
            $opening = $this->openingCashFor($business, $local, $locationId);
            $totals = $this->totals->forDate($business->id, $local, $opening, $this->scope($locationId));

            return CashClosing::create([
                'business_id' => $business->id,
                'location_id' => $locationId,
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
        // El ultimo DE SU SEDE. Cada local lleva su propia cadena de bases, y
        // el cierre de ayer en Cedritos no bloquea deshacer el de hoy en
        // Chapinero.
        $last = CashClosing::where('business_id', $business->id)
            ->where('location_id', $closing->location_id)
            ->orderByDesc('date')
            ->first();

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
    public function pendingDates(Business $business, int $lookbackDays = 30, ?int $locationId = null): array
    {
        $tz = $business->businessTimezone();
        $today = CarbonImmutable::now($tz)->startOfDay();

        $closed = CashClosing::where('business_id', $business->id)
            ->when($locationId !== null, fn ($q) => $q->where('location_id', $locationId))
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

            if ($this->totals->forDate($business->id, $day, 0, $this->scope($locationId))['appointments'] > 0) {
                $pending[] = $iso;
            }
        }

        return $pending;
    }

    private function closingFor(Business $business, CarbonImmutable $date, ?int $locationId = null): ?CashClosing
    {
        return CashClosing::where('business_id', $business->id)
            ->when($locationId !== null, fn ($q) => $q->where('location_id', $locationId))
            ->whereDate('date', $date->toDateString())
            ->first();
    }

    /**
     * La base del dia es lo que quedo contado en el ultimo cierre anterior.
     * Sin esto cada dia arrancaria en cero y el cuadre nunca daria.
     *
     * Y es el cierre anterior DE ESA SEDE. Si Cedritos heredara la base de
     * Chapinero arrancaria el dia con una plata que su cajon nunca tuvo, y no
     * volveria a cuadrar jamas -- un error que se arrastra hacia adelante y
     * que nadie relaciona con el dia en que empezo.
     */
    private function openingCashFor(Business $business, CarbonImmutable $date, ?int $locationId = null): float
    {
        $previous = CashClosing::where('business_id', $business->id)
            ->when($locationId !== null, fn ($q) => $q->where('location_id', $locationId))
            ->whereDate('date', '<', $date->toDateString())
            ->orderByDesc('date')
            ->first();

        return (float) ($previous?->base_for_next_day ?? 0);
    }
}
