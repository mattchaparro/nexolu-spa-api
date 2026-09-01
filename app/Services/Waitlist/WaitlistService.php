<?php

namespace App\Services\Waitlist;

use App\Models\Appointment;
use App\Models\Business;
use App\Models\Client;
use App\Models\Message;
use App\Models\Resource;
use App\Models\Service;
use App\Models\WaitlistEntry;
use App\Services\Messaging\MessageDispatcher;
use App\Services\Scheduling\AvailabilityService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * La lista de espera: se libera un cupo, se avisa a todos los que encajan, y
 * se lo queda quien lo tome primero.
 *
 * TRES DECISIONES QUE SOSTIENEN TODO:
 *
 * 1. BROADCAST, no fila. El momento de la cancelacion es la hora de oro y
 *    serializar los avisos la desperdicia. El empate lo arbitra el indice
 *    unico de `resource_occupancy`, que ya existia: solo una reserva gana, no
 *    importa cuantas lo intenten. El mensaje es honesto -- "para quien lo tome
 *    primero" -- para que llegar tarde no sea una promesa rota.
 *
 * 2. EL AVISO NO PROMETE EL CUPO, abre la puerta. El enlace muestra los cupos
 *    que le sirven EN ESE MOMENTO, recalculados en vivo. Asi un aviso de hace
 *    una hora sigue siendo util aunque ese cupo ya se haya ido: quiza se
 *    libero otro.
 *
 * 3. LA CASCADA NO SE CONSTRUYE, EMERGE. Tomar un cupo teniendo ya una cita
 *    del mismo servicio MUEVE esa cita (no crea otra). Mover libera el hueco
 *    viejo, y liberar es el disparador de esta lista. Cada paso cierra una
 *    entrada, asi que la cadena no puede ciclar: esta acotada por cuantos
 *    esperan.
 */
class WaitlistService
{
    /**
     * Cuanto esperar entre avisos a la MISMA persona.
     *
     * Broadcast sin freno convierte una noche de tres cancelaciones en tres
     * mensajes a la misma clienta, y eso quema lo unico que esta lista tiene:
     * su disposicion a leer el siguiente. Como el enlace muestra los cupos
     * vigentes en vivo, el aviso viejo sigue sirviendo para el cupo nuevo.
     */
    private const COOLDOWN_MINUTES = 60;

    public function __construct(
        private readonly MessageDispatcher $dispatcher,
        private readonly AvailabilityService $availability,
    ) {}

    /**
     * Apuntarse: "avisame si se libera algo".
     *
     * Una entrada abierta por cliente y servicio, no una por intento. Quien
     * vuelve a apuntarse esta REFRESCANDO su interes -- otro rango, otra
     * franja -- no compitiendo consigo mismo: duplicar le duplicaria los
     * avisos.
     */
    public function register(
        Business $business,
        Client $client,
        Service $service,
        string $phone,
        CarbonImmutable $dateFrom,
        CarbonImmutable $dateTo,
        ?int $preferredResourceId = null,
        ?int $locationId = null,
        ?string $timeFrom = null,
        ?string $timeTo = null,
    ): WaitlistEntry {
        $attributes = [
            'phone' => $phone,
            'location_id' => $locationId,
            'preferred_resource_id' => $preferredResourceId,
            'date_from' => $dateFrom->toDateString(),
            'date_to' => $dateTo->toDateString(),
            'time_from' => $timeFrom,
            'time_to' => $timeTo,
            'status' => WaitlistEntry::STATUS_OPEN,
        ];

        $existing = WaitlistEntry::withoutGlobalScopes()
            ->where('business_id', $business->id)
            ->where('client_id', $client->id)
            ->where('service_id', $service->id)
            ->where('status', WaitlistEntry::STATUS_OPEN)
            ->first();

        if ($existing !== null) {
            $existing->update($attributes);

            return $existing->fresh();
        }

        $entry = new WaitlistEntry($attributes + [
            'business_id' => $business->id,
            'client_id' => $client->id,
            'service_id' => $service->id,
        ]);

        // `token` esta fuera de fillable a proposito -- es la llave de la
        // entrada y no puede entrar por un formulario. Se pone con forceFill,
        // igual que el del portal de citas.
        $entry->forceFill(['token' => Str::random(48)])->save();

        return $entry;
    }

    /**
     * Una cita solto su horario: cancelada, inasistencia, o movida.
     *
     * Es EL disparador. Se llama desde los tres puntos que borran ocupacion
     * (cancel, reschedule, release_slot) y no desde un cron: un cron llegaria
     * tarde a la hora de oro y gastaria consultas el 99% del tiempo en nada.
     */
    public function appointmentFreed(Appointment $appointment): void
    {
        $business = $appointment->business;

        if ($business === null || ! $business->hasFeature('reminders')) {
            // La lista de espera avisa por el mismo canal que los
            // recordatorios: sin esa funcion no hay como cumplir la promesa
            // de "te avisamos", asi que no se toma la promesa.
            return;
        }

        foreach ($appointment->items as $item) {
            if ($item->resource === null || $item->starts_at === null) {
                continue;
            }

            $this->slotFreed(
                $business,
                $item->resource,
                CarbonImmutable::parse($item->starts_at),
                // A quien NO avisarle: la persona que acaba de soltar el cupo.
                // "Se libero el cupo que acabas de cancelar" es una burla.
                $appointment->client_id,
            );
        }
    }

