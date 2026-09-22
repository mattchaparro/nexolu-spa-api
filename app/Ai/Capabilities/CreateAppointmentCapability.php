<?php

namespace App\Ai\Capabilities;

use App\Ai\AiArgumentException;
use App\Ai\AiCaller;
use App\Ai\Capability;
use App\Ai\EnvioDirecto;
use App\Ai\EsUnaPrueba;
use App\Ai\FechaDicha;
use App\Ai\HoraLegible;
use App\Ai\InfoPostCita;
use App\Ai\Resolves;
use App\Ai\UltimoPedido;
use App\Models\Appointment;
use App\Models\Business;
use App\Models\Client;
use App\Models\Location;
use App\Models\Service;
use App\Services\ClientPortalService;
use App\Services\ClientResolver;
use App\Services\Scheduling\AvailabilityService;
use App\Services\Scheduling\BookingService;
use App\Services\Scheduling\CitasSimultaneas;
use App\Services\Scheduling\Exceptions\OutsideWorkingHoursException;
use App\Services\Scheduling\Exceptions\SlotUnavailableException;
use App\Support\ChannelPhone;
use App\Support\PublicProfile;
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
        private readonly ClientPortalService $portal,
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
            // Opcionales: lo que no venga se completa con lo ultimo que pidio
            // (UltimoPedido). Tocar "6 pm" despues de ver las horas tiene que
            // alcanzar para agendar sin que nadie repita el servicio ni el dia.
            'servicio' => ['nullable', 'string', 'max:255'],
            // Varios servicios = UNA cita encadenada ("manos y pies"), no
            // dos citas. El motor ya sabe hacerlo; antes el agente no podia
            // pedirlo y terminaba diciendo "el sistema no me deja".
            'servicios' => ['nullable', 'array', 'min:1', 'max:5'],
            'servicios.*' => ['required', 'string', 'max:255'],
            // Varias personas a la MISMA hora (ella y su hija), no una
            // sola pasando de un servicio al otro.
            'juntas' => ['nullable', 'boolean'],
            // Como se llama cada una, en el mismo orden que `servicios`.
            // Sin esto el local no sabe a quien va a atender en cada silla.
            'nombres' => ['nullable', 'array', 'max:5'],
            'nombres.*' => ['required', 'string', 'max:120'],
            // Texto: "el lunes" lo resuelve el codigo, no el modelo.
            'fecha' => ['nullable', 'string', 'max:40'],
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
            /*
             * "Sí, quiero OTRA cita aparte de la que ya tengo". Sin esto,
             * pedir el mismo servicio teniendo una cita en pie no agenda:
             * devuelve la cita existente para preguntar si la mueve o si
             * de verdad quiere dos.
             */
            'otra_mas' => ['nullable', 'boolean'],
        ];
    }

    public function execute(AiCaller $caller, array $arguments): array
    {
        $business = $caller->business;
        $tz = $business->businessTimezone();

        $phoneCtx = ChannelPhone::normalize((string) $caller->phone, $business->country_code ?? 'CO');

        if ($phoneCtx !== null) {
            $arguments = UltimoPedido::completar($phoneCtx, $arguments);
        }

        if (empty($arguments['servicio']) && empty($arguments['servicios'])) {
            return ['agendada' => false, 'motivo' => 'No sé qué servicio agendar. Pregúntale y vuelve a llamarme.'];
        }

        if (! isset($arguments['fecha'])) {
            return ['agendada' => false, 'motivo' => 'No sé para qué día. Pregúntale y vuelve a llamarme.'];
        }

        $nombres = $arguments['servicios'] ?? [$arguments['servicio']];
        // Igual que al mirar horas: con varios servicios, lo ambiguo se
        // resuelve al mas probable. Si aca se resolviera distinto, la
        // clienta veria las horas de unos servicios y quedaria agendada
        // en otros.
        [$servicios] = $this->resolveServices($business->id, $nombres);
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

        $repetida = $this->citaRepetida($caller, $servicios, $inicio, $arguments);

        if ($repetida !== null) {
            return $repetida;
        }

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
                'resource_id' => ($preferida ?? $this->quienEstaLibre($business, $servicios[0], $inicio, $sede?->id))->id,
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

        /*
         * La confirmacion la manda ESTA herramienta, no el modelo.
         *
         * La noche del 21 el modelo agendo bien y respondio con texto
         * vacio: la clienta quedo con una cita que no sabia que tenia.
         * "Tu cita quedo agendada" es demasiado importante para depender
         * de que el modelo decida redactarlo.
         */
        $confirmada = $this->confirmarALaClienta($caller, $cita);

        return [
            'confirmacion_enviada' => $confirmada,
            'instruccion' => $confirmada
                ? 'La confirmación YA le llegó a la clienta por WhatsApp. NO la repitas: responde con una cadena vacía.'
                : 'Confírmale en una frase: servicio, día, hora y con quién.',
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

        $detalle = array_map(fn (array $p, int $i) => [
            'para' => $nombres[$i] ?? 'quien escribe',
            'servicio' => $p['service_name'],
            'con' => $p['resource_name'],
        ], $slot['asignacion'], array_keys($slot['asignacion']));

        /*
         * La confirmacion tambien aca. La clienta simulada que vino con la
         * hija toco "Si, agendar", las DOS citas quedaron... y el bot se
         * quedo callado: solo la ruta de una cita mandaba confirmacion.
         */
        $confirmada = $this->confirmarJuntas($caller, $inicio, $detalle);

        return [
            'confirmacion_enviada' => $confirmada,
            'instruccion' => $confirmada
                ? 'La confirmación YA le llegó a la clienta por WhatsApp. NO la repitas: responde con una cadena vacía.'
                : 'Confírmale en una frase: quiénes, servicio, día, hora y con quién cada una.',
            'agendada' => true,
            'personas' => count($citas),
            'ids' => array_map(fn (Appointment $c) => $c->id, $citas),
            'fecha' => $inicio->format('Y-m-d'),
            'dia' => $inicio->locale('es')->isoFormat('dddd D [de] MMMM'),
            'hora' => HoraLegible::de($inicio, $tz),
            'detalle' => $detalle,
            'precio' => collect($servicios)->sum(fn ($s) => (float) $s->price),
        ];
    }

    /**
     * "¡Listo! Quedaron agendadas…", una línea por persona.
     *
     * @param  list<array{para: string, servicio: string, con: string}>  $detalle
     */
    private function confirmarJuntas(AiCaller $caller, CarbonImmutable $inicio, array $detalle): bool
    {
        if (! $caller->isCustomer() || $caller->channel !== 'whatsapp') {
            return false;
        }

        $tz = $caller->business->businessTimezone();
        $lineas = array_map(
            fn (array $p) => sprintf('• *%s* para %s, con *%s*', $p['servicio'], $p['para'], $p['con']),
            $detalle,
        );

        return app(EnvioDirecto::class)->texto($caller, sprintf(
            "¡Listo! Quedaron agendadas para el *%s* a las *%s*:\n%s\nLas esperamos 💅",
            $inicio->setTimezone($tz)->locale('es')->isoFormat('dddd D [de] MMMM'),
            HoraLegible::de($inicio, $tz),
            implode("\n", $lineas),
        ));
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

    /**
     * ¿No será la MISMA cita que ya tiene?
     *
     * Laura pidió mover su cita del jueves al viernes; el modelo, en vez
     * de llamar a `reagendar_cita`, creó una nueva -- y Laura quedó con
     * DOS citas en la agenda del salón. Nadie que ya tiene una cita del
     * mismo servicio pide otra "así porque sí": casi siempre la está
     * moviendo. Pero "casi siempre" no alcanza para decidir por ella (la
     * del viernes puede ser para la otra semana, además de la que tiene),
     * así que esto no agenda ni mueve: devuelve la cita existente y quien
     * llama pregunta. `otra_mas` es la respuesta "sí, quiero las dos".
     *
     * @param  list<Service>  $servicios
     * @return array<string, mixed>|null null = vía libre para agendar
     */
    private function citaRepetida(AiCaller $caller, array $servicios, CarbonImmutable $inicio, array $arguments): ?array
    {
        if (
            ! $caller->isCustomer()
            || $caller->client === null
            || (bool) ($arguments['otra_mas'] ?? false)
            || (bool) ($arguments['juntas'] ?? false)
            // Para otra persona sí puede haber dos: la suya y la de la mamá.
            || trim((string) ($arguments['para_quien'] ?? '')) !== ''
        ) {
            return null;
        }

        $ids = collect($servicios)->pluck('id');
        $tz = $caller->business->businessTimezone();

        /*
         * Cuenta como "la misma": mismo servicio en cualquier fecha, o
         * CUALQUIER servicio el mismo día. Laura pidió mover su cita de
         * Arabe/4D; el modelo consultó horas de otro servicio y, como no
         * coincidía el nombre, la guarda no saltó -- quedó con la de las
         * 10 am y una nueva a las 5 pm el mismo martes. Dos visitas el
         * mismo día casi siempre son una mudanza a medio hacer.
         */
        $cita = $this->portal->upcoming($caller->client, $caller->business)
            ->first(fn (Appointment $c) => $c->items->pluck('service_id')->intersect($ids)->isNotEmpty()
                || $c->starts_at->setTimezone($tz)->isSameDay($inicio));

        if ($cita === null) {
            return null;
        }
        $cuando = [
            'id' => $cita->id,
            'servicio' => $cita->items->map(fn ($i) => $i->service?->name)->filter()->unique()->implode(' y '),
            'fecha' => $cita->starts_at->setTimezone($tz)->format('Y-m-d'),
            'dia' => $cita->starts_at->setTimezone($tz)->locale('es')->isoFormat('dddd D [de] MMMM'),
            'hora' => HoraLegible::de($cita->starts_at, $tz),
        ];

        if ($cita->starts_at->equalTo($inicio)) {
            return [
                'agendada' => false,
                'ya_existia' => true,
                'cita' => $cuando,
                'motivo' => 'Esa cita YA está agendada tal cual. Dile que ya la tiene y no agendes otra.',
            ];
        }

        return [
            'agendada' => false,
            'ya_tiene_cita' => $cuando,
            'motivo' => sprintf(
                'OJO: ya tiene una cita de %s el %s a las %s. Pregúntale si quiere MOVER esa cita '
                .'a la fecha nueva (entonces llama a reagendar_cita con cita_id=%d) o si quiere una '
                .'cita ADICIONAL (entonces repite crear_cita con otra_mas=true). No agendes nada '
                .'hasta que responda.',
                $cuando['servicio'],
                $cuando['dia'],
                $cuando['hora'],
                $cita->id,
            ),
        ];
    }

    private function variasSedes(int $businessId): bool
    {
        return Location::withoutGlobalScope('business')
            ->where('business_id', $businessId)
            ->where('is_active', true)
            ->count() > 1;
    }

    /** Si la visita es para otra persona, el local tiene que saberlo. */
    /**
     * "¡Listo! Quedó agendada…", directo por el canal.
     */
    private function confirmarALaClienta(AiCaller $caller, Appointment $cita): bool
    {
        if (! $caller->isCustomer() || $caller->channel !== 'whatsapp') {
            return false;
        }

        $tz = $caller->business->businessTimezone();
        $servicios = $cita->items->map(fn ($i) => $i->service?->name)->filter()->unique()->values();
        $con = $cita->items->map(fn ($i) => $i->resource?->name)->filter()->unique()->values();
        $precio = (float) $cita->items->sum(fn ($i) => (float) ($i->price ?? $i->service?->price ?? 0));

        /*
         * La confirmación, con TODO lo que la clienta necesita para no
         * volver a preguntar: día, hora, servicio, precio y quién la
         * atiende. Es el formato que Luxury ya usa en ManyChat y que sus
         * clientas reconocen (lo trajo Alejandro).
         */
        $lineas = [
            '¡Tu cita quedó confirmada! ✅',
            '',
            '📅 Día: *'.ucfirst($cita->starts_at->setTimezone($tz)->locale('es')->isoFormat('dddd D [de] MMMM')).'*',
            '⏰ Hora: *'.HoraLegible::de($cita->starts_at, $tz).'*',
            '💅 Servicio: *'.$servicios->implode(' y ').'*',
        ];

        if ($precio > 0) {
            $lineas[] = '💵 Precio: *$'.number_format($precio, 0, ',', '.').'*';
        }

        if ($con->isNotEmpty()) {
            $lineas[] = '🙋‍♀️ Te atiende: *'.$con->implode(' y ').'*';
        }

        $lineas[] = '';
        $lineas[] = 'Gracias por agendar en *'.$caller->business->name.'* 🌟';

        $instagram = PublicProfile::resolve($caller->business)['instagram'] ?? null;

        if (! empty($instagram)) {
            $lineas[] = 'Síguenos y entérate de nuestras promociones 👉 '.$instagram;
        }

        $enviada = app(EnvioDirecto::class)->texto($caller, implode("\n", $lineas));

        /*
         * Y, aparte, lo que el negocio tenga escrito para después de la
         * cita (garantías, recomendaciones, cancelaciones). Si no ha
         * escrito nada, no se ofrece nada.
         */
        if ($enviada) {
            $phone = ChannelPhone::normalize((string) $caller->phone, $caller->business->country_code ?? 'CO');

            if ($phone !== null) {
                app(InfoPostCita::class)->ofrecer($caller, $phone);
            }
        }

        return $enviada;
    }

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
    /**
     * Quien de verdad esta libre a esa hora.
     *
     * La agenda ofrecio "2:30 pm con Anyi" y, al reservar sin decir con
     * quien, se tomaba a la PRIMERA profesional del servicio -- Alejandra,
     * que a esa hora no trabaja -- y la reserva fallaba. La clienta tocaba
     * "Si, agendar" y no quedaba nada. Se pregunta a la misma agenda que
     * ofrecio la hora quien la tiene libre; solo si nadie aparece se cae a
     * la primera, para que el error que salga sea el de la reserva y no
     * uno inventado aca.
     */
    private function quienEstaLibre(Business $business, Service $servicio, CarbonImmutable $inicio, ?int $sedeId): \App\Models\Resource
    {
        $libres = $this->availability->slotsForService($business, $servicio, $inicio->startOfDay(), null, null, $sedeId);

        foreach ($libres as $slot) {
            if (isset($slot['resource_id']) && $slot['starts_at']->equalTo($inicio)) {
                $recurso = \App\Models\Resource::withoutGlobalScope('business')->find($slot['resource_id']);

                if ($recurso !== null) {
                    return $recurso;
                }
            }
        }

        return $this->anyResourceFor($servicio->id, $sedeId);
    }

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
