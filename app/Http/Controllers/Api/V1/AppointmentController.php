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
     * Encuentra o crea el cliente de una cita agendada por nombre.
     *
     * Sin esto, agendar a alguien nuevo guardaba su nombre suelto en la cita
     * y no creaba ficha: el listado de clientes quedaba vacio y el historial
     * nunca se acumulaba, que es justo para lo que existe la ficha.
     *
     * Si viene telefono se busca por ahi primero: es lo unico que distingue a
     * dos clientas que se llaman igual, y evita duplicar a la misma persona
     * cada vez que alguien escribe su nombre con una tilde distinta.
     */
    private function resolveClient(int $businessId, ?string $name, ?string $phone): ?Client
    {
        if ($name === null || trim($name) === '') {
            return null;
        }

        if ($phone !== null) {
            $existing = Client::where('business_id', $businessId)->where('phone', $phone)->first();

            if ($existing !== null) {
                return $existing;
            }
        }

        $parts = preg_split('/\s+/', trim($name), 2);

        return Client::create([
            'business_id' => $businessId,
            'name' => $parts[0],
            'last_name' => $parts[1] ?? null,
            'phone' => $phone,
            'is_active' => true,
        ]);
    }

    /**
     * Interpreta la hora que manda el cliente.
     *
     * Una hora sin desfase ("2026-08-29 11:00:00") significa "las 11 en la
     * zona del negocio". Una con desfase se respeta tal cual.
     *
     * La distincion no es cosmetica: `parse($s)->setTimezone($tz)` lee el
     * texto como UTC y despues lo CONVIERTE, asi que unas 11:00 enviadas por
     * el calendario aterrizaban a las 06:00. Pasar la zona a parse() la usa
     * solo cuando el texto no trae la suya, que es la semantica que se quiere.
     */
    private static function interpret(string $value, string $tz): CarbonImmutable
    {
        return CarbonImmutable::parse($value, $tz);
    }

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

        $phone = isset($data['client_phone'])
            ? ChannelPhone::normalize($data['client_phone'], $business->country_code)
            : null;

        $client = isset($data['client_id'])
            ? Client::where('business_id', $business->id)->find($data['client_id'])
            : $this->resolveClient($business->id, $data['client_name'] ?? null, $phone);

        try {
            $appointment = $this->booking->book(
                $business,
                [[
                    'service_id' => $data['service_id'],
                    'resource_id' => $data['resource_id'],
                    'starts_at' => self::interpret($data['starts_at'], $tz),
                ]],
                $client,
                $data['client_name'] ?? $client?->fullName(),
                $phone ?? $client?->phone,
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
            // Arrastrar a otra columna del calendario mueve la cita de
            // profesional, no solo de hora.
            'resource_id' => ['nullable', 'integer'],
        ]);

        $business = $request->user()->business;
        $tz = $business->businessTimezone();

        $resource = isset($data['resource_id'])
            ? \App\Models\Resource::where('business_id', $business->id)->findOrFail($data['resource_id'])
            : null;

        try {
            $moved = $this->booking->reschedule(
                $appointment,
                self::interpret($data['starts_at'], $tz),
                $resource,
            );
        } catch (SlotUnavailableException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(new AppointmentResource($moved->load('items.service', 'items.resource')));
    }
}