    /**
     * Avisa a todos los que encajan con un hueco recien liberado.
     */
    public function slotFreed(
        Business $business,
        Resource $resource,
        CarbonImmutable $slotStart,
        ?int $excludeClientId = null,
    ): int {
        if (! $business->hasFeature('reminders')) {
            // Mismo gate que en appointmentFreed, porque reschedule entra por
            // aca directo sin pasar por alla.
            return 0;
        }

        $tz = $business->businessTimezone();
        $local = $slotStart->setTimezone($tz);
        $notified = 0;

        foreach ($this->candidates($business, $resource, $local, $excludeClientId) as $entry) {
            /*
             * Se verifica contra la disponibilidad REAL antes de avisar. El
             * hueco pudo quedar dentro de un descanso, fuera del horario, o
             * ya reocupado en la misma rafaga. Avisar de un cupo que no se
             * puede tomar es peor que no avisar: gasta la confianza.
             */
            $slot = $this->matchingSlot($business, $entry, $resource, $local);

            if ($slot === null) {
                continue;
            }

            $message = $this->dispatcher->queue(
                $business,
                Message::KIND_WAITLIST,
                $entry->phone,
                $this->body($business, $entry, $slot),
                null,
                $entry->client,
            );

            if ($message !== null) {
                $entry->forceFill(['last_notified_at' => now()])->save();
                $notified++;
            }
        }

        return $notified;
    }

    /**
     * Alguien reservo: cerrar las entradas que esa reserva satisface.
     *
     * Tambien las ORGANICAS -- la clienta que volvio a mirar la pagina y
     * encontro cupo sola. Sin esto se le seguiria avisando de algo que ya
     * tiene, que es la forma mas rapida de que pida no recibir nada mas.
     */
    public function closeSatisfiedBy(Appointment $appointment): void
    {
        if ($appointment->client_id === null || $appointment->starts_at === null) {
            return;
        }

        $serviceIds = $appointment->items->pluck('service_id')->all();

        if ($serviceIds === []) {
            return;
        }

        $tz = $appointment->business?->businessTimezone() ?? config('spa.defaults.timezone');
        $fecha = CarbonImmutable::parse($appointment->starts_at)->setTimezone($tz)->toDateString();

        WaitlistEntry::withoutGlobalScopes()
            ->where('business_id', $appointment->business_id)
            ->where('client_id', $appointment->client_id)
            ->whereIn('service_id', $serviceIds)
            ->where('status', WaitlistEntry::STATUS_OPEN)
            ->whereDate('date_from', '<=', $fecha)
            ->whereDate('date_to', '>=', $fecha)
            ->update([
                'status' => WaitlistEntry::STATUS_FULFILLED,
                'fulfilled_appointment_id' => $appointment->id,
            ]);
    }

    /**
     * La entrada detras de un token, o null. Igual que el portal: sin sesion,
     * el token identifica solo.
     */
    public function resolve(?string $token): ?WaitlistEntry
    {
        if ($token === null || strlen($token) < 32) {
            return null;
        }

        return WaitlistEntry::withoutGlobalScopes()
            ->with(['client', 'service', 'preferredResource'])
            ->where('token', $token)
            ->first();
    }

    /**
     * Los cupos que le sirven a esta entrada AHORA, recalculados en vivo.
     *
     * Es lo que hace que un aviso viejo siga siendo util: la pagina no muestra
     * el cupo del mensaje, muestra lo vigente. Acotado a los primeros dias con
     * algo, porque una lista de 200 horas no ayuda a decidir.
     *
     * @return list<array{resource_id:int, resource_name:string, starts_at:CarbonImmutable, label:string, date_label:string}>
     */
    public function liveSlots(WaitlistEntry $entry, int $maxSlots = 24): array
    {
        $business = $entry->business;
        $tz = $business->businessTimezone();
        $hoy = CarbonImmutable::now($tz)->startOfDay();

        $desde = CarbonImmutable::parse($entry->date_from, $tz)->max($hoy);
        $hasta = CarbonImmutable::parse($entry->date_to, $tz);

        $result = [];

        for ($dia = $desde; $dia <= $hasta && count($result) < $maxSlots; $dia = $dia->addDay()) {
            $slots = $this->availability->slotsForService(
                $business,
                $entry->service,
                $dia,
                $entry->preferredResource,
                locationId: $entry->location_id,
            );

            foreach ($slots as $slot) {
                if (! $this->withinTimeWindow($entry, $slot['starts_at']->setTimezone($tz))) {
                    continue;
                }

                $local = $slot['starts_at']->setTimezone($tz);

                $result[] = [
                    'resource_id' => $slot['resource_id'],
                    'resource_name' => $slot['resource_name'],
                    'starts_at' => $slot['starts_at'],
                    'label' => $local->format('g:i a'),
                    'date_label' => $local->locale('es')->isoFormat('dddd D [de] MMMM'),
                ];

                if (count($result) >= $maxSlots) {
                    break;
                }
            }
        }

        return $result;
    }

