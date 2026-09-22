<?php

namespace App\Ai;

use App\Ai\Capabilities\AvailabilityCapability;
use App\Ai\Capabilities\CancelAppointmentCapability;
use App\Models\Appointment;
use App\Models\Message;
use App\Models\WhatsappConversation;
use App\Services\ClientPortalService;
use App\Support\ChannelPhone;
use App\Support\TituloCorto;
use Illuminate\Support\Collection;

/**
 * La conversación inicial obligatoria: todo empieza tocando.
 *
 * Lo definió Alejandro, y es la puerta que las clientas conocen de
 * ManyChat:
 *
 *   [Agendar] [Mis citas] [Otra consulta]
 *     Agendar        → [Agendar aquí] [Agendar en la web]
 *     Mis citas      → la lista de sus citas → una → [Reagendar] [Cancelar] [Volver]
 *     Otra consulta  → [Daño / Garantía] [Hablar con el admin] [Otra pregunta]
 *       Daño / Garantía → [Mi último servicio] [Otro servicio] → al equipo
 *
 * Sale al empezar cada conversación (un saludo, o el primer mensaje
 * después de un rato), y los atajos se respetan: "quiero cambiar mi cita"
 * va directo a mover, "mis citas" a la lista, "se me cayó el esmalte" a
 * garantía, "quiero semi mañana" directo a las horas. Lo que no es ni
 * menú ni atajo sigue siendo conversación con el modelo.
 *
 * Todo esto corre en código, sin modelo: son decisiones con opciones
 * conocidas, y el modelo solo se equivocaba al "interpretarlas".
 */
final class GuidedEntry
{
    public const BOOK = 'Agendar';

    public const MY_APPOINTMENTS = 'Mis citas';

    public const OTHER = 'Otra consulta';

    public const HERE = 'Agendar aquí';

    public const WEB = 'Agendar en la web';

    public const RESCHEDULE = 'Reagendar';

    public const CANCEL = 'Cancelar';

    public const BACK = 'Volver';

    public const CONFIRM_CANCEL = 'Sí, cancelar';

    public const KEEP = 'No, dejarla';

    public const WARRANTY = 'Daño / Garantía';

    public const ADMIN = 'Hablar con el admin';

    public const QUESTION = 'Otra pregunta';

    public const LAST_SERVICE = 'Mi último servicio';

    public const OTHER_SERVICE = 'Otro servicio';

    /** Sin mensajes del bot en este lapso, lo siguiente es una conversación nueva. */
    private const NEW_SESSION_MINUTES = 30;

    /** Lo que marca una gestión a MEDIAS: ahí un menú interrumpe. */
    private const PENDING = ['confirmar', 'decidir_mover', 'mudanza', 'eligiendo_fecha'];

    /** Lo que deja una gestión anterior y un comienzo nuevo borra. */
    private const LEFTOVERS = ['servicios', 'opciones', 'horas', 'todas', 'fechas', 'mostrado_at', 'fecha_iso', 'dia', 'menu', 'citas', 'cita_id'];

    public function __construct(
        private readonly AvailabilityCapability $agenda,
        private readonly CancelAppointmentCapability $cancellation,
        private readonly ClientPortalService $portal,
    ) {}

