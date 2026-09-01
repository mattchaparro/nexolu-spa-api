<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Appointment;
use App\Models\Business;
use App\Models\Client;
use App\Services\ClientPortalService;
use App\Services\Scheduling\AvailabilityService;
use App\Services\Scheduling\BookingService;
use App\Services\Scheduling\Exceptions\OutsideWorkingHoursException;
use App\Services\Scheduling\Exceptions\SlotUnavailableException;
use App\Support\ImageStorage;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "Mis citas": lo que un cliente hace con las suyas, sin cuenta.
 *
 * SIN AUTENTICAR, igual que la pagina publica, y con las mismas cautelas: el
 * scope global de negocio es inerte sin sesion, asi que cada consulta lleva su
 * `where('business_id', ...)` a mano.
 *
 * Se entra por un TOKEN, nunca por telefono. Un telefono no es un secreto --
 * esta en la vitrina del local, en Instagram, en un grupo de WhatsApp -- y
 * dejar consultar por el convierte la pantalla en un directorio: se prueban
 * numeros y salen nombres, servicios y horarios de clientas ajenas. Es
 * literalmente lo que hacia `/api/external/*` en Blue Souls.
 *
 * Lo que se puede hacer es deliberadamente poco: ver las proximas, MOVER LA
 * HORA, y cancelar. No cambiar de persona, ni de servicio, ni de sede -- eso es
 * reservar de nuevo, y para eso ya esta la pagina publica entera.
 */
class ClientPortalController
{
    public function __construct(
        private readonly ClientPortalService $portal,
        private readonly AvailabilityService $availability,
        private readonly BookingService $booking,
    ) {}

    /**
     * Un token invalido y un token de otro negocio se ven igual: 404.
     *
     * @return array{0: Client, 1: Business}
     */
    private function resolve(Business $business, string $token): array
    {
        $client = $this->portal->resolve($token);

        abort_if($client === null || $client->business_id !== $business->id, 404);

        return [$client, $business];
    }

    /** Sus proximas citas. */
    public function show(Business $business, string $token): JsonResponse
    {
        [$client] = $this->resolve($business, $token);

        $tz = $business->businessTimezone();

        return response()->json([
            'business' => [
                'name' => $business->name,
                'slug' => $business->slug,
                'timezone' => $tz,
                'logo_url' => ImageStorage::url($business->logo_path),
                'whatsapp' => $business->public_profile['whatsapp'] ?? null,
            ],

            /*
             * Con que prellenar el formulario si decide reservar otra cosa.
             *
             * Es la unica respuesta honesta a "que el enlace traiga su
             * numero": el navegador no lo sabe y no hay forma de que lo sepa.
             * Lo sabe el negocio, porque ya lo tenia en su ficha, y viaja
             * porque el token dice de quien es esa ficha.
             */
            'client' => [
                'name' => $client->fullName(),
                'phone' => $client->phone,
                'email' => $client->email,
            ],

            'appointments' => $this->portal->upcoming($client, $business)
                ->map(fn (Appointment $a) => [
                    'id' => $a->id,
                    'starts_at' => $a->starts_at?->setTimezone($tz)->toIso8601String(),
                    'date_label' => $a->starts_at?->setTimezone($tz)->locale('es')->isoFormat('dddd D [de] MMMM'),
                    'time_label' => $a->starts_at?->setTimezone($tz)->format('g:i a'),
                    'status' => $a->status,
                    'location' => $a->location?->name,
                    'location_address' => $a->location?->address,
                    'maps_url' => $a->location?->maps_url,
                    'items' => $a->items->map(fn ($i) => [
                        'service' => $i->service?->name,
                        'resource' => $i->resource?->name,
                    ])->values(),
                    // Si se puede tocar, y si no, por que. La pantalla no
                    // tiene que reimplementar la regla del preaviso.
                    'can_change' => $this->portal->canBeChanged($a, $business),
                    'refusal' => $this->portal->reasonToRefuse($a, $business),
                ])->values(),
        ]);
    }

