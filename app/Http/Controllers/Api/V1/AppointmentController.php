<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\AppointmentResource;
use App\Models\Appointment;
use App\Models\Client;
use App\Services\Scheduling\BookingService;
use App\Services\Scheduling\Exceptions\SlotUnavailableException;
use App\Support\ChannelPhone;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AppointmentController
{
    public function __construct(private readonly BookingService $booking) {}

    /**
     * Las citas de un dia, en la zona del negocio.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'resource_id' => ['nullable', 'integer'],
        ]);

        $business = $request->user()->business;
        $tz = $business->businessTimezone();

        // El rango se arma en hora local y se compara en UTC, que es como se
        // persiste: "el 31 de agosto en Bogota" no son las mismas 24 horas
        // que "el 31 de agosto en UTC".
        $from = CarbonImmutable::parse($data['date'], $tz)->startOfDay()->utc();
        $to = $from->addDay();

        $appointments = Appointment::query()
            ->with(['items.service', 'items.resource', 'client'])
            ->where('starts_at', '>=', $from)
            ->where('starts_at', '<', $to)
            ->when(
                isset($data['resource_id']),
                fn ($q) => $q->whereHas('items', fn ($i) => $i->where('resource_id', $data['resource_id'])),
            )
            ->orderBy('starts_at')
            ->get();

        return AppointmentResource::collection($appointments);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'service_id' => ['required', 'integer'],
            'resource_id' => ['required', 'integer'],
            'starts_at' => ['required', 'date'],
            'client_id' => ['nullable', 'integer'],
            'client_name' => ['required_without:client_id', 'nullable', 'string', 'max:255'],
            'client_phone' => ['nullable', 'string', 'max:32'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $business = $request->user()->business;
        $tz = $business->businessTimezone();

        $client = isset($data['client_id'])
            ? Client::where('business_id', $business->id)->find($data['client_id'])
            : null;

        try {
            $appointment = $this->booking->book(
                $business,
                [[
                    'service_id' => $data['service_id'],
                    'resource_id' => $data['resource_id'],
                    'starts_at' => CarbonImmutable::parse($data['starts_at'])->setTimezone($tz),
                ]],
                $client,
                $data['client_name'] ?? null,
                isset($data['client_phone'])
                    ? ChannelPhone::normalize($data['client_phone'], $business->country_code)
                    : null,
                Appointment::SOURCE_ADMIN,
                $data['notes'] ?? null,
            );
        } catch (SlotUnavailableException $e) {
            // 409 y no 500: que otra persona tomara el horario primero es un
            // desenlace normal, no una falla del sistema. El front lo trata
            // recargando la disponibilidad.
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json(
            new AppointmentResource($appointment->load('items.service', 'items.resource')),
            201,
        );
    }

    public function cancel(Request $request, Appointment $appointment): AppointmentResource
    {
        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $this->booking->cancel($appointment, $request->user()->id, $data['reason'] ?? null);

        return new AppointmentResource($appointment->fresh(['items.service', 'items.resource']));
    }

    public function reschedule(Request $request, Appointment $appointment): JsonResponse
    {
        $data = $request->validate([
            'starts_at' => ['required', 'date'],
        ]);

        $tz = $request->user()->business->businessTimezone();

        try {
            $moved = $this->booking->reschedule(
                $appointment,
                CarbonImmutable::parse($data['starts_at'])->setTimezone($tz),
            );
        } catch (SlotUnavailableException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(new AppointmentResource($moved->load('items.service', 'items.resource')));
    }
}