    /**
     * El mismo contrato que Toques: null = esto no es para mí.
     *
     * @return array{text: string, conversation_id: null, tools_used: list<string>}|null
     */
    public function attend(WhatsappConversation $conversacion, string $texto): ?array
    {
        $business = $conversacion->business;
        $phone = ChannelPhone::normalize((string) $conversacion->phone, $business->country_code ?? 'CO');

        if ($phone === null) {
            return null;
        }

        $caller = AiCaller::customer($business, $phone, $conversacion->client, 'whatsapp');
        $pedido = UltimoPedido::ver($phone);

        // 1) Tocó una opción del menú en el que está.
        if (isset($pedido['menu'])) {
            $tocado = $this->tapped($caller, $conversacion, $phone, $pedido, $this->lastLine($texto));

            if ($tocado !== null) {
                return $tocado;
            }

            // Escribió otra cosa: la marca se va para no atrapar lo que siga.
            unset($pedido['menu'], $pedido['citas'], $pedido['cita_id']);
            UltimoPedido::guardar($phone, $pedido);
        }

        // 2) Atajos: lo que dijo ya elige la rama.
        if ($this->wantsToMove($texto)) {
            return $this->startMove($caller, $phone);
        }

        if (array_intersect(array_keys($pedido), self::PENDING) !== []) {
            return null;
        }

        if ($this->wantsMyAppointments($texto)) {
            return $this->listAppointments($caller, $phone);
        }

        if ($this->wantsWarranty($texto)) {
            return $this->askWarrantyScope($caller, $phone);
        }

        if ($this->wantsHuman($texto)) {
            return $this->toStaff($caller, $conversacion, $phone, 'Pidió hablar con una persona del equipo.', 'hablar_con_persona');
        }

        if ($this->wantsBooking($texto)) {
            // "quiero semi mañana" ya dijo qué: el camino normal lo lleva mejor.
            return $this->agenda->mentionsService($caller, $texto)
                ? null
                : $this->showMenu($caller, $phone, 'agendar');
        }

        // 3) El menú de inicio: con un saludo, o al empezar la conversación.
        if ($this->isGreeting($texto) || $this->isNewSession($conversacion)) {
            return $this->showMenu($caller, $phone, 'root');
        }

        return null;
    }

    /**
     * Lo que pasa al tocar una opción, según el menú en que está.
     *
     * @param  array<string, mixed>  $pedido
     * @return array{text: string, conversation_id: null, tools_used: list<string>}|null
     */
    private function tapped(AiCaller $caller, WhatsappConversation $conversacion, string $phone, array $pedido, string $t): ?array
    {
        return match ($pedido['menu']) {
            'root' => match ($t) {
                $this->plain(self::BOOK) => $this->showMenu($caller, $phone, 'agendar'),
                $this->plain(self::MY_APPOINTMENTS) => $this->listAppointments($caller, $phone),
                $this->plain(self::OTHER) => $this->showMenu($caller, $phone, 'consulta'),
                default => null,
            },
            'agendar' => match ($t) {
                $this->plain(self::HERE), 'aqui', 'por aqui', 'agendar por aqui' => $this->bookHere($caller, $phone),
                $this->plain(self::WEB), 'web', 'en la web', 'pagina', 'link' => $this->bookOnWeb($caller, $phone),
                default => null,
            },
            'citas' => isset($pedido['citas'][$t]) ? $this->showAppointment($caller, $phone, (int) $pedido['citas'][$t]) : null,
            'cita' => match ($t) {
                $this->plain(self::RESCHEDULE) => $this->moveChosen($caller, $phone, (int) $pedido['cita_id']),
                $this->plain(self::CANCEL) => $this->askCancel($caller, $phone, (int) $pedido['cita_id']),
                $this->plain(self::BACK) => $this->listAppointments($caller, $phone),
                default => null,
            },
            'cancelar' => match ($t) {
                $this->plain(self::CONFIRM_CANCEL), 'si', 'si cancelar' => $this->cancel($caller, $phone, (int) $pedido['cita_id']),
                $this->plain(self::KEEP), 'no' => $this->reply($phone, 'Perfecto, tu cita sigue en pie 😊', 'cancelar_cita'),
                default => null,
            },
            'consulta' => match ($t) {
                $this->plain(self::WARRANTY), 'garantia', 'dano' => $this->askWarrantyScope($caller, $phone),
                $this->plain(self::ADMIN) => $this->toStaff($caller, $conversacion, $phone, 'Pidió hablar con el administrador.', 'hablar_con_persona'),
                $this->plain(self::QUESTION) => $this->reply($phone, '¡Claro! Cuéntame, ¿en qué te ayudo? 😊', 'otra_pregunta'),
                default => null,
            },
            'garantia' => match ($t) {
                $this->plain(self::LAST_SERVICE) => $this->warrantyForLast($caller, $conversacion, $phone),
                $this->plain(self::OTHER_SERVICE) => $this->toStaff(
                    $caller,
                    $conversacion,
                    $phone,
                    'Posible GARANTÍA: dice que NO es por su último servicio. Pregúntale cuál fue y quién lo hizo: '
                        .'la garantía se le anota a quien hizo el trabajo original.',
                    'garantia',
                    'Gracias por avisarnos 🙏 Cuéntanos qué servicio fue, cuándo, y qué pasó (si puedes, con una foto 📷). '
                        .'Alguien del equipo te escribe para revisarlo.',
                ),
                default => null,
            },
            default => null,
        };
    }

