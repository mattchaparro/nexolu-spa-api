<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Appointment;
use App\Models\Business;
use App\Models\ClientPenalty;
use App\Models\Resource;
use App\Models\ResourceBreak;
use App\Models\Service;
use App\Services\ClientResolver;
use App\Services\Scheduling\AvailabilityService;
use App\Services\Scheduling\BookingService;
use App\Services\Scheduling\Exceptions\OutsideWorkingHoursException;
use App\Services\Scheduling\Exceptions\SlotUnavailableException;
use App\Support\ChannelPhone;
use App\Support\ImageStorage;
use App\Support\PublicProfile;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * La pagina publica de un negocio: quien es, que hace, y reservar.
 *
 * SIN AUTENTICAR, y eso cambia como hay que escribir cada consulta. El scope
 * global de `BelongsToBusiness` solo filtra cuando hay usuario en sesion; aca
 * no lo hay, asi que una consulta sin `where('business_id', ...)` explicito
 * devuelve las filas de TODOS los negocios. Cada query de este archivo lo
 * lleva a mano, y hay una prueba que lo comprueba.
 *
 * El alcance es deliberadamente estrecho. Blue Souls exponia
 * `/api/external/*` con throttle y nada mas: crear y borrar citas, aplicar
 * penalizaciones y enumerar clientes con telefono, todo sin credenciales. Aca
 * lo publico solo puede LEER el catalogo y CREAR una cita para si mismo.
 * Nunca listar clientes, nunca cancelar, nunca cobrar, nunca saber si un
 * telefono ya existe.
 */
class PublicBookingController
{
    /**
     * Cuantas citas futuras puede tener una misma persona sin llamar.
     *
     * No es un limite de negocio sino un freno de abuso: sin el, un formulario
     * abierto deja llenar la agenda de una profesional en un minuto. Quien de
     * verdad necesita mas, llama.
     */
    private const MAX_CITAS_ABIERTAS = 3;

    /**
     * Inasistencias sin perdonar a partir de las cuales se pide llamar.
     *
     * El local que quiere seguir recibiendola igual la agenda por telefono; lo
     * que se corta es la reserva desatendida, que es la que sale gratis.
     */
    private const MAX_INASISTENCIAS = 3;

    public function __construct(
        private readonly AvailabilityService $availability,
        private readonly BookingService $booking,
        private readonly ClientResolver $clients,
    ) {}

    /** Quien es el negocio, sus servicios y su equipo. */
    public function show(Business $business): JsonResponse
    {
        $this->assertOpenToPublic($business);

        return response()->json([
            'business' => [
                'name' => $business->name,
                'slug' => $business->slug,
                'timezone' => $business->businessTimezone(),
                'currency' => $business->currency,
                'address' => $business->address,
                'phone' => $business->phone,
                'logo_url' => ImageStorage::url($business->logo_path),
                'cover_url' => ImageStorage::url($business->cover_path),
            ],
            // Lo que el negocio dice de si mismo. Cuando llegue el constructor
            // de landings, sus bloques se suman aca sin tocar el resto.
            'profile' => PublicProfile::resolve($business),
            'services' => $this->services($business),
            'packages' => $this->packages($business),
            'resources' => $this->resources($business),
            'hours' => $this->hours($business),
        ]);
    }

    /**
     * Los combos que se pueden reservar por internet.
     *
     * Un combo solo sale si TODAS sus partes se ofrecen en linea. Si una no,
     * el combo llevaria a reservar por la pagina un servicio que el negocio
     * decidio no vender por ahi, y el descuento seria la puerta de atras.
     *
     * @return list<array<string, mixed>>
     */
    private function packages(Business $business): array
    {
        $bookables = collect($this->services($business))->pluck('id')->all();

        return \App\Models\ServicePackage::withoutGlobalScope('business')
            ->where('business_id', $business->id)
            ->where('is_active', true)
            ->where('is_bookable_online', true)
            ->with('services')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->filter(fn ($p) => $p->services->isNotEmpty()
                && $p->services->every(fn (Service $s) => in_array($s->id, $bookables, true)))
            ->map(fn ($p) => [
                'id' => $p->id,
                'name' => $p->name,
                'description' => $p->description,
                'image_url' => ImageStorage::url($p->image_path),
                'total_minutes' => $p->totalMinutes(),
                'services' => $p->services->map(fn (Service $s) => [
                    'id' => $s->id,
                    'name' => $s->name,
                    'duration_min' => $s->duration_min,
                    'price' => (float) $s->price,
                ])->values()->all(),
            ] + $p->quote())
            ->values()
            ->all();
    }

