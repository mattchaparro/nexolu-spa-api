<?php

namespace App\Ai\Capabilities;

use App\Ai\AiArgumentException;
use App\Ai\AiCaller;
use App\Ai\Capability;
use App\Ai\EsUnaPrueba;
use App\Ai\FechaDicha;
use App\Ai\HoraLegible;
use App\Ai\Resolves;
use App\Models\Appointment;
use App\Models\Business;
use App\Models\Client;
use App\Models\Location;
use App\Models\Service;
use App\Services\ClientResolver;
use App\Services\Scheduling\AvailabilityService;
use App\Services\Scheduling\BookingService;
use App\Services\Scheduling\CitasSimultaneas;
use App\Services\Scheduling\Exceptions\OutsideWorkingHoursException;
use App\Services\Scheduling\Exceptions\SlotUnavailableException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Agendar. La escritura que justifica todo el agente.
 *
 * Dos cosas que NO hace, y son las importantes:
 *
 * 1. No agenda a nombre de un tercero. Para una clienta, la ficha es la del
 *    telefono que escribio. Aunque el modelo mande otro nombre, la cita es
 *    suya: si no, basta escribirle al bot "agéndale a Carolina el sábado"
 *    para meterle citas falsas a la agenda de un local.
 *
 * 2. No reimplementa la reserva. Llama a `BookingService::book()`, que es
 *    quien reclama el horario contra el indice unico de `resource_occupancy`
 *    -- la garantia anti-solape del producto. Si el hueco se fue mientras la
 *    conversacion iba, esto devuelve un "ya no esta" y el agente ofrece otra
 *    hora, en vez de crear una cita encima de otra.
 */
class CreateAppointmentCapability implements Capability
{
    use Resolves;

    public function __construct(
        private readonly BookingService $booking,
        private readonly ClientResolver $clients,
        private readonly AvailabilityService $availability,
        private readonly CitasSimultaneas $simultaneas,
    ) {}

    public function requiredPermission(): ?string
    {
        return 'citas.crear';
    }

    public function requiredFeature(): ?string
    {
        return 'online_booking';
    }