    /**
     * Manda uno de los menús de botones.
     *
     * @return array{text: string, conversation_id: null, tools_used: list<string>}|null
     */
    private function showMenu(AiCaller $caller, string $phone, string $menu): ?array
    {
        [$texto, $botones] = match ($menu) {
            'root' => [$this->hello($caller).' ¿En qué te puedo ayudar?', [self::BOOK, self::MY_APPOINTMENTS, self::OTHER]],
            'agendar' => ['¿Cómo prefieres agendar? 💅', [self::HERE, self::WEB]],
            'consulta' => ['¡Claro! ¿Qué necesitas?', [self::WARRANTY, self::ADMIN, self::QUESTION]],
            default => [$this->hello($caller).' ¿En qué te puedo ayudar?', [self::BOOK, self::MY_APPOINTMENTS, self::OTHER]],
        };

        return $this->send($caller, $phone, $texto, $botones, ['menu' => $menu], 'menu_'.$menu, fresh: $menu !== 'consulta');
    }

    /**
     * Envía botones y deja el menú guardado para el toque siguiente.
     *
     * @param  list<string>|list<array{id: string, title: string, description?: string}>  $opciones
     * @param  array<string, mixed>  $estado
     * @return array{text: string, conversation_id: null, tools_used: list<string>}|null
     */
    private function send(AiCaller $caller, string $phone, string $texto, array $opciones, array $estado, string $herramienta, bool $fresh = false, string $boton = 'Ver opciones'): ?array
    {
        $filas = array_map(
            fn ($o, $i) => is_array($o) ? $o : ['id' => 'o'.$i, 'title' => $o],
            $opciones,
            array_keys($opciones),
        );

        if (! app(EnvioDirecto::class)->opciones($caller, $texto, $filas, $boton)) {
            return null;
        }

        $pedido = UltimoPedido::ver($phone);

        // Un comienzo nuevo empieza de cero: lo de la gestión anterior no
        // puede colarse en la nueva. La fecha que haya dicho en ESTE
        // mensaje (DateInText) sí se queda.
        if ($fresh) {
            $pedido = array_diff_key($pedido, array_flip(self::LEFTOVERS));
        }

        UltimoPedido::guardar($phone, [...$pedido, ...$estado]);

        return ['text' => '', 'conversation_id' => null, 'tools_used' => [$herramienta]];
    }

    // -- Agendar -----------------------------------------------------------

    /** @return array{text: string, conversation_id: null, tools_used: list<string>}|null */
    private function bookHere(AiCaller $caller, string $phone): ?array
    {
        $this->forgetMenu($phone);

        if ($this->agenda->welcomeMenu($caller, '') !== null) {
            return ['text' => '', 'conversation_id' => null, 'tools_used' => ['menu_inicial']];
        }

        return $this->reply($phone, '¡Claro! ¿Qué servicio te quieres hacer y para qué día? 💅', 'agendar');
    }