    /**
     * Las entradas que podrian querer este hueco.
     *
     * @return Collection<int, WaitlistEntry>
     */
    private function candidates(
        Business $business,
        Resource $resource,
        CarbonImmutable $localStart,
        ?int $excludeClientId,
    ): Collection {
        $fecha = $localStart->toDateString();

        // Lo vencido se marca de paso, no en un cron aparte: es el unico
        // momento en que a alguien le importa el estado de estas filas.
        WaitlistEntry::withoutGlobalScopes()
            ->where('business_id', $business->id)
            ->where('status', WaitlistEntry::STATUS_OPEN)
            ->whereDate('date_to', '<', CarbonImmutable::now($business->businessTimezone())->toDateString())
            ->update(['status' => WaitlistEntry::STATUS_EXPIRED]);

        return WaitlistEntry::withoutGlobalScopes()
            ->with(['client', 'service', 'preferredResource'])
            ->where('business_id', $business->id)
            ->where('status', WaitlistEntry::STATUS_OPEN)
            ->whereDate('date_from', '<=', $fecha)
            ->whereDate('date_to', '>=', $fecha)
            // "Con Maria o con nadie": si pidio a alguien, el cupo de otra
            // persona no le sirve.
            ->where(fn ($q) => $q->whereNull('preferred_resource_id')
                ->orWhere('preferred_resource_id', $resource->id))
            // La sede del cupo es la del recurso que lo solto.
            ->where(fn ($q) => $q->whereNull('location_id')
                ->orWhere('location_id', $resource->location_id))
            ->when($excludeClientId !== null, fn ($q) => $q->where('client_id', '!=', $excludeClientId))
            /*
             * El freno de spam: quien recibio un aviso hace poco no recibe
             * otro. No pierde nada -- su enlace muestra los cupos vigentes en
             * vivo, incluido este.
             */
            ->where(fn ($q) => $q->whereNull('last_notified_at')
                ->orWhere('last_notified_at', '<=', now()->subMinutes(self::COOLDOWN_MINUTES)))
            ->get()
            ->filter(fn (WaitlistEntry $e) => $this->withinTimeWindow($e, $localStart))
            ->values();
    }

    /**
     * El primer cupo real que esta entrada puede tomar en ese dia/recurso.
     *
     * @return array{starts_at: CarbonImmutable, resource_name: string}|null
     */
    private function matchingSlot(
        Business $business,
        WaitlistEntry $entry,
        Resource $resource,
        CarbonImmutable $localStart,
    ): ?array {
        $tz = $business->businessTimezone();

        $slots = $this->availability->slotsForService(
            $business,
            $entry->service,
            $localStart->startOfDay(),
            $resource,
            locationId: $entry->location_id,
        );

        foreach ($slots as $slot) {
            $local = $slot['starts_at']->setTimezone($tz);

            if ($this->withinTimeWindow($entry, $local)) {
                return ['starts_at' => $slot['starts_at'], 'resource_name' => $slot['resource_name']];
            }
        }

        return null;
    }

    /** Si la hora cae en la franja que la persona dijo que le sirve. */
    private function withinTimeWindow(WaitlistEntry $entry, CarbonImmutable $localStart): bool
    {
        $hora = $localStart->format('H:i:s');

        if ($entry->time_from !== null && $hora < $entry->time_from) {
            return false;
        }

        if ($entry->time_to !== null && $hora > $entry->time_to) {
            return false;
        }

        return true;
    }

    /**
     * El mensaje del aviso.
     *
     * Concreto sobre el cupo que se libero -- "el sabado a las 10" vende mas
     * que "hay disponibilidad" -- y honesto sobre la regla: es para quien lo
     * tome primero. El enlace muestra lo vigente, asi que el mensaje sigue
     * sirviendo aunque ese cupo puntual ya se haya ido.
     */
    private function body(Business $business, WaitlistEntry $entry, array $slot): string
    {
        $tz = $business->businessTimezone();
        $local = $slot['starts_at']->setTimezone($tz);
        $nombre = trim(explode(' ', (string) $entry->client?->name)[0] ?? '');

        $link = rtrim((string) config('app.frontend_url', ''), '/')
            .'/cupo/'.$business->slug.'/'.$entry->token;

        return sprintf(
            '¡%s! Se liberó un cupo para %s: %s a las %s con %s. Es para quien lo tome primero: %s',
            $nombre !== '' ? "Hola {$nombre}" : 'Hola',
            $entry->service?->name ?? 'tu servicio',
            $local->locale('es')->isoFormat('dddd D [de] MMMM'),
            $local->format('g:i a'),
            $slot['resource_name'],
            $link,
        );
    }
}
