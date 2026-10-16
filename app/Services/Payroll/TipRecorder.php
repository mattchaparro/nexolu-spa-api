<?php

namespace App\Services\Payroll;

use App\Models\Appointment;
use App\Models\PayrollAdjustment;
use App\Models\Resource;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Anota una propina para quien atendió.
 *
 * Una propina es de la persona entera: no paga comisión ni se le descuenta
 * nada, y llega en la liquidación como un ajuste `propina` (premio), que ya
 * suma al neto y se ve aparte. Por eso NO es parte de lo cobrado del
 * servicio: no entra en las ventas ni en la base de comisión.
 *
 * Mientras no esté liquidada se puede quitar (si se anotó mal).
 */
class TipRecorder
{
    public const CATEGORY = 'propina';

    public function record(
        Resource $resource,
        float $amount,
        User $by,
        ?Appointment $appointment = null,
        ?string $note = null,
    ): PayrollAdjustment {
        $tz = $resource->business?->businessTimezone() ?? config('spa.defaults.timezone');

        return PayrollAdjustment::withoutGlobalScopes()->create([
            'business_id' => $resource->business_id,
            'resource_id' => $resource->id,
            'date' => CarbonImmutable::now($tz)->toDateString(),
            'kind' => PayrollAdjustment::KIND_BONUS,
            'category' => self::CATEGORY,
            'amount' => round($amount, 2),
            'description' => $this->describe($appointment, $resource, $note),
            'created_by_user_id' => $by->id,
        ]);
    }

    private function describe(?Appointment $appointment, Resource $resource, ?string $note): ?string
    {
        $partes = [];

        if ($appointment !== null) {
            $servicios = $appointment->loadMissing('items.service')->items
                ->where('resource_id', $resource->id)
                ->map(fn ($i) => $i->service?->name)
                ->filter()
                ->unique()
                ->implode(' y ');
            $clienta = $appointment->client_name ? explode(' ', trim($appointment->client_name))[0] : null;

            $partes[] = trim($servicios.($clienta ? " · {$clienta}" : ''), ' ·');
        }

        if ($note !== null && trim($note) !== '') {
            $partes[] = trim($note);
        }

        $texto = implode(' — ', array_filter($partes));

        return $texto === '' ? null : mb_substr($texto, 0, 255);
    }
}