    /** @return array{text: string, conversation_id: null, tools_used: list<string>}|null */
    private function bookOnWeb(AiCaller $caller, string $phone): ?array
    {
        $link = $this->bookingLink($caller);

        if ($link === null) {
            return $this->bookHere($caller, $phone);
        }

        return $this->reply(
            $phone,
            "Aquí puedes ver la agenda completa y reservar tú misma 👇\n{$link}\n\nSi prefieres, también te agendo por aquí 😊",
            'agenda_web',
        );
    }

    // -- Mis citas ---------------------------------------------------------

    /**
     * Sus citas próximas, como lista tocable.
     *
     * Con una sola no hay nada que elegir: se abre directo.
     *
     * @return array{text: string, conversation_id: null, tools_used: list<string>}|null
     */
    private function listAppointments(AiCaller $caller, string $phone): ?array
    {
        $citas = $this->upcoming($caller);

        if ($citas->isEmpty()) {
            return $this->send(
                $caller,
                $phone,
                'No tienes citas próximas 😊 ¿Quieres agendar una?',
                [self::BOOK, self::OTHER],
                ['menu' => 'root'],
                'mis_citas',
            );
        }

        if ($citas->count() === 1) {
            return $this->showAppointment($caller, $phone, $citas->first()->id);
        }

        $tz = $caller->business->businessTimezone();
        $filas = [];
        $mapa = [];

        foreach ($citas->take(10) as $cita) {
            $titulo = TituloCorto::de(
                ucfirst($cita->starts_at->setTimezone($tz)->locale('es')->isoFormat('ddd D MMM')).' · '.HoraLegible::de($cita->starts_at, $tz),
                24,
            );
            $filas[] = [
                'id' => 'cita_'.$cita->id,
                'title' => $titulo,
                'description' => TituloCorto::de($this->whatAndWho($cita), 72),
            ];
            $mapa[$this->plain($titulo)] = $cita->id;
        }

        return $this->send($caller, $phone, 'Estas son tus citas próximas. Toca una para gestionarla 👇', $filas, ['menu' => 'citas', 'citas' => $mapa], 'mis_citas', boton: 'Ver mis citas');
    }

    /** @return array{text: string, conversation_id: null, tools_used: list<string>}|null */
    private function showAppointment(AiCaller $caller, string $phone, int $id): ?array
    {
        $cita = $this->mine($caller, $id);

        if ($cita === null) {
            return $this->listAppointments($caller, $phone);
        }

        return $this->send(
            $caller,
            $phone,
            'Tu cita: '.$this->describe($caller, $cita).'. ¿Qué quieres hacer?',
            [self::RESCHEDULE, self::CANCEL, self::BACK],
            ['menu' => 'cita', 'cita_id' => $cita->id],
            'mis_citas',
        );
    }

    /** @return array{text: string, conversation_id: null, tools_used: list<string>}|null */
    private function askCancel(AiCaller $caller, string $phone, int $id): ?array
    {
        $cita = $this->mine($caller, $id);

        if ($cita === null) {
            return $this->listAppointments($caller, $phone);
        }

        // La política del local (con cuánta anticipación) se dice ANTES de
        // pedir confirmación, no después de que ella ya dijo que sí.
        if (! $this->portal->canBeChanged($cita, $caller->business)) {
            $this->forgetMenu($phone);

            return $this->reply(
                $phone,
                ($this->portal->reasonToRefuse($cita, $caller->business) ?? 'Esa cita ya no se puede cancelar por aquí.')
                    .' Si necesitas ayuda, toca «'.self::ADMIN.'» desde «'.self::OTHER.'» 🙏',
                'cancelar_cita',
            );
        }

        return $this->send(
            $caller,
            $phone,
            '¿Seguro que cancelas tu cita de '.$this->describe($caller, $cita).'?',
            [self::CONFIRM_CANCEL, self::KEEP],
            ['menu' => 'cancelar', 'cita_id' => $cita->id],
            'cancelar_cita',
        );
    }

