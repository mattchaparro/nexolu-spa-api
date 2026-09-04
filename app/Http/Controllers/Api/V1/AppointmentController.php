<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\AppointmentResource;
use App\Models\Appointment;
use App\Models\ServicePackage;
use App\Services\ClientResolver;
use App\Services\Scheduling\BookingService;
use App\Services\Scheduling\Exceptions\SlotUnavailableException;
use App\Support\AgendaScope;
use App\Support\ChannelPhone;
use App\Support\LocationScope;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AppointmentController
{
    public function __construct(
        private readonly BookingService $booking,
        private readonly ClientResolver $clients,
    ) {}

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
            'location_id' => ['nullable', 'integer'],
        ]);

        $business = $request->user()->business;
        $tz = $business->businessTimezone();

        // El rango se arma en hora local y se compara en UTC, que es como se
        // persiste: "el 31 de agosto en Bogota" no son las mismas 24 horas
        // que "el 31 de agosto en UTC".
        $from = CarbonImmutable::parse($data['date'], $tz)->startOfDay()->utc();
        $to = $from->addDay();

        // Sin `citas.ver_todas` solo se ve la agenda propia. Ver la del
        // negocio entero es la version suave de la misma fuga: revela toda la
        // clientela, no solo la que uno atiende.
        $scope = AgendaScope::for($request->user());

        if ($scope->seesNothing()) {
            return AppointmentResource::collection(collect());
        }

        /*
         * La sede LIMITA, no solo filtra.
         *
         * Sin sede pedida no vienen todas: vienen las que esta persona puede
         * ver. Es la diferencia entre un filtro y un limite, y confundirlos es
         * como la administradora de Cedritos termina leyendo la clientela de
         * Chapinero simplemente por no mandar un parametro.
         */
        try {
            $sedes = LocationScope::for($request->user())->filterFor($data['location_id'] ?? null);
        } catch (\DomainException) {
            // Una sede que no le toca no devuelve un error util para tantear:
            // se responde lo mismo que si no hubiera citas.
            return AppointmentResource::collection(collect());
        }

        $appointments = Appointment::query()
            ->with(['items.service', 'items.resource', 'client'])
            ->when($sedes !== null, fn ($q) => $q->whereIn('location_id', $sedes))
            ->where('starts_at', '>=', $from)
            ->where('starts_at', '<', $to)
            ->when(
                isset($data['resource_id']),
                fn ($q) => $q->whereHas('items', fn ($i) => $i->where('resource_id', $data['resource_id'])),
            )
            ->when(
                $scope->resourceId !== null,
                fn ($q) => $q->whereHas('items', fn ($i) => $i->where('resource_id', $scope->resourceId)),
            )
            ->orderBy('starts_at')
            ->get();

        return AppointmentResource::collection($appointments);
    }

    /**
     * UNA cita, por id.
     *
     * Existe porque no toda pantalla llega a una cita por la agenda del dia.
     * «Mi dia» lista lo atendido y sin cobrar SIN limite hacia atras -- a
     * alguien se le olvido registrar lo de ayer y lo hace hoy, que es el caso
     * normal, no la excepcion -- y hasta ahora el boton de cobrar la buscaba
     * en la rejilla de HOY: una cita de ayer no estaba ahi y la pantalla
     * contestaba "no encontramos esa cita".
     *
     * Mismos limites que el listado: sin `citas.ver_todas`, solo las que uno
     * atendio, y solo de las sedes que le tocan. 404 y no 403 -- una cita
     * ajena no deberia ni confirmar que existe.
     */
    public function show(Request $request, Appointment $appointment): AppointmentResource
    {
        $scope = AgendaScope::for($request->user());

        abort_if($scope->seesNothing(), 404);

        abort_unless(
            LocationScope::for($request->user())->allows($appointment->location_id),
            404,
        );

        abort_if(
            $scope->resourceId !== null
                && ! $appointment->items()->where('resource_id', $scope->resourceId)->exists(),
            404,
        );

        return new AppointmentResource(
            $appointment->load(['items.service', 'items.resource', 'client', 'paymentMethod']),
        );
    }

    public function store(Request $request): JsonResponse
    {
        /*
         * Dos formas de pedir lo mismo, porque son dos gestos distintos en la
         * pantalla: un servicio suelto (lo mas comun, y lo que manda el
         * calendario al tocar un hueco) o una visita de varios -- que puede
         * venir de un combo.
         *
         * `items` lleva la hora de CADA eslabon y no una sola de arranque: la
         * cadena la calculo el motor de disponibilidad, con los buffers y los
         * cambios de persona ya resueltos. Recalcularla aca a partir de una
         * hora suelta seria hacer dos veces la misma cuenta y arriesgarse a
         * que den distinto.
         */
        $data = $request->validate([
            'service_id' => ['required_without:items', 'integer'],
            'resource_id' => ['required_with:service_id', 'integer'],
            'starts_at' => ['required_with:service_id', 'date'],

            'items' => ['required_without:service_id', 'array', 'min:1'],
            'items.*.service_id' => ['required', 'integer'],
            'items.*.resource_id' => ['required', 'integer'],
            'items.*.starts_at' => ['required', 'date'],

            // De que combo sale, para que el cobro sepa que descuento aplicar.
            'service_package_id' => ['nullable', 'integer'],

            /*
             * Garantia: rehacer un trabajo que fallo. Vale 0 y no paga
             * comision, y se anota a quien hizo el ORIGINAL -- no a quien lo
             * rehace. El sentido de llevar la cuenta es saber quien esta
             * recibiendo garantias.
             */
            'is_warranty' => ['nullable', 'boolean'],
            'warranty_for_resource_id' => ['required_if:is_warranty,true', 'nullable', 'integer'],
            'warranty_for_item_id' => ['nullable', 'integer'],
            'warranty_note' => ['nullable', 'string', 'max:1000'],

            'client_id' => ['nullable', 'integer'],
            'client_name' => ['required_without:client_id', 'nullable', 'string', 'max:255'],
            'client_phone' => ['nullable', 'string', 'max:32'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $business = $request->user()->business;
        $tz = $business->businessTimezone();

        $package = isset($data['service_package_id'])
            ? ServicePackage::with('services')->findOrFail($data['service_package_id'])
            : null;

        $items = isset($data['items'])
            ? array_map(fn (array $item) => [
                'service_id' => $item['service_id'],
                'resource_id' => $item['resource_id'],
                'starts_at' => self::interpret($item['starts_at'], $tz),
            ], $data['items'])
            : [[
                'service_id' => $data['service_id'],
                'resource_id' => $data['resource_id'],
                'starts_at' => self::interpret($data['starts_at'], $tz),
            ]];

        /*
         * La garantia aplica a la visita completa, no linea por linea: nadie
         * agenda "esto es garantia y esto no" en la misma cita. Si algun dia
         * hiciera falta, se mueve a `items.*`.
         */
        if ($request->boolean('is_warranty')) {
            $items = array_map(fn (array $item) => $item + [
                'is_warranty' => true,
                'warranty_for_resource_id' => $data['warranty_for_resource_id'],
                'warranty_for_item_id' => $data['warranty_for_item_id'] ?? null,
                'warranty_note' => $data['warranty_note'] ?? null,
            ], $items);
        }

        if ($package !== null) {
            $esperados = $package->services->pluck('id')->sort()->values()->all();
            $recibidos = collect($items)->pluck('service_id')->sort()->values()->all();

            // Sin esto se podria agendar UN servicio barato marcandolo como el
            // combo y llevarse el descuento del combo entero.
            if ($esperados !== $recibidos) {
                return response()->json([
                    'message' => 'Los servicios no corresponden al combo elegido.',
                ], 422);
            }
        }

        $phone = isset($data['client_phone'])
            ? ChannelPhone::normalize($data['client_phone'], $business->country_code)
            : null;

        $client = $this->clients->resolve(
            $business->id,
            $data['client_id'] ?? null,
            $data['client_name'] ?? null,
            $phone,
        );

        try {
            $appointment = $this->booking->book(
                $business,
                $items,
                $client,
                $data['client_name'] ?? $client?->fullName(),
                $phone ?? $client?->phone,
                Appointment::SOURCE_ADMIN,
                $data['notes'] ?? null,
                package: $package,
            );
        } catch (SlotUnavailableException $e) {
            // 409 y no 500: que otra persona tomara el horario primero es un
            // desenlace normal, no una falla del sistema. El front lo trata
            // recargando la disponibilidad.
            return response()->json(['message' => $e->getMessage()], 409);
        } catch (\DomainException $e) {
            // Fuera de jornada o encima de un almuerzo. 422 y no 409: no es
            // que el hueco este tomado, es que no existe, y recargar la
            // disponibilidad no lo va a hacer aparecer.
            return response()->json(['message' => $e->getMessage()], 422);
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