    /** Solo el catalogo, para quien ya esta en el paso de elegir. */
    public function services(Business $business): array
    {
        $this->assertOpenToPublic($business);

        return Service::withoutGlobalScope('business')
            ->where('business_id', $business->id)
            ->where('is_active', true)
            // Un servicio puede existir en el catalogo interno y no ofrecerse
            // por internet: los que se cotizan, los que exigen valoracion.
            ->where('is_bookable_online', true)
            ->with('resources')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(fn (Service $s) => [
                'id' => $s->id,
                'name' => $s->name,
                'description' => $s->description,
                'duration_min' => $s->duration_min,
                'price' => (float) $s->price,
                'image_url' => ImageStorage::url($s->image_path),
                // Con quien se puede: la pagina deja elegir profesional, y sin
                // esto ofreceria a alguien que no presta ese servicio.
                'resource_ids' => $s->resources
                    ->where('is_active', true)
                    ->where('is_bookable_online', true)
                    ->pluck('id')->values(),
            ])
            ->values()
            ->all();
    }

    /** Los huecos de un servicio en una fecha. */
    public function availability(Request $request, Business $business): JsonResponse
    {
        $this->assertOpenToPublic($business);

        $data = $request->validate([
            'service_id' => ['required', 'integer'],
            'date' => ['required', 'date_format:Y-m-d'],
            'resource_id' => ['nullable', 'integer'],
        ]);

        $service = $this->bookableService($business, $data['service_id']);
        $resource = isset($data['resource_id'])
            ? $this->bookableResource($business, $data['resource_id'])
            : null;

        $tz = $business->businessTimezone();

        $slots = $this->availability->slotsForService(
            $business,
            $service,
            CarbonImmutable::parse($data['date'], $tz),
            $resource,
        );

        return response()->json([
            'date' => $data['date'],
            'timezone' => $tz,
            'slots' => array_map(fn (array $slot) => [
                'resource_id' => $slot['resource_id'],
                'resource_name' => $slot['resource_name'],
                'starts_at' => $slot['starts_at']->toIso8601String(),
                'label' => $slot['starts_at']->format('g:i a'),
            ], $slots),
        ]);
    }

    /**
     * Donde cabe una visita de varios servicios, o un combo.
     *
     * La pagina publica necesita esto por la misma razon que la agenda de
     * adentro: encadenar `availability()` a mano da horas libres para el
     * primer servicio y ocupadas para el tercero. Y aca importa mas todavia,
     * porque no hay nadie del local mirando para corregirlo.
     */
    public function chain(Request $request, Business $business): JsonResponse
    {
        $this->assertOpenToPublic($business);

        $data = $request->validate([
            'service_ids' => ['required_without:package_id', 'array', 'min:1', 'max:10'],
            'service_ids.*' => ['integer'],
            'package_id' => ['nullable', 'integer'],
            'date' => ['required', 'date_format:Y-m-d'],
            // "Todo con la misma persona": preferencia, no filtro.
            'resource_id' => ['nullable', 'integer'],
        ]);

        $package = isset($data['package_id']) ? $this->bookablePackage($business, $data['package_id']) : null;

        // Aunque venga de un combo, cada servicio se revalida como reservable
        // en linea: un combo mal configurado no puede ser el atajo.
        $services = $package
            ? $package->services->map(fn (Service $s) => $this->bookableService($business, $s->id))->all()
            : array_values(array_filter(array_map(
                fn (int $id) => $this->bookableService($business, $id),
                $data['service_ids'],
            )));

        $resource = isset($data['resource_id'])
            ? $this->bookableResource($business, $data['resource_id'])
            : null;

        $tz = $business->businessTimezone();

        $slots = $this->availability->slotsForChain(
            $business,
            $services,
            CarbonImmutable::parse($data['date'], $tz),
            preferredResourceId: $resource?->id,
        );

        return response()->json([
            'date' => $data['date'],
            'timezone' => $tz,
            'total_minutes' => array_sum(array_map(fn (Service $s) => $s->occupiedMinutesFor(), $services)),
            'package' => $package ? ['id' => $package->id, 'name' => $package->name] + $package->quote() : null,
            'preferred_resource' => $resource ? ['id' => $resource->id, 'name' => $resource->name] : null,
            'slots' => array_map(fn (array $slot) => [
                'starts_at' => $slot['starts_at']->toIso8601String(),
                'label' => $slot['label'],
                'same_person' => $slot['same_person'],
                'preferred_honored' => $slot['preferred_honored'],
                'legs' => array_map(fn (array $leg) => [
                    'service_id' => $leg['service_id'],
                    'service_name' => $leg['service_name'],
                    'resource_id' => $leg['resource_id'],
                    'resource_name' => $leg['resource_name'],
                    'starts_at' => $leg['starts_at']->toIso8601String(),
                    'label' => $leg['starts_at']->format('g:i a'),
                    'changed_reason' => $leg['changed_reason'],
                ], $slot['legs']),
            ], $slots),
        ]);
    }