    /** @return array{text: string, conversation_id: null, tools_used: list<string>} */
    private function cancel(AiCaller $caller, string $phone, int $id): array
    {
        $this->forgetMenu($phone);

        $resultado = $this->cancellation->execute($caller, ['cita_id' => $id, 'motivo' => 'Cancelada por la clienta desde WhatsApp']);

        if (! ($resultado['cancelada'] ?? false)) {
            return $this->reply($phone, 'No pude cancelarla 😕 '.rtrim((string) ($resultado['motivo'] ?? ''), '.').'.', 'cancelar_cita');
        }

        // La confirmación ya la mandó la herramienta de cancelar.
        return ['text' => '', 'conversation_id' => null, 'tools_used' => ['cancelar_cita']];
    }

    // -- Mover -------------------------------------------------------------

    /**
     * "Quiero cambiar mi cita", escrito.
     *
     * Con UNA cita, directo a horas; con varias, la lista para elegir
     * cuál (antes eso quedaba en manos del modelo, que se enredaba).
     *
     * @return array{text: string, conversation_id: null, tools_used: list<string>}|null
     */
    private function startMove(AiCaller $caller, string $phone): ?array
    {
        $pedido = UltimoPedido::ver($phone);

        if ($caller->client === null || array_intersect(array_keys($pedido), ['confirmar', 'decidir_mover', 'mudanza']) !== []) {
            return null;
        }

        $citas = $this->upcoming($caller);

        return match (true) {
            $citas->isEmpty() => null,
            $citas->count() === 1 => $this->moveChosen($caller, $phone, $citas->first()->id),
            default => $this->listAppointments($caller, $phone),
        };
    }

    /**
     * Mover una cita concreta, por el riel y no por el modelo.
     *
     * El pedido queda marcado como MUDANZA: las horas se anuncian como
     * mudanza y el «Sí» ejecuta reagendar_cita, no crear_cita (ver Toques).
     *
     * @return array{text: string, conversation_id: null, tools_used: list<string>}|null
     */
    private function moveChosen(AiCaller $caller, string $phone, int $id): ?array
    {
        $cita = $this->mine($caller, $id);

        if ($cita === null) {
            return null;
        }

        if (! $this->portal->canBeChanged($cita, $caller->business)) {
            $this->forgetMenu($phone);

            return $this->reply(
                $phone,
                $this->portal->reasonToRefuse($cita, $caller->business) ?? 'Esa cita ya no se puede mover por aquí.',
                'mover_cita',
            );
        }

        $tz = $caller->business->businessTimezone();
        $pedido = array_diff_key(UltimoPedido::ver($phone), array_flip(self::LEFTOVERS));

        UltimoPedido::guardar($phone, [
            ...$pedido,
            'servicios' => $cita->items->map(fn ($i) => $i->service?->name)->filter()->unique()->values()->all(),
            'mudanza' => [
                'id' => $cita->id,
                // Para que el encabezado de las horas diga QUÉ se mueve.
                'desde' => 'para el '.$cita->starts_at->setTimezone($tz)->locale('es')->isoFormat('dddd [a las] ')
                    .HoraLegible::de($cita->starts_at, $tz),
            ],
        ]);

        // Con fecha dicha van directo las horas; sin fecha, los botones de
        // día. Ambas rutas ya son de la agenda.
        $resultado = $this->agenda->execute($caller, []);

        if (! empty($resultado['ofrecidas']) || ! empty($resultado['eligiendo_fecha']) || ! empty($resultado['eligiendo_servicio'])) {
            return ['text' => '', 'conversation_id' => null, 'tools_used' => ['mover_cita']];
        }

        if (isset($resultado['servicios'], $resultado['dia']) && ($resultado['horas'] ?? []) === []) {
            return $this->reply(
                $phone,
                sprintf('Para mover tu cita de *%s*: el *%s* no me quedan horas 😕 ¿Te sirve otro día?', implode(' y ', $resultado['servicios']), $resultado['dia']),
                'mover_cita',
            );
        }

        // No se pudo encaminar: que lo lleve el modelo, sin la marca.
        UltimoPedido::guardar($phone, $pedido);

        return null;
    }

