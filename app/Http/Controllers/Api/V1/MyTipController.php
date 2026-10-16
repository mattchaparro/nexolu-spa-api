<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Appointment;
use App\Models\PayrollAdjustment;
use App\Services\Payroll\TipRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * La propina que le dejaron a una profesional, anotada por ella misma.
 *
 * Es de ella entera: sin comisión, directo a su liquidación. Solo puede
 * anotar y quitar las suyas, y quitar solo mientras no la hayan liquidado.
 */
class MyTipController
{
    public function store(Request $request): JsonResponse
    {
        $resource = $request->user()->resource;

        abort_if($resource === null, 422, 'Tu usuario no está asociado a nadie de la agenda.');

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:1', 'max:5000000'],
            'note' => ['nullable', 'string', 'max:120'],
            'appointment_id' => ['nullable', 'integer'],
        ]);

        $appointment = null;

        if (! empty($data['appointment_id'])) {
            $appointment = Appointment::find($data['appointment_id']);

            // Solo de un servicio suyo.
            abort_unless(
                $appointment !== null && $appointment->items()->where('resource_id', $resource->id)->exists(),
                422,
                'Esa cita no es tuya.',
            );
        }

        $tip = app(TipRecorder::class)->record(
            $resource,
            (float) $data['amount'],
            $request->user(),
            $appointment,
            $data['note'] ?? null,
        );

        return response()->json($this->present($tip), 201);
    }

    public function destroy(Request $request, PayrollAdjustment $adjustment): JsonResponse
    {
        $resource = $request->user()->resource;

        abort_unless(
            $resource !== null
                && $adjustment->resource_id === $resource->id
                && $adjustment->category === TipRecorder::CATEGORY,
            404,
        );
        abort_if($adjustment->settlement_id !== null, 422, 'Esa propina ya se liquidó: no se puede quitar.');

        $adjustment->delete();

        return response()->json(null, 204);
    }

    /** @return array<string, mixed> */
    private function present(PayrollAdjustment $tip): array
    {
        return [
            'id' => $tip->id,
            'date' => $tip->date?->toDateString(),
            'amount' => round((float) $tip->amount, 2),
            'description' => $tip->description,
        ];
    }
}