    /**
     * Que dias tienen algo libre, para no hacer tocar dia por dia.
     *
     * Devuelve solo si hay o no hay, no los huecos: calcular las horas de 60
     * dias para pintar un calendario es trabajo que nadie va a mirar.
     */
    public function days(Request $request, Business $business): JsonResponse
    {
        $this->assertOpenToPublic($business);

        $data = $request->validate([
            'service_id' => ['required', 'integer'],
            'from' => ['required', 'date_format:Y-m-d'],
            'resource_id' => ['nullable', 'integer'],
            // Cuantos dias mirar. El calendario pide el mes visible; la tira de
            // fichas rapidas se conforma con dos semanas.
            'days' => ['nullable', 'integer', 'min:1', 'max:42'],
        ]);

        $service = $this->bookableService($business, $data['service_id']);
        $resource = isset($data['resource_id'])
            ? $this->bookableResource($business, $data['resource_id'])
            : null;

        $tz = $business->businessTimezone();
        $from = CarbonImmutable::parse($data['from'], $tz)->startOfDay();
        $horizon = (int) $business->schedulingSetting('max_booking_horizon_days');

        $days = [];

        /*
         * Un mes cuesta menos de lo que parece: los dias pasados del horizonte
         * cortan por el `&&` sin calcular nada, y un dia sin horario devuelve
         * vacio de una. Lo que se paga es solo por dia laboral dentro del
         * horizonte.
         */
        for ($i = 0, $total = (int) ($data['days'] ?? 14); $i < $total; $i++) {
            $date = $from->addDays($i);

            $days[] = [
                'date' => $date->toDateString(),
                'has_slots' => $date->diffInDays(CarbonImmutable::now($tz)->startOfDay()) <= $horizon
                    && $this->availability->slotsForService($business, $service, $date, $resource) !== [],
            ];
        }

        return response()->json(['days' => $days]);
    }