    // -- Garantías ---------------------------------------------------------

    /** @return array{text: string, conversation_id: null, tools_used: list<string>}|null */
    private function askWarrantyScope(AiCaller $caller, string $phone): ?array
    {
        $ultimo = $this->lastService($caller);

        $texto = $ultimo === null
            ? 'Lamentamos mucho eso 😔 ¿Es por tu último servicio con nosotros o por otro?'
            : 'Lamentamos mucho eso 😔 Tu último servicio fue '.$this->describe($caller, $ultimo).'. ¿Es por ese, o por otro?';

        return $this->send($caller, $phone, $texto, [self::LAST_SERVICE, self::OTHER_SERVICE], ['menu' => 'garantia'], 'garantia');
    }

    /**
     * Garantía por el último servicio: al equipo, con la atribución lista.
     *
     * La garantía se le anota a quien hizo el trabajo ORIGINAL (así la
     * modela la agenda: `warranty_for_resource_id`), y el número de
     * garantías por persona es la señal de quién está haciendo mal su
     * trabajo. El bot no decide si es garantía -- eso lo decide el
     * equipo al verla --, pero les deja nombrado el servicio, la cita y
     * quién lo hizo para que la registren bien.
     *
     * @return array{text: string, conversation_id: null, tools_used: list<string>}|null
     */
    private function warrantyForLast(AiCaller $caller, WhatsappConversation $conversacion, string $phone): ?array
    {
        $ultimo = $this->lastService($caller);

        $nota = $ultimo === null
            ? 'Posible GARANTÍA por su último servicio, pero no encontré ninguno registrado. Pregúntale cuál fue y quién lo hizo.'
            : sprintf(
                'Posible GARANTÍA por su último servicio: %s (cita #%d%s). Si se hace la garantía, anotarla a %s: la garantía se le anota a quien hizo el trabajo original.',
                $this->whatAndWho($ultimo),
                $ultimo->id,
                $ultimo->items->first() ? ', ítem #'.$ultimo->items->first()->id : '',
                $ultimo->items->map(fn ($i) => $i->resource?->name)->filter()->unique()->implode(' / ') ?: 'quien lo hizo',
            );

        return $this->toStaff(
            $caller,
            $conversacion,
            $phone,
            $nota,
            'garantia',
            'Gracias por avisarnos 🙏 Cuéntanos qué pasó y, si puedes, mándanos una foto 📷. Alguien del equipo te escribe enseguida para revisarlo.',
        );
    }

    // -- Al equipo ---------------------------------------------------------

    /**
     * Pasa la conversación a una persona: el bot calla, queda la nota en la
     * bandeja y la conversación salta como pendiente.
     *
     * @return array{text: string, conversation_id: null, tools_used: list<string>}
     */
    private function toStaff(
        AiCaller $caller,
        WhatsappConversation $conversacion,
        string $phone,
        string $nota,
        string $herramienta,
        string $aLaClienta = 'Listo 🙏 Ya le avisé al equipo: te escriben por aquí enseguida.',
    ): array {
        $this->forgetMenu($phone);
        $conversacion->pauseAgent();

        Message::create([
            'business_id' => $conversacion->business_id,
            'conversation_id' => $conversacion->id,
            'client_id' => $conversacion->client_id,
            'kind' => Message::KIND_STAFF,
            'direction' => Message::DIRECTION_OUT,
            'to' => $conversacion->phone,
            'body' => '⚑ '.$nota.' (el bot queda en pausa: responde tú)',
            // Nota interna: nace enviada para que el outbox no la despache.
            'status' => Message::STATUS_SENT,
            'sent_at' => now(),
        ]);

        $conversacion->update([
            'last_message_at' => now(),
            'read_at' => null,
            'status' => WhatsappConversation::STATUS_OPEN,
        ]);

        return ['text' => $aLaClienta, 'conversation_id' => null, 'tools_used' => [$herramienta]];
    }