    /**
     * Las horas a las que podria moverla, EL MISMO servicio con LA MISMA
     * persona.
     *
     * Se consulta con `$onlyResource` puesto para que no aparezcan huecos de
     * otra profesional: la clienta reservo con quien reservo, y ofrecerle otra
     * cara sin decirlo es cambiarle la cita, no moverla.
     */
    public function slots(Request $request, Business $business, string $token, Appointment $appointment): JsonResponse
    {
        [$client] = $this->resolve($business, $token);

        abort_if($appointment->client_id !== $client->id, 404);
        abort_if($appointment->business_id !== $business->id, 404);

        $data = $request->validate(['date' => ['required', 'date_format:Y-m-d']]);

        $item = $appointment->items()->with(['service', 'resource'])->orderBy('sort_order')->first();

        if ($item === null || $appointment->items()->count() > 1) {
            /*
             * Una visita de varios servicios no se mueve desde aca.
             *
             * Cada eslabon puede ser de una persona distinta y moverla entera
             * es reencajar la cadena completa: si no cabe, la respuesta seria
             * un error que la clienta no puede resolver sola. Se le dice que
             * escriba, que es lo que iba a terminar haciendo igual.
             */
            return response()->json([
                'message' => 'Esta visita tiene varios servicios. Escríbenos y la movemos contigo.',
                'slots' => [],
            ]);
        }

        $tz = $business->businessTimezone();

        $slots = $this->availability->slotsForService(
            $business,
            $item->service,
            CarbonImmutable::parse($data['date'], $tz),
            $item->resource,
            locationId: $appointment->location_id,
        );

        return response()->json([
            'date' => $data['date'],
            'resource_name' => $item->resource?->name,
            'slots' => array_map(fn (array $slot) => [
                'starts_at' => $slot['starts_at']->setTimezone($tz)->toIso8601String(),
                'label' => $slot['starts_at']->setTimezone($tz)->format('g:i a'),
            ], $slots),
        ]);
    }

    /** Mover la hora. Nada mas: ni persona, ni servicio, ni sede. */
    public function reschedule(Request $request, Business $business, string $token, Appointment $appointment): JsonResponse
    {
        [$client] = $this->resolve($business, $token);

        abort_if($appointment->client_id !== $client->id, 404);
        abort_if($appointment->business_id !== $business->id, 404);

        if ($motivo = $this->portal->reasonToRefuse($appointment, $business)) {
            return response()->json(['message' => $motivo], 422);
        }

        $data = $request->validate(['starts_at' => ['required', 'date']]);

        try {
            $moved = $this->booking->reschedule(
                $appointment,
                CarbonImmutable::parse($data['starts_at'])->setTimezone($business->businessTimezone()),
            );
        } catch (SlotUnavailableException) {
            // Alguien tomo ese hueco entre que se pinto la pantalla y se toco
            // el boton. No es un error de la clienta: se le dice y se recarga.
            return response()->json([
                'message' => 'Esa hora se acaba de ocupar. Elige otra, por favor.',
            ], 409);
        } catch (OutsideWorkingHoursException|\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $tz = $business->businessTimezone();

        return response()->json([
            'id' => $moved->id,
            'date_label' => $moved->starts_at?->setTimezone($tz)->locale('es')->isoFormat('dddd D [de] MMMM'),
            'time_label' => $moved->starts_at?->setTimezone($tz)->format('g:i a'),
            'message' => 'Listo, tu cita quedó movida.',
        ]);
    }

    public function cancel(Request $request, Business $business, string $token, Appointment $appointment): JsonResponse
    {
        [$client] = $this->resolve($business, $token);

        abort_if($appointment->client_id !== $client->id, 404);
        abort_if($appointment->business_id !== $business->id, 404);

        if ($motivo = $this->portal->reasonToRefuse($appointment, $business)) {
            return response()->json(['message' => $motivo], 422);
        }

        $data = $request->validate(['reason' => ['nullable', 'string', 'max:300']]);

        /*
         * Sin `byUserId`: no la cancelo nadie del equipo, la cancelo la
         * clienta. Guardarlo como si fuera una decision del local haria que la
         * profesional cargue con una cancelacion que no hizo.
         */
        $this->booking->cancel($appointment, null, $data['reason'] ?? 'Cancelada por el cliente');

        return response()->json(['message' => 'Tu cita quedó cancelada.']);
    }
}