    /** Reservar. Lo unico que la pagina publica puede escribir. */
    public function store(Request $request, Business $business): JsonResponse
    {
        $this->assertOpenToPublic($business);

        $data = $request->validate([
            // Un servicio suelto, o la cadena completa que devolvio `chain()`.
            'service_id' => ['required_without:items', 'integer'],
            'resource_id' => ['required_with:service_id', 'integer'],
            'starts_at' => ['required_with:service_id', 'date'],

            'items' => ['required_without:service_id', 'array', 'min:1', 'max:10'],
            'items.*.service_id' => ['required', 'integer'],
            'items.*.resource_id' => ['required', 'integer'],
            'items.*.starts_at' => ['required', 'date'],
            'service_package_id' => ['nullable', 'integer'],

            'client_name' => ['required', 'string', 'min:2', 'max:255'],
            'client_phone' => ['required', 'string', 'max:32'],
            'client_email' => ['required', 'email:rfc', 'max:255'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $tz = $business->businessTimezone();

        $crudos = $data['items'] ?? [[
            'service_id' => $data['service_id'],
            'resource_id' => $data['resource_id'],
            'starts_at' => $data['starts_at'],
        ]];

        /*
         * Cada linea se revalida contra el catalogo publico. La cadena la
         * calculo el servidor, pero vuelve por la red: nada impide reenviarla
         * con el id de un servicio que no se vende en linea.
         */
        $servicios = [];
        $recursos = [];

        foreach ($crudos as $crudo) {
            $servicios[] = $this->bookableService($business, (int) $crudo['service_id']);
            $recursos[] = $this->bookableResource($business, (int) $crudo['resource_id']);
        }

        $package = isset($data['service_package_id'])
            ? $this->bookablePackage($business, (int) $data['service_package_id'])
            : null;

        if ($package !== null) {
            $esperados = $package->services->pluck('id')->sort()->values()->all();
            $recibidos = collect($servicios)->pluck('id')->sort()->values()->all();

            // Sin esto se agenda el servicio mas barato marcado como el combo
            // y el cobro le aplica el descuento del combo entero.
            if ($esperados !== $recibidos) {
                return response()->json([
                    'message' => 'Los servicios no corresponden al combo elegido.',
                ], 422);
            }
        }

        $service = $servicios[0];
        $resource = $recursos[0];
        $phone = ChannelPhone::normalize($data['client_phone'], $business->country_code);

        if ($phone === null) {
            return response()->json([
                'message' => 'Revisa el número de teléfono.',
            ], 422);
        }

        if ($rechazo = $this->reasonToRefuse($business, $phone)) {
            return response()->json(['message' => $rechazo], 422);
        }

        $client = $this->clients->resolve(
            $business->id,
            null,
            $data['client_name'],
            $phone,
            $data['client_email'],
        );

        try {
            $appointment = $this->booking->book(
                $business,
                array_map(fn (array $crudo, int $i) => [
                    'service_id' => $servicios[$i]->id,
                    'resource_id' => $recursos[$i]->id,
                    // Sin desfase se interpreta en la zona del negocio, que es
                    // lo que manda la pagina.
                    'starts_at' => CarbonImmutable::parse($crudo['starts_at'], $tz),
                ], $crudos, array_keys($crudos)),
                $client,
                $data['client_name'],
                $phone,
                Appointment::SOURCE_ONLINE,
                $data['notes'] ?? null,
                package: $package,
            );
        } catch (SlotUnavailableException $e) {
            return response()->json([
                'message' => 'Ese horario se acaba de ocupar. Elige otro, por favor.',
            ], 409);
        } catch (OutsideWorkingHoursException $e) {
            return response()->json([
                'message' => 'Ese horario ya no está disponible. Elige otro, por favor.',
            ], 422);
        }

        $start = CarbonImmutable::parse($appointment->starts_at)->setTimezone($tz);

        /*
         * La respuesta NO devuelve la ficha del cliente, ni su id, ni su
         * historial. Si lo hiciera, cualquiera podria escribir el telefono de
         * otra persona y leerse su nombre y sus citas: un enumerador de
         * clientela servido por el propio formulario de reservas.
         */
        return response()->json([
            'reference' => $appointment->id,
            'status' => $appointment->status,
            'service' => $service->name,
            'resource' => $resource->name,
            'package' => $package?->name,
            // La confirmacion tiene que decir la visita COMPLETA: quien atiende
            // cada parte y a que hora. Es lo que la persona va a releer al
            // llegar, y un cambio de persona a mitad de visita sin avisar antes
            // es una discusion en el mostrador.
            'items' => array_map(fn (array $crudo, int $i) => [
                'service' => $servicios[$i]->name,
                'resource' => $recursos[$i]->name,
                'time_label' => CarbonImmutable::parse($crudo['starts_at'], $tz)->format('g:i a'),
            ], $crudos, array_keys($crudos)),
            'starts_at' => $start->toIso8601String(),
            'date_label' => $start->translatedFormat('l j \d\e F'),
            'time_label' => $start->format('g:i a'),
            'message' => 'Tu cita quedó registrada. Te esperamos.',
        ], 201);
    }

    /*
    |--------------------------------------------------------------------------
    | Internos
    |--------------------------------------------------------------------------
    */

    /**
     * 404 si el negocio no esta abierto al publico.
     *
     * 404 y no 403: que un negocio exista pero tenga la reserva apagada no es
     * asunto de quien pasa por la URL.
     */
    private function assertOpenToPublic(Business $business): void
    {
        abort_unless($business->is_active, 404);
        abort_unless($business->hasFeature('online_booking'), 404);
    }

    private function bookableService(Business $business, int $id): Service
    {
        return Service::withoutGlobalScope('business')
            ->where('business_id', $business->id)
            ->where('is_active', true)
            ->where('is_bookable_online', true)
            ->findOrFail($id);
    }

    /**
     * Un combo que de verdad se vende en linea.
     *
     * Se comprueba tambien que cada parte sea reservable: un combo activo con
     * un servicio que se saco de la venta online no puede colarlo de vuelta.
     */
    private function bookablePackage(Business $business, int $id): \App\Models\ServicePackage
    {
        $package = \App\Models\ServicePackage::withoutGlobalScope('business')
            ->where('business_id', $business->id)
            ->where('is_active', true)
            ->where('is_bookable_online', true)
            ->with('services')
            ->findOrFail($id);

        foreach ($package->services as $service) {
            $this->bookableService($business, $service->id);
        }

        return $package;
    }

    private function bookableResource(Business $business, int $id): Resource
    {
        return Resource::withoutGlobalScope('business')
            ->where('business_id', $business->id)
            ->where('is_active', true)
            ->where('is_bookable_online', true)
            ->findOrFail($id);
    }

    /**
     * Por que NO se le deja reservar, o null si si.
     *
     * Los mensajes mandan a llamar en vez de cerrar la puerta: el negocio
     * quiere a ese cliente, lo que no quiere es la reserva desatendida.
     */
    private function reasonToRefuse(Business $business, string $phone): ?string
    {
        $client = \App\Models\Client::withoutGlobalScope('business')
            ->where('business_id', $business->id)
            ->where('phone', $phone)
            ->first();

        $abiertas = Appointment::withoutGlobalScope('business')
            ->where('business_id', $business->id)
            ->where('client_phone', $phone)
            ->whereIn('status', [Appointment::STATUS_PENDING, Appointment::STATUS_CONFIRMED])
            ->where('starts_at', '>=', now())
            ->count();

        if ($abiertas >= self::MAX_CITAS_ABIERTAS) {
            return 'Ya tienes '.self::MAX_CITAS_ABIERTAS.' citas reservadas. Llámanos si necesitas otra.';
        }

        if ($client && $business->hasFeature('no_show_penalties')) {
            $faltas = ClientPenalty::withoutGlobalScope('business')
                ->where('business_id', $business->id)
                ->where('client_id', $client->id)
                ->where('kind', ClientPenalty::KIND_NO_SHOW)
                ->whereNull('waived_at')
                ->count();

            if ($faltas >= self::MAX_INASISTENCIAS) {
                return 'No podemos reservarte en línea. Llámanos y con gusto te agendamos.';
            }
        }

        return null;
    }

    /** El equipo que se puede elegir. */
    private function resources(Business $business): array
    {
        return Resource::withoutGlobalScope('business')
            ->where('business_id', $business->id)
            ->where('type', Resource::TYPE_STAFF)
            ->where('is_active', true)
            ->where('is_bookable_online', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(fn (Resource $r) => [
                'id' => $r->id,
                'name' => $r->name,
                'color' => $r->color,
                'photo_url' => ImageStorage::url($r->photo_path),
            ])
            ->values()
            ->all();
    }

    /**
     * A que hora abre y cierra el local cada dia.
     *
     * De la union de los horarios del equipo, no de un campo aparte: si
     * hubiera dos fuentes, la pagina diria que abren a las nueve el dia que
     * nadie entra hasta las diez.
     *
     * @return list<array{weekday:int, label:string, opens:?string, closes:?string, breaks:list<array{start:string,end:string,label:string}>}>
     */
    private function hours(Business $business): array
    {
        $schedules = \App\Models\ResourceSchedule::withoutGlobalScope('business')
            ->where('resource_schedules.business_id', $business->id)
            ->join('resources', 'resources.id', '=', 'resource_schedules.resource_id')
            ->where('resources.is_active', true)
            ->where('resources.is_bookable_online', true)
            ->get(['resource_schedules.weekday', 'resource_schedules.start_time', 'resource_schedules.end_time'])
            ->groupBy('weekday');

        // Solo los descansos de TODO el negocio: el almuerzo de una
        // profesional no es el horario del local.
        $breaks = ResourceBreak::withoutGlobalScope('business')
            ->where('business_id', $business->id)
            ->whereNull('resource_id')
            ->where('is_active', true)
            ->get()
            ->groupBy(fn (ResourceBreak $b) => $b->weekday ?? 0);

        $dias = ['', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo'];
        $result = [];

        foreach (range(1, 7) as $weekday) {
            $delDia = $schedules->get($weekday);

            $result[] = [
                'weekday' => $weekday,
                'label' => $dias[$weekday],
                'opens' => $delDia ? substr((string) $delDia->min('start_time'), 0, 5) : null,
                'closes' => $delDia ? substr((string) $delDia->max('end_time'), 0, 5) : null,
                'breaks' => collect($breaks->get($weekday, collect()))
                    ->merge($breaks->get(0, collect()))
                    ->map(fn (ResourceBreak $b) => [
                        'start' => substr((string) $b->start_time, 0, 5),
                        'end' => substr((string) $b->end_time, 0, 5),
                        'label' => $b->label,
                    ])->values()->all(),
            ];
        }

        return $result;
    }
}