    // -- Datos -------------------------------------------------------------

    /** @return Collection<int, Appointment> */
    private function upcoming(AiCaller $caller): Collection
    {
        return $caller->client === null
            ? collect()
            : $this->portal->upcoming($caller->client, $caller->business);
    }

    /** Una cita SUYA, o nada: el id viene de un toque, pero se verifica igual. */
    private function mine(AiCaller $caller, int $id): ?Appointment
    {
        return $this->upcoming($caller)->firstWhere('id', $id);
    }

    /** Su último servicio ya pasado (no cancelado ni inasistido). */
    private function lastService(AiCaller $caller): ?Appointment
    {
        if ($caller->client === null) {
            return null;
        }

        return Appointment::withoutGlobalScopes()
            ->where('business_id', $caller->business->id)
            ->where('client_id', $caller->client->id)
            ->where('starts_at', '<', now())
            ->whereNotIn('status', [Appointment::STATUS_CANCELLED, Appointment::STATUS_NO_SHOW])
            ->with(['items.service', 'items.resource'])
            ->latest('starts_at')
            ->first();
    }

    /** "*Semipermanente* el *martes 23 de septiembre* a las *3 pm* con *Anyi*" */
    private function describe(AiCaller $caller, Appointment $cita): string
    {
        $tz = $caller->business->businessTimezone();
        $servicios = $cita->items->map(fn ($i) => $i->service?->name)->filter()->unique()->implode(' y ');
        $con = $cita->items->map(fn ($i) => $i->resource?->name)->filter()->unique()->implode(' y ');

        return sprintf(
            '*%s* el *%s* a las *%s*%s',
            $servicios !== '' ? $servicios : 'tu servicio',
            $cita->starts_at->setTimezone($tz)->locale('es')->isoFormat('dddd D [de] MMMM'),
            HoraLegible::de($cita->starts_at, $tz),
            $con !== '' ? ' con *'.$con.'*' : '',
        );
    }

    /** "Semipermanente con Anyi" */
    private function whatAndWho(Appointment $cita): string
    {
        $servicios = $cita->items->map(fn ($i) => $i->service?->name)->filter()->unique()->implode(' y ');
        $con = $cita->items->map(fn ($i) => $i->resource?->name)->filter()->unique()->implode(' y ');

        return trim(($servicios !== '' ? $servicios : 'Servicio').($con !== '' ? ' con '.$con : ''));
    }

    private function hello(AiCaller $caller): string
    {
        $nombre = trim((string) $caller->client?->fullName());

        return $nombre === '' || NombreRaro::es($nombre)
            ? '¡Hola! 💅'
            : '¡Hola, '.explode(' ', $nombre)[0].'! 💅';
    }

    private function bookingLink(AiCaller $caller): ?string
    {
        $base = rtrim((string) config('spa.public_booking_url'), '/');
        $slug = (string) ($caller->business->slug ?? '');

        return ($base === '' || $slug === '') ? null : "{$base}/reservar/{$slug}";
    }

    /** @return array{text: string, conversation_id: null, tools_used: list<string>} */
    private function reply(string $phone, string $texto, string $herramienta): array
    {
        $this->forgetMenu($phone);

        return ['text' => $texto, 'conversation_id' => null, 'tools_used' => [$herramienta]];
    }

    private function forgetMenu(string $phone): void
    {
        $pedido = UltimoPedido::ver($phone);
        unset($pedido['menu'], $pedido['citas'], $pedido['cita_id']);
        UltimoPedido::guardar($phone, $pedido);
    }

    // -- Qué quiso decir ----------------------------------------------------

