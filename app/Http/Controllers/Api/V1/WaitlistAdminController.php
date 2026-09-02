<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\WaitlistEntry;
use App\Support\LocationScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * La lista de espera vista desde adentro del negocio.
 *
 * Es una pantalla de LECTURA con un solo verbo: cerrar una espera. Tomar un
 * cupo a nombre de alguien no existe aqui a proposito — eso se hace agendando
 * la cita normal, que ya cierra la espera sola. Duplicar ese camino seria
 * tener dos formas de agendar con reglas distintas.
 */
class WaitlistAdminController
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => ['nullable', 'string', 'max:16'],
            'location_id' => ['nullable', 'integer'],
        ]);

        try {
            $sedes = LocationScope::for($request->user())->filterFor($data['location_id'] ?? null);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $tz = $request->user()->business->businessTimezone();

        $entries = WaitlistEntry::with(['client', 'service', 'preferredResource', 'location'])
            /*
             * Sin sede entra siempre: "cualquier sede me sirve" es asunto de
             * todas. Mismo criterio que los mensajes y los gastos generales.
             */
            ->when($sedes !== null, fn ($q) => $q->where(
                fn ($qq) => $qq->whereIn('location_id', $sedes)->orWhereNull('location_id'),
            ))
            ->when(
                ! empty($data['status']),
                fn ($q) => $q->where('status', $data['status']),
                // Sin filtro: quienes siguen esperando, que es a lo que se entra.
                fn ($q) => $q->where('status', WaitlistEntry::STATUS_OPEN),
            )
            ->orderByRaw("status = 'open' desc")
            ->orderBy('date_from')
            ->limit(200)
            ->get();

        return response()->json([
            'data' => $entries->map(fn (WaitlistEntry $e) => [
                'id' => $e->id,
                'status' => $e->status,
                'status_label' => self::statusLabels()[$e->status] ?? $e->status,
                'client_name' => $e->client?->fullName(),
                'phone' => $e->phone,
                'service' => $e->service?->name,
                'preferred_resource' => $e->preferredResource?->name,
                'location' => $e->location?->name,
                'date_from' => $e->date_from?->toDateString(),
                'date_to' => $e->date_to?->toDateString(),
                'time_from' => $e->time_from ? substr($e->time_from, 0, 5) : null,
                'time_to' => $e->time_to ? substr($e->time_to, 0, 5) : null,
                'last_notified_at' => $e->last_notified_at?->setTimezone($tz)->toIso8601String(),
                'created_at' => $e->created_at?->setTimezone($tz)->toIso8601String(),
            ]),
            'open' => WaitlistEntry::where('status', WaitlistEntry::STATUS_OPEN)->count(),
        ]);
    }

    /**
     * Cerrar una espera desde el mostrador.
     *
     * El caso real: la clienta llama y dice "ya no, gracias". Se marca
     * stopped, no se borra — quien espero es un dato del negocio.
     */
    public function stop(Request $request, WaitlistEntry $entry): JsonResponse
    {
        if ($entry->status === WaitlistEntry::STATUS_OPEN) {
            $entry->forceFill(['status' => WaitlistEntry::STATUS_STOPPED])->save();
        }

        return response()->json(['message' => 'Espera cerrada.']);
    }

    /** @return array<string, string> */
    public static function statusLabels(): array
    {
        return [
            WaitlistEntry::STATUS_OPEN => 'Esperando',
            WaitlistEntry::STATUS_FULFILLED => 'Consiguió cupo',
            WaitlistEntry::STATUS_STOPPED => 'Ya no quiere',
            WaitlistEntry::STATUS_EXPIRED => 'Venció',
        ];
    }
}
