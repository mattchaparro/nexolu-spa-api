<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Appointment;
use App\Models\Business;
use App\Models\Location;
use App\Models\Resource;
use App\Models\Service;
use App\Models\WaitlistEntry;
use App\Services\ClientResolver;
use App\Services\Scheduling\BookingService;
use App\Services\Scheduling\Exceptions\OutsideWorkingHoursException;
use App\Services\Scheduling\Exceptions\SlotUnavailableException;
use App\Services\Waitlist\WaitlistService;
use App\Support\ChannelPhone;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * La lista de espera, del lado del cliente. SIN AUTENTICAR.
 *
 * Mismas cautelas que la pagina publica: el scope de negocio es inerte sin
 * sesion, cada consulta filtra a mano, y se entra por TOKEN — nunca por
 * telefono, que no es un secreto.
 *
 * La regla del juego es publica y honesta: el cupo es de quien lo tome
 * primero. El arbitro es el indice unico de `resource_occupancy` — si dos
 * personas tocan a la vez, una gana y la otra recibe un "ya se ocupo" que el
 * mensaje ya le habia advertido.
 */
class WaitlistController
{
    public function __construct(
        private readonly WaitlistService $waitlist,
        private readonly BookingService $booking,
        private readonly ClientResolver $clients,
    ) {}

