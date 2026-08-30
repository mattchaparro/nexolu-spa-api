<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\AppointmentResource;
use App\Models\Appointment;
use App\Models\PaymentMethod;
use App\Support\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Registrar el abono con que un cliente separo su cita.
 *
 * Este API todavia NO cobra en linea: no hay pasarela cableada. El cliente
 * transfiere y alguien del local confirma que llego. Ese "alguien confirma" es
 * lo que hace este controlador, y por eso pide el metodo de pago -- sin el, la
 * plata entra sin quedar en ninguna cuenta y el cierre del dia no cuadra.
 */
class DepositController
{
    public function store(Request $request, Appointment $appointment): JsonResponse
    {
        $data = $request->validate([
            'payment_method_id' => ['required', 'integer'],
            // Se puede recibir MENOS de lo que se pidio -- el cliente mandó lo
            // que tenia y el local lo acepto igual. Lo que no se puede es
            // inventar un abono de otro monto sin dejarlo escrito.
            'amount' => ['nullable', 'numeric', 'min:0'],
            'reference' => ['nullable', 'string', 'max:120'],
        ]);

        if ($appointment->deposit_paid_at !== null) {
            return response()->json(['message' => 'Esta cita ya tiene el abono registrado.'], 422);
        }

        if ($appointment->status === Appointment::STATUS_CANCELLED) {
            return response()->json(['message' => 'No se registra un abono de una cita cancelada.'], 422);
        }

        $method = PaymentMethod::where('business_id', $appointment->business_id)
            ->findOrFail($data['payment_method_id']);

        $amount = round((float) ($data['amount'] ?? $appointment->deposit_amount), 2);

        if ($amount <= 0) {
            return response()->json(['message' => 'El abono tiene que ser mayor que cero.'], 422);
        }

        $appointment->update([
            'deposit_amount' => $amount,
            'deposit_paid_at' => now(),
            'deposit_payment_method_id' => $method->id,
            'deposit_reference' => $data['reference'] ?? null,
        ]);

        AuditLogger::log('abono.registrado', [
            'amount' => $amount,
            'payment_method_id' => $method->id,
        ], Appointment::class, $appointment->id);

        return response()->json(new AppointmentResource(
            $appointment->fresh(['items.service', 'items.resource', 'paymentMethod']),
        ));
    }

    /**
     * Deshace el registro de un abono.
     *
     * No borra la cita ni el monto pedido: solo dice que esa plata NO habia
     * llegado. Es para corregir a quien marco la transferencia equivocada.
     */
    public function destroy(Request $request, Appointment $appointment): JsonResponse
    {
        if ($appointment->deposit_paid_at === null) {
            return response()->json(['message' => 'Esta cita no tiene abono registrado.'], 422);
        }

        if ($appointment->checked_out_at !== null) {
            return response()->json([
                'message' => 'La cita ya fue cobrada. Deshaz el cobro antes de tocar el abono.',
            ], 422);
        }

        $appointment->update([
            'deposit_paid_at' => null,
            'deposit_payment_method_id' => null,
            'deposit_reference' => null,
        ]);

        AuditLogger::log('abono.deshecho', [], Appointment::class, $appointment->id);

        return response()->json(new AppointmentResource(
            $appointment->fresh(['items.service', 'items.resource']),
        ));
    }
}