    /**
     * ¿Es el arranque de una conversación? El bot no ha escrito en la
     * última media hora: lo que llega ahora es una visita nueva.
     */
    private function isNewSession(WhatsappConversation $conversacion): bool
    {
        $ultimaDelBot = Message::withoutGlobalScope('business')
            ->where('conversation_id', $conversacion->id)
            ->where('direction', Message::DIRECTION_OUT)
            ->where('kind', Message::KIND_AGENT)
            ->latest('id')
            ->first();

        return $ultimaDelBot === null
            || $ultimaDelBot->created_at->lte(now()->subMinutes(self::NEW_SESSION_MINUTES));
    }

    /** "Hola", "buenas tardes", "buen día 🙏" — un saludo y nada más. */
    private function isGreeting(string $texto): bool
    {
        $t = trim((string) preg_replace('/[^\p{L}\s]/u', '', $this->plain($texto)));

        return mb_strlen($t) <= 40
            && (bool) preg_match('/^(hola|holi|holaa+|buenas|buenos dias|buen dia|buenas tardes|buenas noches|hey|que tal)(\s+\w+){0,3}$/u', $t);
    }

    /** ¿Pidió mover una cita que ya tiene? */
    private function wantsToMove(string $texto): bool
    {
        $t = $this->plain($texto);

        return (bool) preg_match('/\b(mover|moverla|cambiar|cambiarla|pasar|pasarla|correr|correrla|reagendar|aplazar|adelantar)\b/u', $t)
            && (bool) preg_match('/\b(cita|turno|hora|reserva)\b/u', $t);
    }

    /** "mis citas", "cuándo es mi cita", "¿tengo cita?" */
    private function wantsMyAppointments(string $texto): bool
    {
        $t = $this->plain($texto);

        return (bool) preg_match('/\b(mis citas|mi cita|mis turnos|mi turno|tengo cita|cuando es mi|cancelar mi|cancelar la cita|cancelar cita)\b/u', $t);
    }

    /** "se me cayó el esmalte", "garantía", "me quedaron mal" */
    private function wantsWarranty(string $texto): bool
    {
        $t = $this->plain($texto);

        return (bool) preg_match('/\b(garantia|se me cayo|se me cayeron|se me levanto|se me levantaron|se me dano|se danaron|se me partio|quedaron mal|quedo mal|me las dejaron mal|reclamo|queja)\b/u', $t);
    }

    /** "hablar con alguien", "una persona", "el administrador" */
    private function wantsHuman(string $texto): bool
    {
        $t = $this->plain($texto);

        return (bool) preg_match('/\b(hablar con (alguien|una persona|el admin|la admin|el administrador|la administradora|un asesor|una asesora|la duena|el dueno)|asesor humano|persona real)\b/u', $t);
    }

    /** ¿Vino a agendar? */
    private function wantsBooking(string $texto): bool
    {
        $t = $this->plain($texto);

        // Pide la página: la salida más barata es el link, no un menú.
        if (preg_match('/\b(link|enlace|pagina|web|sitio)\b/u', $t)) {
            return false;
        }

        return (bool) preg_match(
            '/\b(cita|agendar|agendarme|agendame|agenda|turno|reserva|reservar|disponibilidad|cupo|espacio)\b/u',
            $t,
        );
    }

    /** El toque casi siempre es lo ÚLTIMO del mensaje (el debounce pega pedazos). */
    private function lastLine(string $texto): string
    {
        return $this->plain(trim((string) collect(preg_split('/\r?\n/', $texto))->filter(fn ($l) => trim($l) !== '')->last()));
    }

    private function plain(string $texto): string
    {
        $t = mb_strtolower(trim($texto));
        $t = strtr($t, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n']);
        $t = preg_replace('/[^\p{L}\p{N}\s,]/u', ' ', $t) ?? $t;

        return trim((string) preg_replace('/\s+/u', ' ', $t));
    }
}
