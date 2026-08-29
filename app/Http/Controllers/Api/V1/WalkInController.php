<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\AppointmentResource;
use App\Models\Appointment;
use App\Models\PaymentMethod;
use App\Models\Resource;
use App\Models\Service;
use App\Services\ClientResolver;
use App\Services\Scheduling\BookingService;
use App\Services\Scheduling\CheckoutService;
use App\Services\Scheduling\Exceptions\SlotUnavailableException;
use App\Support\ChannelPhone;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Alguien que llega sin cita.
 *
 * Es el caso mas comun de un spa y el que peor encaja en un flujo pensado
 * solo para agenda: la profesional ya atendio, y lo que necesita es dejarlo
 * registrado y cobrado en un paso, no agendar hacia atras y despues cobrar.
 *
 * Por dentro crea una cita normal -- misma tabla, misma ocupacion, mismo
 * historial del cliente -- para que un servicio sin cita no quede fuera de
 * los reportes ni de la ficha.
 */
class WalkInController
{
    public function __construct(
        private readonly BookingService $booking,
        private readonly CheckoutService $checkout,
        private readonly ClientResolver $clients,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'service_id' => ['required', 'integer'],
            // Opcional: una profesional que registra lo suyo no deberia tener
            // que elegirse a si misma en un desplegable.
            'resource_id' => ['nullable', 'integer'],
            'started_at' => ['nullable', 'date'],
            'client_id' => ['nullable', 'integer'],
            'client_name' => ['required_without:client_id', 'nullable', 'string', 'max:255'],
            'client_phone' => ['nullable', 'string', 'max:32'],
            'notes' => ['nullable', 'string', 'max:2000'],

            // Cobrar en el mismo paso es lo normal en un walk-in. Si no viene
            // metodo, queda registrado sin cobrar y se cobra despues.
            'payment_method_id' => ['nullable', 'integer'],
            'discount_amount' => ['nullable', 'numeric', 'min:0'],
            'final_price' => ['nullable', 'numeric', 'min:0'],
        ]);

        $user = $request->user();
        $business = $user->business;
        $tz = $business->businessTimezone();

        $resource = $this->resolveResource($request, $business->id, $data['resource_id'] ?? null);

        if ($resource === null) {
            return response()->json([
                'message' => 'No sabemos quién prestó el servicio. Elige a la profesional.',
            ], 422);
        }

        $service = Service::where('business_id', $business->id)->findOrFail($data['service_id']);

        $phone = isset($data['client_phone'])
            ? ChannelPhone::normalize($data['client_phone'], $business->country_code)
            : null;

        // Un servicio sin cita crea ficha igual que uno agendado: si no, el
        // cliente no aparece en el listado ni acumula historial.
        $client = $this->clients->resolve(
            $business->id,
            $data['client_id'] ?? null,
            $data['client_name'] ?? null,
            $phone,
        );

        // Hacia atras: el servicio ya se presto. Se redondea al minuto para
        // que la ocupacion caiga en la rejilla como cualquier otra cita.
        $startedAt = isset($data['started_at'])
            ? CarbonImmutable::parse($data['started_at'], $tz)
            : CarbonImmutable::now($tz)->subMinutes($service->duration_min)->startOfMinute();

        try {
            $appointment = DB::transaction(function () use (
                $business, $service, $resource, $startedAt, $client, $data, $user
            ) {
                $appointment = $this->booking->book(
                    $business,
                    [[
                        'service_id' => $service->id,
                        'resource_id' => $resource->id,
                        'starts_at' => $startedAt,
                    ]],
                    $client,
                    $data['client_name'] ?? $client?->fullName(),
                    $phone ?? $client?->phone,
                    Appointment::SOURCE_ADMIN,
                    $data['notes'] ?? null,
                    // Sin validar la jornada: esto NO agenda, deja constancia
                    // de algo que ya pasó. Si un cliente llegó a la una y
                    // Maria la atendió en su hora de almuerzo, el sistema no
                    // puede negarse a registrarlo -- el servicio se prestó y
                    // hay que cobrarlo y comisionarlo igual. La regla protege
                    // la agenda del futuro, no reescribe el pasado.
                    enforceSchedule: false,
                );

                if (! empty($data['payment_method_id'])) {
                    $method = PaymentMethod::where('business_id', $business->id)
                        ->where('is_active', true)
                        ->findOrFail($data['payment_method_id']);

                    $itemPrices = isset($data['final_price'])
                        ? [$appointment->items->first()->id => (float) $data['final_price']]
                        : [];

                    $appointment = $this->checkout->checkout(
                        $appointment,
                        $method,
                        $user,
                        (float) ($data['discount_amount'] ?? 0),
                        null,
                        $itemPrices,
                    );
                }

                return $appointment;
            });
        } catch (SlotUnavailableException $e) {
            // El horario que ocuparia choca con otra cita de esa profesional.
            return response()->json([
                'message' => 'Ese horario choca con otra cita. Ajusta la hora de inicio.',
            ], 409);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(
            new AppointmentResource($appointment->load('items.service', 'items.resource', 'paymentMethod')),
            201,
        );
    }

    /**
     * Quien presto el servicio.
     *
     * Si el usuario ES una profesional, se asume ella misma salvo que elija
     * otra. Recepcion o el administrador tienen que decirlo.
     */
    private function resolveResource(Request $request, int $businessId, ?int $explicit): ?Resource
    {
        if ($explicit !== null) {
            return Resource::where('business_id', $businessId)->find($explicit);
        }

        return $request->user()->resource;
    }
}