    public function allowsCustomers(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'servicio' => ['required_without:servicios', 'string', 'max:255'],
            // Varios servicios = UNA cita encadenada ("manos y pies"), no
            // dos citas. El motor ya sabe hacerlo; antes el agente no podia
            // pedirlo y terminaba diciendo "el sistema no me deja".
            'servicios' => ['required_without:servicio', 'array', 'min:1', 'max:5'],
            'servicios.*' => ['required', 'string', 'max:255'],
            // Varias personas a la MISMA hora (ella y su hija), no una
            // sola pasando de un servicio al otro.
            'juntas' => ['nullable', 'boolean'],
            // Como se llama cada una, en el mismo orden que `servicios`.
            // Sin esto el local no sabe a quien va a atender en cada silla.
            'nombres' => ['nullable', 'array', 'max:5'],
            'nombres.*' => ['required', 'string', 'max:120'],
            // Texto: "el lunes" lo resuelve el codigo, no el modelo.
            'fecha' => ['required', 'string', 'max:40'],
            'hora' => ['required', 'date_format:H:i'],
            'empleado' => ['nullable', 'string', 'max:255'],
            'sede' => ['nullable', 'string', 'max:255'],
            // Solo se usa para NOMBRAR una ficha nueva. Nunca para elegir a
            // quien se le agenda: eso lo decide el telefono.
            'cliente' => ['nullable', 'string', 'max:255'],
            /*
             * Para quien es la visita, si no es para quien escribe.
             *
             * La cita SIGUE siendo del telefono -- ahi es donde llegan los
             * recordatorios y desde donde se puede cancelar -- pero el local
             * necesita saber a quien va a atender. Sin esto, la hija que
             * agenda para su mama aparece en la agenda como la mama, y quien
             * llega no es quien dice la ficha.
             */
            'para_quien' => ['nullable', 'string', 'max:120'],
        ];
    }

    public function execute(AiCaller $caller, array $arguments): array
    {
        $business = $caller->business;
        $tz = $business->businessTimezone();

        $nombres = $arguments['servicios'] ?? [$arguments['servicio']];
        $servicios = array_map(fn (string $n) => $this->resolveService($business->id, $n), $nombres);
        $sede = $this->resolveLocation($business->id, $arguments['sede'] ?? null);

        $preferida = isset($arguments['empleado'])
            ? $this->resolveResource($business->id, $arguments['empleado'], $sede?->id)
            : null;

        $dia = FechaDicha::resolver($arguments['fecha'], $tz);

        if ($dia === null) {
            return [
                'agendada' => false,
                'motivo' => "No entendí la fecha «{$arguments['fecha']}». Pregúntale qué día quiere.",
            ];
        }

        $inicio = $dia->setTimeFromTimeString($arguments['hora'].':00');

        if ((bool) ($arguments['juntas'] ?? false) && count($servicios) > 1) {
            return $this->citasSimultaneas($caller, $business, $servicios, $inicio, $sede?->id, $arguments);
        }

        /*
         * Una cadena de dos servicios no empieza los dos a la misma hora:
         * el segundo arranca cuando termina el primero. Quien sabe armar
         * eso -- con sus buffers y con quien esta libre -- es
         * AvailabilityService, el mismo motor que ofrecio la hora.
         */
        $items = count($servicios) === 1
            ? [[
                'service_id' => $servicios[0]->id,
                'resource_id' => ($preferida ?? $this->anyResourceFor($servicios[0]->id, $sede?->id))->id,
                'starts_at' => $inicio,
            ]]
            : $this->cadena($business, $servicios, $inicio, $preferida?->id, $sede?->id);

        if ($items === []) {
            return [
                'agendada' => false,
                'motivo' => 'A esa hora no alcanzan los dos servicios seguidos. Ofrécele otra hora.',
            ];
        }

        [$client, $nombre, $telefono] = $this->whoFor($caller, $arguments);

        try {
            $cita = $this->booking->book(
                $business,
                $items,
                $client,
                $nombre,
                $telefono,
                Appointment::SOURCE_WHATSAPP_AGENT,
                $this->notaDeTerceros($caller, $arguments),
            );
        } catch (SlotUnavailableException) {
            /*
             * No es un error: es informacion que el agente puede usar. Se
             * devuelve como dato para que ofrezca otra hora en vez de
             * disculparse con un fallo tecnico.
             */
            return [
                'agendada' => false,
                'motivo' => 'Esa hora ya se ocupó mientras conversábamos. Ofrece otra hora del mismo día.',
            ];
        } catch (OutsideWorkingHoursException|\DomainException $e) {
            return ['agendada' => false, 'motivo' => $e->getMessage()];
        }

        $cita->load(['items.resource', 'items.service']);

        return [
            'agendada' => true,
            'id' => $cita->id,
            'servicio' => collect($servicios)->pluck('name')->implode(' y '),
            'con' => $cita->items->map(fn ($i) => $i->resource?->name)->filter()->unique()->implode(' y '),
            'sede' => $this->variasSedes($business->id) ? $sede?->name : null,
            'fecha' => $cita->starts_at?->setTimezone($tz)->format('Y-m-d'),
            'hora' => HoraLegible::de($cita->starts_at, $tz),
            'hora_24' => $cita->starts_at?->setTimezone($tz)->format('H:i'),
            'precio' => collect($servicios)->sum(fn ($s) => (float) $s->price),
        ];
    }

    /**
     * Dos personas a la misma hora: dos citas, una por cada una.
     *
     * TODO O NADA. Si la segunda falla, la primera se deshace: dejar a la
     * mama agendada y a la hija afuera es peor que no agendar nada --
     * llegan las dos y solo cabe una.
     *
     * @param  list<Service>  $servicios
     */
    private function citasSimultaneas(
        AiCaller $caller,
        Business $business,
        array $servicios,
        CarbonImmutable $inicio,
        ?int $sedeId,
        array $arguments,
    ): array {
        $tz = $business->businessTimezone();

        $slot = collect($this->simultaneas->slots($business, $servicios, $inicio->startOfDay(), $sedeId))
            ->first(fn (array $s) => $s['starts_at']->equalTo($inicio));

        if ($slot === null) {
            return [
                'agendada' => false,
                'motivo' => 'A esa hora no hay suficientes profesionales libres al tiempo. '
                    .'Ofrécele otra hora.',
            ];
        }

        [$client, $nombre, $telefono] = $this->whoFor($caller, $arguments);
        $nombres = $arguments['nombres'] ?? [];

        $citas = DB::transaction(function () use ($business, $slot, $inicio, $client, $nombre, $telefono, $nombres, $caller, $arguments) {
            $creadas = [];

            foreach ($slot['asignacion'] as $indice => $parte) {
                /*
                 * Todas las citas quedan a nombre de QUIEN ESCRIBE: ahi
                 * llegan los recordatorios y desde ahi se pueden cancelar.
                 * A quien se atiende en cada silla va en la nota, que es
                 * lo que el local necesita saber.
                 */
                $paraQuien = $nombres[$indice] ?? null;

                $creadas[] = $this->booking->book(
                    $business,
                    [[
                        'service_id' => $parte['service_id'],
                        'resource_id' => $parte['resource_id'],
                        'starts_at' => $inicio,
                    ]],
                    $client,
                    $nombre,
                    $telefono,
                    Appointment::SOURCE_WHATSAPP_AGENT,
                    // El sello de evaluación también acá: si no, las citas
                    // de las pruebas de "ella y su hija" se quedan en la
                    // agenda del salón.
                    $this->conSello(
                        $caller,
                        $paraQuien === null
                            ? $this->notaDeTerceros($caller, $arguments)
                            : 'Para '.$paraQuien.' (agendó '.($nombre ?: 'quien escribe').').',
                    ),
                );
            }

            return $creadas;
        });

        return [
            'agendada' => true,
            'personas' => count($citas),
            'ids' => array_map(fn (Appointment $c) => $c->id, $citas),
            'fecha' => $inicio->format('Y-m-d'),
            'dia' => $inicio->locale('es')->isoFormat('dddd D [de] MMMM'),
            'hora' => HoraLegible::de($inicio, $tz),
            'detalle' => array_map(fn (array $p, int $i) => [
                'para' => $nombres[$i] ?? 'quien escribe',
                'servicio' => $p['service_name'],
                'con' => $p['resource_name'],
            ], $slot['asignacion'], array_keys($slot['asignacion'])),
            'precio' => collect($servicios)->sum(fn ($s) => (float) $s->price),
        ];
    }

    /**
     * Los tramos de una cita de varios servicios, en el orden en que se
     * hacen y con quien puede hacerlos.
     *
     * Sale del MISMO motor que ofrecio la hora (`slotsForChain`), asi que
     * lo que se reserva es exactamente lo que se prometio. Vacio = a esa
     * hora la cadena no cabe.
     *
     * @param  list<Service>  $servicios
     * @return list<array{service_id: int, resource_id: int, starts_at: CarbonImmutable}>
     */
    private function cadena(
        Business $business,
        array $servicios,
        CarbonImmutable $inicio,
        ?int $preferidaId,
        ?int $sedeId,
    ): array {
        $slots = $this->availability->slotsForChain(
            $business,
            $servicios,
            $inicio->startOfDay(),
            null,
            $preferidaId,
            $sedeId,
        );

        foreach ($slots as $slot) {
            if ($slot['starts_at']->equalTo($inicio)) {
                return array_map(fn (array $leg) => [
                    'service_id' => $leg['service_id'],
                    'resource_id' => $leg['resource_id'],
                    'starts_at' => $leg['starts_at'],
                ], $slot['legs']);
            }
        }

        return [];
    }

    private function variasSedes(int $businessId): bool
    {
        return Location::withoutGlobalScope('business')
            ->where('business_id', $businessId)
            ->where('is_active', true)
            ->count() > 1;
    }

    /** Si la visita es para otra persona, el local tiene que saberlo. */
    private function notaDeTerceros(AiCaller $caller, array $arguments): ?string
    {
        $otra = trim((string) ($arguments['para_quien'] ?? ''));

        return $this->conSello(
            $caller,
            $otra === '' || $caller->isStaff()
                ? null
                : 'La cita es para '.$otra.'. Agendó '
                    .($caller->client?->fullName() ?? $caller->phone).' por WhatsApp.',
        );
    }

    /**
     * La nota, más la marca si esta conversación es una evaluación.
     *
     * `ia:evaluar` conversa con un teléfono de verdad -- el de quien
     * mantiene esto -- y después borra lo que el bot dejó. Borrar "todas
     * las citas de esa ficha creadas en los últimos segundos" casi nunca
     * se equivoca, y "casi nunca" no alcanza cuando lo que está en juego
     * es la cita de alguien. Con la marca solo se borra lo que nació de
     * la evaluación.
     */
    private function conSello(AiCaller $caller, ?string $nota): ?string
    {
        if (! EsUnaPrueba::si((string) $caller->phone)) {
            return $nota;
        }

        return trim(($nota ?? '').' '.EsUnaPrueba::SELLO);
    }

    /**
     * A nombre de quien va la cita.
     *
     * @return array{0: ?Client, 1: ?string, 2: ?string}
     */
    private function whoFor(AiCaller $caller, array $arguments): array
    {
        if ($caller->isStaff()) {
            // Desde el panel si tiene sentido agendarle a alguien mas: quien
            // lo pide es una empleada del negocio, con permiso `citas.crear`.
            $nombre = trim((string) ($arguments['cliente'] ?? ''));

            if ($nombre === '') {
                throw new AiArgumentException('Falta el nombre de la clienta.');
            }

            return [null, $nombre, null];
        }

        // Clienta: su ficha, o una nueva con su telefono. El nombre del
        // argumento solo sirve para estrenarla.
        if ($caller->client !== null) {
            return [$caller->client, $caller->client->fullName(), $caller->phone];
        }

        $nombre = trim((string) ($arguments['cliente'] ?? ''));

        if ($nombre === '') {
            throw new AiArgumentException('Pregúntale su nombre antes de agendar.');
        }

        $ficha = $this->clients->resolve($caller->business->id, null, $nombre, $caller->phone);

        return [$ficha, $nombre, $caller->phone];
    }

    /** La primera persona activa que presta ese servicio en esa sede. */
    private function anyResourceFor(int $serviceId, ?int $locationId): \App\Models\Resource
    {
        $recurso = \App\Models\Resource::withoutGlobalScope('business')
            ->where('type', \App\Models\Resource::TYPE_STAFF)
            ->where('is_active', true)
            ->where('is_bookable_online', true)
            ->when($locationId !== null, fn ($q) => $q->where('location_id', $locationId))
            ->whereHas('services', fn ($q) => $q->where('services.id', $serviceId))
            ->orderBy('sort_order')
            ->first();

        if ($recurso === null) {
            throw new AiArgumentException('Nadie presta ese servicio en esa sede.');
        }

        return $recurso;
    }
}
