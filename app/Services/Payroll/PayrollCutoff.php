<?php

namespace App\Services\Payroll;

use App\Models\Appointment;
use App\Models\PayrollSettlement;
use App\Models\Resource;

/**
 * Hasta cuándo se le pagó a cada persona, y qué pasa con lo que quedó sin cobrar.
 *
 * Un servicio que se atendió y no se subió antes del pago de la quincena ya no
 * se puede subir: a la persona se le pagó sin él, y cobrarlo ahora lo metería
 * en la quincena siguiente --y en la caja de hoy-- cuando pertenece a una
 * que ya se cerró. Marcela tenía un servicio del 29 de septiembre sin subir
 * después de que se le pagó el 1 de octubre.
 *
 * La regla se mide por la fecha del SERVICIO, no por la de cobro:
 * - se cierra con la última liquidación PAGADA de esa persona (si se deshace,
 *   el cierre vuelve atrás solo);
 * - un servicio queda cerrado si es de un día cubierto por el período pagado
 *   (`period_end`) Y anterior al día en que se pagó. El mismo día del pago
 *   sigue abierto: lo de la tarde de ese día entra en la siguiente.
 */
class PayrollCutoff
{
    /**
     * @return array{resource: string, paid_on: string, period_end: string}|null
     */
    public function closed(Appointment $appointment): ?array
    {
        $appointment->loadMissing('items.resource', 'business');

        $tz = $appointment->business?->businessTimezone() ?? config('spa.defaults.timezone');
        $day = $appointment->starts_at?->setTimezone($tz)->toDateString();

        if ($day === null) {
            return null;
        }

        $personas = $appointment->items->map(fn ($item) => $item->resource)->filter()->unique('id');

        if ($personas->isEmpty()) {
            return null;
        }

        /*
         * TODAS las personas de la cita tienen que tener ese día cerrado.
         *
         * Una cita de manos y pies con dos manicuristas, una ya pagada y la
         * otra no, se puede cobrar: la comisión de la segunda sigue viva, y
         * bloquearla dejaría a esa segunda sin lo suyo por culpa de la otra.
         */
        $primera = null;

        foreach ($personas as $persona) {
            $cierre = $this->closedFor($persona, $day, $tz);

            if ($cierre === null) {
                return null;
            }

            $primera ??= $cierre;
        }

        return $primera;
    }

    /**
     * @return array{resource: string, paid_on: string, period_end: string}|null
     */
    public function closedFor(Resource $resource, string $day, string $tz): ?array
    {
        $ultima = PayrollSettlement::withoutGlobalScope('business')
            ->where('resource_id', $resource->id)
            ->whereNotNull('paid_at')
            ->orderByDesc('period_end')
            ->orderByDesc('paid_at')
            ->first();

        if ($ultima === null) {
            return null;
        }

        // `period_end` es una columna `date`: se lee tal cual, sin zona.
        $periodEnd = $ultima->period_end?->toDateString();
        $paidOn = $ultima->paid_at?->setTimezone($tz)->toDateString();

        if ($periodEnd === null || $paidOn === null) {
            return null;
        }

        if ($day <= $periodEnd && $day < $paidOn) {
            return ['resource' => $resource->name, 'paid_on' => $paidOn, 'period_end' => $periodEnd];
        }

        return null;
    }
}
