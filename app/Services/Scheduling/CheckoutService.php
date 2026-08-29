<?php

namespace App\Services\Scheduling;

use App\Models\Appointment;
use App\Models\PaymentMethod;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Cierra una cita atendida y la cobra.
 *
 * Es el momento en que una cita deja de ser agenda y pasa a ser dinero: se
 * congelan el total, el metodo de pago y la comision de cada profesional.
 *
 * Se congelan y no se recalculan a proposito. Los precios del catalogo y los
 * porcentajes de comision cambian; un reporte de hace seis meses tiene que
 * seguir mostrando lo que de verdad se cobro y lo que de verdad se pago,
 * no lo que esas mismas reglas darian hoy.
 */
class CheckoutService
{
    /**
     * @param  array<int, float>  $itemPrices  Precio final por id de item, para
     *                                         ajustar a mano lo que se cobro.
     */
    public function checkout(
        Appointment $appointment,
        PaymentMethod $paymentMethod,
        User $by,
        float $discountAmount = 0,
        ?string $discountReason = null,
        array $itemPrices = [],
    ): Appointment {
        if ($appointment->checked_out_at !== null) {
            throw new \DomainException('Esta cita ya fue cobrada.');
        }

        if ($appointment->status === Appointment::STATUS_CANCELLED) {
            throw new \DomainException('No se puede cobrar una cita cancelada.');
        }

        if ($discountAmount < 0) {
            throw new \DomainException('El descuento no puede ser negativo.');
        }

        return DB::transaction(function () use ($appointment, $paymentMethod, $by, $discountAmount, $discountReason, $itemPrices) {
            $items = $appointment->items()->lockForUpdate()->get();

            $subtotal = 0.0;

            foreach ($items as $item) {
                $item->final_price = round((float) ($itemPrices[$item->id] ?? $item->price), 2);
                $subtotal += $item->final_price;
            }

            if ($discountAmount > $subtotal) {
                throw new \DomainException('El descuento no puede superar el total.');
            }

            $commissionTotal = 0.0;
            $repartido = 0.0;
            $ultimo = $items->count() - 1;

            foreach ($items as $i => $item) {
                // El descuento se reparte proporcional al peso de cada linea:
                // la comision debe calcularse sobre lo que de verdad entro por
                // ese servicio, no sobre el precio de lista.
                if ($i === $ultimo) {
                    // La ultima linea absorbe el redondeo, para que la suma de
                    // las partes sea exactamente el total. Sin esto se pierden
                    // pesos y el cierre de caja no cuadra por centavos.
                    $porcion = round($discountAmount - $repartido, 2);
                } else {
                    $porcion = $subtotal > 0
                        ? round($discountAmount * ($item->final_price / $subtotal), 2)
                        : 0.0;
                    $repartido += $porcion;
                }

                $cobrado = round($item->final_price - $porcion, 2);
                $item->commission_amount = round($cobrado * (float) ($item->commission_rate ?? 0), 2);
                $commissionTotal += $item->commission_amount;
                $item->save();
            }

            $appointment->update([
                'status' => Appointment::STATUS_COMPLETED,
                'payment_method_id' => $paymentMethod->id,
                'checked_out_at' => now(),
                'checked_out_by_user_id' => $by->id,
                'subtotal' => round($subtotal, 2),
                'discount_amount' => round($discountAmount, 2),
                'discount_reason' => $discountReason,
                'total' => round($subtotal - $discountAmount, 2),
                'commission_total' => round($commissionTotal, 2),
            ]);

            return $appointment->fresh(['items.service', 'items.resource', 'paymentMethod']);
        });
    }

    /**
     * Deshace un cobro.
     *
     * NO borra la cita ni libera el horario: el servicio se presto igual. Solo
     * revierte la parte de dinero, para corregir un metodo de pago o un
     * descuento mal digitado sin tener que inventar una cita nueva.
     */
    public function undo(Appointment $appointment): Appointment
    {
        if ($appointment->checked_out_at === null) {
            throw new \DomainException('Esta cita no ha sido cobrada.');
        }

        return DB::transaction(function () use ($appointment) {
            $appointment->items()->update([
                'final_price' => null,
                'commission_amount' => null,
            ]);

            $appointment->update([
                'status' => Appointment::STATUS_CONFIRMED,
                'payment_method_id' => null,
                'checked_out_at' => null,
                'checked_out_by_user_id' => null,
                'subtotal' => null,
                'discount_amount' => 0,
                'discount_reason' => null,
                'total' => null,
                'commission_total' => null,
            ]);

            return $appointment->fresh(['items.service', 'items.resource']);
        });
    }
}