    /**
     * "Avisame si se libera algo."
     *
     * Nace de la pantalla de reserva cuando no hay cupos: la persona ya
     * eligio servicio, fecha y quiza persona — esto solo pide su contacto.
     */
    public function store(Request $request, Business $business): JsonResponse
    {
        abort_unless($business->hasFeature('online_booking'), 404);

        if (! $business->hasFeature('reminders')) {
            // Sin canal de avisos no se toma la promesa de avisar.
            return response()->json([
                'message' => 'Este negocio no tiene avisos automáticos. Escríbele directamente.',
            ], 422);
        }

        $data = $request->validate([
            'service_id' => ['required', 'integer'],
            'resource_id' => ['nullable', 'integer'],
            'date_from' => ['required', 'date_format:Y-m-d'],
            'date_to' => ['required', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'time_from' => ['nullable', 'date_format:H:i'],
            'time_to' => ['nullable', 'date_format:H:i', 'after:time_from'],
            'location' => ['nullable', 'string', 'max:120'],
            'client_name' => ['required', 'string', 'min:2', 'max:255'],
            'client_phone' => ['required', 'string', 'max:32'],
        ]);

        $tz = $business->businessTimezone();
        $hasta = CarbonImmutable::parse($data['date_to'], $tz);

        // Un rango vencido o eterno no es una espera, es basura futura.
        if ($hasta->isPast()) {
            return response()->json(['message' => 'Ese rango de fechas ya pasó.'], 422);
        }

        if (CarbonImmutable::parse($data['date_from'], $tz)->diffInDays($hasta) > 60) {
            return response()->json(['message' => 'El rango no puede superar 60 días.'], 422);
        }

        $service = Service::withoutGlobalScopes()
            ->where('business_id', $business->id)
            ->where('is_active', true)
            ->where('is_bookable_online', true)
            ->findOrFail($data['service_id']);

        $resource = isset($data['resource_id'])
            ? Resource::withoutGlobalScopes()
                ->where('business_id', $business->id)
                ->where('is_active', true)
                ->findOrFail($data['resource_id'])
            : null;

        $phone = ChannelPhone::normalize($data['client_phone'], $business->country_code);

        if ($phone === null) {
            return response()->json(['message' => 'Revisa el número de teléfono.'], 422);
        }

        /*
         * Crea la ficha igual que una reserva: quien pide que le avisen es un
         * cliente interesado, no un formulario perdido. Es ademas lo que
         * permite el traslado despues — sin ficha no hay cita que mover.
         */
        $client = $this->clients->resolve($business->id, null, $data['client_name'], $phone);

        $sede = null;

        if (! empty($data['location'])) {
            $sede = Location::withoutGlobalScopes()
                ->where('business_id', $business->id)
                ->where('slug', $data['location'])
                ->where('is_active', true)
                ->first();
        }

        $this->waitlist->register(
            $business,
            $client,
            $service,
            $phone,
            CarbonImmutable::parse($data['date_from'], $tz),
            $hasta,
            $resource?->id,
            $sede?->id,
            isset($data['time_from']) ? $data['time_from'].':00' : null,
            isset($data['time_to']) ? $data['time_to'].':00' : null,
        );

        return response()->json([
            'message' => 'Listo. Si se libera un cupo que te sirva, te escribimos.',
        ], 201);
    }

    /**
     * Lo que la persona ve al abrir su enlace: los cupos que le sirven AHORA.
     *
     * En vivo, no el cupo del mensaje: un aviso de hace una hora sigue siendo
     * util aunque ese cupo puntual ya se haya ido — quiza se libero otro.
     */
    public function show(Business $business, string $token): JsonResponse
    {
        $entry = $this->resolveEntry($business, $token);

        $tz = $business->businessTimezone();
        $swap = $entry->status === WaitlistEntry::STATUS_OPEN
            ? $this->swappable($entry)
            : null;

        return response()->json([
            'business' => [
                'name' => $business->name,
                'slug' => $business->slug,
                'timezone' => $tz,
                'whatsapp' => $business->public_profile['whatsapp'] ?? null,
            ],
            'client_name' => $entry->client?->name,
            'service' => $entry->service?->name,
            'preferred_resource' => $entry->preferredResource?->name,
            'status' => $entry->status,
            'date_from' => $entry->date_from?->toDateString(),
            'date_to' => $entry->date_to?->toDateString(),
            'slots' => $entry->status === WaitlistEntry::STATUS_OPEN
                ? array_map(fn (array $s) => [
                    'resource_id' => $s['resource_id'],
                    'resource_name' => $s['resource_name'],
                    'starts_at' => $s['starts_at']->toIso8601String(),
                    'label' => $s['label'],
                    'date_label' => $s['date_label'],
                ], $this->waitlist->liveSlots($entry))
                : [],

            /*
             * La cita que se MOVERIA si toma un cupo, dicha ANTES de tocar.
             *
             * Es la parte diferenciadora: quien se conformo con el martes y ve
             * liberarse el sabado no recibe una segunda cita — se le traslada
             * la que tiene, con su precio y su abono intactos. Decirlo despues
             * de confirmar seria moverle una cita sin permiso.
             */
            'swaps' => $swap === null ? null : [
                'appointment_id' => $swap->id,
                'date_label' => $swap->starts_at?->setTimezone($tz)->locale('es')->isoFormat('dddd D [de] MMMM'),
                'time_label' => $swap->starts_at?->setTimezone($tz)->format('g:i a'),
            ],
        ]);
    }

    /** Tomar un cupo. El primero que llega, gana; el resto recibe la verdad. */
    public function take(Request $request, Business $business, string $token): JsonResponse
    {
        $entry = $this->resolveEntry($business, $token);

        if ($entry->status !== WaitlistEntry::STATUS_OPEN) {
            return response()->json(['message' => 'Esta espera ya no está activa.'], 422);
        }

        $data = $request->validate([
            'resource_id' => ['required', 'integer'],
            'starts_at' => ['required', 'date'],
        ]);

        $resource = Resource::withoutGlobalScopes()
            ->where('business_id', $business->id)
            ->where('is_active', true)
            ->findOrFail($data['resource_id']);

        $tz = $business->businessTimezone();
        $start = CarbonImmutable::parse($data['starts_at'])->setTimezone($tz);
        $swap = $this->swappable($entry);

        try {
            if ($swap !== null && $swap->items->first()?->resource?->location_id === $resource->location_id) {
                /*
                 * EL TRASLADO. Tenia una cita del mismo servicio: tomar el
                 * cupo la MUEVE en vez de duplicarla. Precio, comision y abono
                 * quedan donde estaban — reschedule ya lo garantiza — y el
                 * hueco viejo se libera, lo que dispara el aviso al siguiente
                 * de la lista. La cascada es exactamente esta linea.
                 */
                $appointment = $this->booking->reschedule($swap, $start, $resource);
            } else {
                $appointment = $this->booking->book(
                    $business,
                    [[
                        'service_id' => $entry->service_id,
                        'resource_id' => $resource->id,
                        'starts_at' => $start,
                    ]],
                    $entry->client,
                    $entry->client?->fullName(),
                    $entry->phone,
                    Appointment::SOURCE_ONLINE,
                );
            }
        } catch (SlotUnavailableException) {
            /*
             * Alguien llego primero. No es un error del sistema ni una promesa
             * rota: el mensaje decia "para quien lo tome primero". La espera
             * SIGUE ABIERTA — perder esta carrera no la gasta.
             */
            return response()->json([
                'message' => 'Ese cupo ya lo tomó alguien más. Sigues en la lista: te avisamos del próximo.',
            ], 409);
        } catch (OutsideWorkingHoursException|\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $entry->forceFill([
            'status' => WaitlistEntry::STATUS_FULFILLED,
            'fulfilled_appointment_id' => $appointment->id,
        ])->save();

        return response()->json([
            'message' => $swap !== null && $appointment->id === $swap->id
                ? '¡Listo! Tu cita quedó movida al nuevo horario.'
                : '¡Listo! Quedaste con el cupo.',
            'moved' => $swap !== null && $appointment->id === $swap->id,
            'date_label' => $appointment->starts_at?->setTimezone($tz)->locale('es')->isoFormat('dddd D [de] MMMM'),
            'time_label' => $appointment->starts_at?->setTimezone($tz)->format('g:i a'),
        ]);
    }

    /** "Ya no me avisen." Un opt-out que no funciona es spam con pasos extra. */
    public function stop(Business $business, string $token): JsonResponse
    {
        $entry = $this->resolveEntry($business, $token);

        if ($entry->status === WaitlistEntry::STATUS_OPEN) {
            $entry->forceFill(['status' => WaitlistEntry::STATUS_STOPPED])->save();
        }

        return response()->json(['message' => 'Listo, no te avisamos más.']);
    }

    private function resolveEntry(Business $business, string $token): WaitlistEntry
    {
        $entry = $this->waitlist->resolve($token);

        // Token invalido y token de otro negocio se ven igual: 404.
        abort_if($entry === null || $entry->business_id !== $business->id, 404);

        return $entry;
    }

    /**
     * La cita que se trasladaria en vez de duplicarse, si existe.
     *
     * Solo una FUTURA, del MISMO servicio, de UN solo servicio, y sin cobrar.
     * Una visita encadenada no se muta desde aca — moverla entera es otra
     * operacion — y una ya cobrada es historia contable, no un plan.
     */
    private function swappable(WaitlistEntry $entry): ?Appointment
    {
        if ($entry->client_id === null) {
            return null;
        }

        return Appointment::withoutGlobalScopes()
            ->where('business_id', $entry->business_id)
            ->where('client_id', $entry->client_id)
            ->where('starts_at', '>=', now())
            ->whereIn('status', [Appointment::STATUS_PENDING, Appointment::STATUS_CONFIRMED])
            ->whereNull('checked_out_at')
            ->whereHas('items', fn ($q) => $q->where('service_id', $entry->service_id))
            ->withCount('items')
            ->with('items.resource')
            ->orderBy('starts_at')
            ->get()
            ->first(fn (Appointment $a) => $a->items_count === 1);
    }
}
