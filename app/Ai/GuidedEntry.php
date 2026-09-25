<?php

namespace App\Ai;

use App\Ai\Capabilities\AvailabilityCapability;
use App\Ai\Capabilities\CancelAppointmentCapability;
use App\Models\Appointment;
use App\Models\AppointmentStageEvent;
use App\Models\Message;
use App\Models\WhatsappConversation;
use App\Services\ClientPortalService;
use App\Services\Loyalty\LoyaltyService;
use App\Services\Ratings\SurveyService;
use App\Services\Scheduling\StageTransitionService;
use App\Support\ChannelPhone;
use App\Support\NombreDePila;
use App\Support\Scheduling\ThankYouMessage;
use App\Support\TituloCorto;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

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

    /** Los botones del recordatorio de retoque (ver MessageTemplate::retoque). */
    public const RETOUCH = 'Agendar retoque';

    /**
     * El otro botón del recordatorio.
     *
     * No reusa OTHER_SERVICE ('Otro servicio') a propósito: ese texto ya
     * significa otra cosa en el flujo de garantías, y dos botones distintos
     * con el mismo título llegan iguales por el webhook.
     */
    public const FROM_SCRATCH = 'Empezar de cero';

    /**
     * Darse de baja. Va en TODO mensaje que el spa manda por su cuenta.
     *
     * No es cortesía: es lo que separa un recordatorio de un spam. Quien no
     * puede salirse bloquea el número, y un bloqueo se lleva por delante
     * también los recordatorios de su propia cita -- y la calidad del número
     * para todas las demás.
     */
    public const UNSUBSCRIBE = 'Darme de baja';

    public const RESUBSCRIBE = 'Volver a recibir';

    /**
     * Los dos botones del final de la visita.
     *
     * Viven en ThankYouMessage --que es quien los manda-- y se reexportan
     * acá para que el enrutamiento se lea completo en un solo sitio.
     */
    public const MY_CARD = ThankYouMessage::MY_CARD;

    public const RATE = ThankYouMessage::RATE;

    /**
     * Los botones del recordatorio de la cita.
     *
     * «Reagendar» ya existe arriba y su camino es el mismo. Estos dos son
     * los que faltaban: confirmar que viene --que es lo que el salón hace
     * hoy llamando una por una-- y cancelar, que libera la silla a tiempo.
     */
    public const CONFIRM_ATTENDANCE = 'Confirmo que voy';

    public const CANCEL_APPOINTMENT = 'Cancelar cita';

    /**
     * Los dos botones del aviso de cambio de número (plantilla
     * `cambio_de_numero_granizado`, septiembre de 2026).
     *
     * Aprovechan que le escribimos a TODAS las clientas para limpiar la
     * lista: quien ya no viene --se fue de Sibaté, cambió de salón-- lo dice
     * con un toque y no le volvemos a escribir, en vez de bloquear el
     * número, que es lo que hace quien no puede salirse.
     */
    public const STILL_CLIENT = 'Sí, ahí nos vemos';

    public const NO_LONGER_CLIENT = 'Ya no voy, gracias';

    /** Marca en caché: le preguntamos el nombre y falta la respuesta. */
    private const ASKING_NAME = 'pide_nombre:';

    /** Sin mensajes del bot en este lapso, lo siguiente es una conversación nueva. */
    private const NEW_SESSION_MINUTES = 30;

    /** Lo que marca una gestión a MEDIAS: ahí un menú interrumpe. */
    private const PENDING = ['confirmar', 'decidir_mover', 'mudanza', 'eligiendo_fecha', 'eligiendo_empleado'];

    /** Lo que deja una gestión anterior y un comienzo nuevo borra. */
    private const LEFTOVERS = ['servicios', 'opciones', 'horas', 'todas', 'fechas', 'mostrado_at', 'fecha_iso', 'dia', 'menu', 'citas', 'cita_id', 'acepta_multa', 'empleado', 'empleados', 'eligiendo_empleado', 'empleado_preguntado', 'preludio'];

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
    public function attend(WhatsappConversation $conversacion, string $texto, bool $explicitOnly = false): ?array
    {
        /*
         * `$explicitOnly`: la conversación está en pausa esperando a una
         * persona. Solo se atiende lo que la clienta PIDIÓ con todas las
         * letras (agendar, mis citas, mover, un toque sobre un menú abierto);
         * nunca se le abre el menú de inicio por iniciativa propia, que le
         * quitaría la conversación a quien viene.
         */
        $business = $conversacion->business;
        $phone = ChannelPhone::normalize((string) $conversacion->phone, $business->country_code ?? 'CO');

        if ($phone === null) {
            return null;
        }

        $caller = AiCaller::customer($business, $phone, $conversacion->client, 'whatsapp');

        /*
         * 0) Los botones del recordatorio de retoque.
         *
         * Van ANTES que «reiniciar» porque «Empezar de cero» cae dentro de
         * esa palabra, y llevarla al menú de inicio le cobra un toque de más
         * a quien ya dijo que quiere agendar otra cosa.
         */
        $retoque = $this->fromRetouchReminder($caller, $phone, $texto);

        if ($retoque !== null) {
            return $retoque;
        }

        // 0.4) Los del aviso de cambio de número: sigue viniendo, o ya no
        // (y, si no teníamos su nombre, la respuesta a "¿cómo te llamas?").
        $cambio = $this->fromNumberChange($caller, $phone, $texto)
            ?? $this->fromNamePrompt($caller, $phone, $texto);

        if ($cambio !== null) {
            return $cambio;
        }

        // 0.5) Los botones del final de la visita: su tarjeta y calificar.
        $cierre = $this->fromThankYou($caller, $phone, $texto);

        if ($cierre !== null) {
            return $cierre;
        }

        // 0.6) Los del recordatorio: confirmar que viene, o cancelar.
        $recordatorio = $this->fromReminder($caller, $phone, $texto);

        if ($recordatorio !== null) {
            return $recordatorio;
        }

        /*
         * 1) «reiniciar» vuelve al inicio SIEMPRE: se olvida lo que quedara
         * abierto y sale la bienvenida. Es explícito, así que vale también
         * en pausa esperando a una persona. La bienvenida lo anuncia.
         */
        if ($this->wantsRestart($texto)) {
            UltimoPedido::olvidar($phone);

            return $this->showMenu($caller, $phone, 'root');
        }

        $pedido = UltimoPedido::ver($phone);

        /*
         * Un "Hola" NO borra lo que va a medias: muchas veces es "¿sigues
         * ahí?". Alejandro dejó un reagendamiento a medias, escribió
         * "Hola" y le contestó el modelo con texto suelto, sin menú. Ahora
         * con algo abierto se le recuerda en qué iban y cómo reiniciar; sin
         * nada abierto, la bienvenida de siempre (paso 3).
         */
        if (! $explicitOnly && $this->isGreeting($texto) && array_intersect(array_keys($pedido), self::PENDING) !== []) {
            return $this->reply(
                $phone,
                '¡Hola! Sigo aquí 😊 Estábamos '.$this->whatWasPending($pedido)
                    .'. Puedes seguir con las opciones que te envié, o escribir *reiniciar* para empezar de nuevo.',
                'saludo_en_gestion',
            );
        }

        // 1) Tocó una opción del menú en el que está.
        if (isset($pedido['menu'])) {
            $tocado = $this->tapped($caller, $conversacion, $phone, $pedido, $this->lastLine($texto));

            if ($tocado !== null) {
                return $tocado;
            }

            // Escribió otra cosa: la marca se va para no atrapar lo que siga.
            unset($pedido['menu'], $pedido['citas'], $pedido['cita_id'], $pedido['acepta_multa']);
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
            // En pausa ya se pidió una persona: repetir el aviso solo
            // llenaría la bandeja de notas iguales.
            return $explicitOnly
                ? null
                : $this->toStaff($caller, $conversacion, $phone, 'Pidió hablar con una persona del equipo.', 'hablar_con_persona');
        }

        if ($this->wantsBooking($texto)) {
            // "quiero semi mañana" ya dijo qué: el camino normal lo lleva mejor.
            // Si es el primer mensaje ("Hola, quiero agendar"), se saluda
            // antes de preguntar: ir al grano sin un hola es descortés.
            return $this->agenda->mentionsService($caller, $texto)
                ? null
                : $this->showMenu(
                    $caller,
                    $phone,
                    'agendar',
                    welcome: $this->startsWithGreeting($texto) || $this->isNewSession($conversacion),
                );
        }

        /*
         * 3) El menú de inicio: con un saludo, o al empezar la conversación.
         *
         * Pero no es conversación nueva si dejó una cita a medias. Media hora
         * sin que el bot escribiera bastaba para tratar lo siguiente como una
         * visita nueva, y la clienta que se había distraído en el trabajo
         * volvía a «¿Qué deseas hacer el día de hoy?» con su pedido tirado.
         * Un saludo explícito sí abre el menú: ahí está pidiendo empezar.
         */
        $nueva = $this->isNewSession($conversacion) && UltimoPedido::ver($phone) === [];

        if (! $explicitOnly && ($this->isGreeting($texto) || $nueva)) {
            return $this->showMenu($caller, $phone, 'root');
        }

        /*
         * 4) Un cierre ("vale", "gracias", "listo") se responde corto y se
         * acabó. Al "Vale" de Alejandro, después de cancelar su cita, el
         * modelo le contestó "¡Hola! ¿cómo te puedo ayudar hoy?" -- un
         * saludo a quien se estaba despidiendo.
         */
        if (! $explicitOnly && $this->isClosing($texto)) {
            return [
                'text' => '¡Con gusto! 😊 Si necesitas algo más, escribe *reiniciar* y te muestro el menú.',
                'conversation_id' => null,
                'tools_used' => ['despedida'],
            ];
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
            // También escrito: "dale, mejor por aquí" cayó al modelo porque
            // solo se aceptaba el botón exacto.
            'agendar' => match (true) {
                (bool) preg_match('/\b(aqui|chat|whatsapp)\b/u', $t) => $this->bookHere($caller, $phone),
                (bool) preg_match('/\b(web|pagina|link|enlace)\b/u', $t) => $this->bookOnWeb($caller, $phone),
                default => null,
            },
            // El título vuelve recortado ("Sáb. 26 sep. · 3:30" por "…3:30
            // pm"), así que se busca aguantando el recorte.
            'citas' => ($clave = UltimoPedido::claveDe($pedido['citas'] ?? [], $t)) !== null
                ? $this->showAppointment($caller, $phone, (int) $pedido['citas'][$clave])
                : null,
            'cita' => match ($t) {
                $this->plain(self::RESCHEDULE) => $this->moveChosen($caller, $phone, (int) $pedido['cita_id']),
                $this->plain(self::CANCEL) => $this->askCancel($caller, $phone, (int) $pedido['cita_id']),
                $this->plain(self::BACK) => $this->listAppointments($caller, $phone),
                default => null,
            },
            'cancelar' => match ($t) {
                $this->plain(self::CONFIRM_CANCEL), 'si', 'si cancelar' => $this->cancel($caller, $phone, (int) $pedido['cita_id'], (bool) ($pedido['acepta_multa'] ?? false)),
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
    private function showMenu(AiCaller $caller, string $phone, string $menu, bool $welcome = false): ?array
    {
        /*
         * Al empezar, se saluda como en el mostrador: el nombre si lo
         * sabemos, el del negocio, y la pregunta. Alejandro escribió
         * "Buenas" y recibió "¿Cómo prefieres agendar?" a secas: una
         * máquina de turnos, no un salón.
         */
        [$texto, $botones] = match ($menu) {
            'agendar' => [
                ($welcome ? $this->welcome($caller)."\n\n" : '').'¿Cómo prefieres agendar? 💅',
                [self::HERE, self::WEB],
            ],
            'consulta' => ['¡Claro! ¿Qué necesitas?', [self::WARRANTY, self::ADMIN, self::QUESTION]],
            default => [
                $this->welcome($caller)."\n\n¿Qué deseas hacer el día de hoy?"
                    ."\n\n_Escribe *reiniciar* en cualquier momento para volver a este menú._",
                [self::BOOK, self::MY_APPOINTMENTS, self::OTHER],
            ],
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

        /*
         * Tarde (dentro de la anticipación del local) SE PUEDE cancelar, con
         * multa: igual no iba a llegar, y así al menos se libera la silla.
         * La multa se dice ANTES de pedir la confirmación.
         */
        if ($this->portal->isLateCancellation($cita, $caller->business)) {
            $aviso = $this->cancellation->execute($caller, ['cita_id' => $cita->id]);

            return $this->send(
                $caller,
                $phone,
                ($aviso['motivo'] ?? 'Esta cancelación es tardía.').' ¿Cancelas de todas formas tu cita de '.$this->describe($caller, $cita).'?',
                [self::CONFIRM_CANCEL, self::KEEP],
                ['menu' => 'cancelar', 'cita_id' => $cita->id, 'acepta_multa' => true],
                'cancelar_cita',
            );
        }

        // Lo demás que impide cancelar (ya atendida, ya no activa) se dice
        // ANTES de pedir confirmación, no después de que ella dijo que sí.
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
    private function cancel(AiCaller $caller, string $phone, int $id, bool $aceptaMulta): array
    {
        $this->forgetMenu($phone);

        $resultado = $this->cancellation->execute($caller, [
            'cita_id' => $id,
            'motivo' => 'Cancelada por la clienta desde WhatsApp',
            // Solo si se le mostró la multa en la pregunta que acaba de
            // responder: sin eso, cancelar tarde vuelve a pedir el aviso.
            'acepta_multa' => $aceptaMulta,
        ]);

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

    // -- El recordatorio de la cita -----------------------------------------

    /**
     * «Confirmo que voy» y «Cancelar cita», los botones del recordatorio.
     *
     * Ahí está la diferencia entre un recordatorio que sirve y uno que no: si
     * la persona no va a poder, tiene que poder decirlo EN ESE MOMENTO. Uno
     * sin salida solo consigue que la inasistencia llegue avisada.
     *
     * @return array{text: string, conversation_id: null, tools_used: list<string>}|null
     */
    private function fromReminder(AiCaller $caller, string $phone, string $texto): ?array
    {
        $tocado = $this->plain($texto);

        if ($tocado === $this->plain(self::CONFIRM_ATTENDANCE)) {
            return $this->confirmAttendance($caller, $phone);
        }

        if ($tocado === $this->plain(self::CANCEL_APPOINTMENT)) {
            return $this->startCancel($caller, $phone);
        }

        return null;
    }

    /**
     * «Confirmo que voy»: la cita queda confirmada y el salón lo ve.
     *
     * Es lo que hoy se hace llamando una por una. Pasa por la máquina de
     * estados --no se escribe el estado a mano-- para que quede el registro
     * de quién la movió y para que el tablero del mostrador diga lo mismo
     * que la ficha.
     *
     * @return array{text: string, conversation_id: null, tools_used: list<string>}
     */
    private function confirmAttendance(AiCaller $caller, string $phone): array
    {
        $citas = $this->upcoming($caller);

        if ($citas->isEmpty()) {
            return $this->reply(
                $phone,
                '¡Gracias por avisar! 😊 No veo citas próximas a tu nombre. Si quieres, te agendo una.',
                'confirmar_asistencia',
            );
        }

        // La más cercana: el recordatorio salió por esa.
        $cita = $citas->first();

        try {
            app(StageTransitionService::class)->moveToStatus(
                $cita,
                Appointment::STATUS_CONFIRMED,
                null,
                AppointmentStageEvent::ACTOR_CLIENT,
            );
        } catch (\Throwable $e) {
            // Ya estaba confirmada, o el flujo del negocio no permite el
            // salto. Ninguna de las dos es culpa de ella ni cambia su
            // respuesta: dijo que viene, y eso ya se anotó.
            report($e);
        }

        return $this->reply(
            $phone,
            '¡Perfecto! ✅ Te esperamos '.$this->describe($caller, $cita).'. '
                .'Si algo cambia, escríbeme y la movemos.',
            'confirmar_asistencia',
        );
    }

    /**
     * «Cancelar cita» sin haber pasado por el menú.
     *
     * Con una sola cita se va directo a preguntarle si está segura --con la
     * multa por delante si es tardía--; con varias, a la lista, porque
     * cancelar la que no era es peor que un toque de más.
     *
     * @return array{text: string, conversation_id: null, tools_used: list<string>}|null
     */
    private function startCancel(AiCaller $caller, string $phone): ?array
    {
        $citas = $this->upcoming($caller);

        if ($citas->isEmpty()) {
            return $this->reply(
                $phone,
                'No veo citas próximas a tu nombre 😊 ¿Quieres agendar una?',
                'cancelar_cita',
            );
        }

        return $citas->count() === 1
            ? $this->askCancel($caller, $phone, $citas->first()->id)
            : $this->listAppointments($caller, $phone);
    }

    // -- El final de la visita ----------------------------------------------

    /**
     * «Mi tarjeta» y «Calificar servicio», los botones del gracias.
     *
     * Null = no tocó ninguno. Ambos se atienden en CUALQUIER momento, no
     * solo pegados al mensaje: la clienta puede escribir «mi tarjeta» un
     * martes cualquiera, y en ManyChat eso funcionaba.
     *
     * @return array{text: string, conversation_id: null, tools_used: list<string>}|null
     */
    private function fromThankYou(AiCaller $caller, string $phone, string $texto): ?array
    {
        $tocado = $this->plain($texto);

        if ($tocado === $this->plain(self::MY_CARD) || $tocado === 'mi tarjeta virtual' || $tocado === 'tarjeta') {
            return $this->loyaltyCard($caller, $phone);
        }

        if ($tocado === $this->plain(self::RATE)) {
            return $this->surveyLink($caller, $phone);
        }

        return null;
    }

    /**
     * Cómo va su tarjeta de sellos.
     *
     * "Te faltan 3" es una razón concreta para volver, y es información que
     * la clienta no tiene de otra forma: en el mostrador nadie se la dice.
     *
     * @return array{text: string, conversation_id: null, tools_used: list<string>}
     */
    private function loyaltyCard(AiCaller $caller, string $phone): array
    {
        $tarjeta = $caller->client !== null
            ? app(LoyaltyService::class)->cardFor($caller->client)
            : null;

        if ($tarjeta === null || (int) ($tarjeta['required'] ?? 0) < 1) {
            return $this->reply(
                $phone,
                'Por ahora no tenemos tarjeta de sellos 😊 Pero con gusto te agendo tu próxima cita.',
                'mi_tarjeta',
            );
        }

        $sellos = (int) $tarjeta['stamps'];
        $faltan = (int) $tarjeta['remaining'];
        $premio = (string) ($tarjeta['program']['reward_label'] ?? '');

        $lineas = [
            '*Tu tarjeta* 🎟️',
            '',
            sprintf('🎯 Llevas *%d de %d* sellos.', $sellos, (int) $tarjeta['required']),
        ];

        if ($premio !== '') {
            $lineas[] = '🎁 Próximo premio: *'.$premio.'*';
        }

        // Lo que ya se ganó y no ha usado: es plata suya esperando.
        $listos = collect($tarjeta['rewards'] ?? [])->pluck('label')->filter();

        if ($listos->isNotEmpty()) {
            $lineas[] = '';
            $lineas[] = '✨ ¡Ya tienes disponible: *'.$listos->implode('*, *').'*! Recuérdalo al pagar.';
        } elseif ($faltan > 0) {
            $lineas[] = '';
            $lineas[] = $faltan === 1
                ? '¡Te falta *1 sello*! 💅'
                : sprintf('Te faltan *%d sellos* 💅', $faltan);
        }

        return $this->reply($phone, implode("\n", $lineas), 'mi_tarjeta');
    }

    /**
     * El enlace para calificar su última visita.
     *
     * El token se crea acá, al tocarlo, no al mandar el gracias: así
     * `survey_sent_at` dice cuándo se le ofreció de verdad y no cuándo se
     * mandó un botón que quizá nadie tocó.
     *
     * @return array{text: string, conversation_id: null, tools_used: list<string>}
     */
    private function surveyLink(AiCaller $caller, string $phone): array
    {
        $ultima = $this->lastService($caller);

        if ($ultima === null) {
            return $this->reply(
                $phone,
                '¡Gracias por querer contarnos! 😊 Cuando tengamos tu visita registrada te mando el enlace.',
                'calificar',
            );
        }

        app(SurveyService::class)->markSent($ultima);
        $enlace = rtrim((string) config('app.frontend_url', ''), '/').'/encuesta/'.$ultima->fresh()->survey_token;

        return $this->reply(
            $phone,
            "¡Gracias! 🌟 Son 30 segundos y nos ayuda muchísimo 👇\n".$enlace,
            'calificar',
        );
    }

    // -- Cambio de número ---------------------------------------------------

    /**
     * Los dos botones del aviso de cambio de número.
     *
     * La respuesta queda en las notas de la clienta, con fecha: es lo que
     * el salón va a querer mirar ("¿esta sigue viniendo?"), y no hace falta
     * una columna para una campaña.
     *
     * @return array{text: string, conversation_id: null, tools_used: list<string>}|null
     */
    private function fromNumberChange(AiCaller $caller, string $phone, string $texto): ?array
    {
        $tocado = $this->plain($texto);

        if ($tocado === $this->plain(self::NO_LONGER_CLIENT)) {
            // La misma llave que ya frena difusiones y retoques: una sola.
            $this->noteOnClient($caller, 'Dijo que ya no viene (aviso de cambio de número). No enviarle promociones.', ['accepts_marketing' => false]);

            return $this->reply(
                $phone,
                '¡Gracias por avisarnos! 💛 No te volveremos a escribir con promociones.'
                    ."\n\nSi algún día vuelves por Sibaté, aquí estaremos. ¡Te deseamos lo mejor! ✨",
                'ya_no_es_clienta',
            );
        }

        if ($tocado === $this->plain(self::STILL_CLIENT)) {
            $this->noteOnClient($caller, 'Confirmó que sigue siendo clienta (aviso de cambio de número).');

            if ($caller->client === null) {
                return $this->welcomeBack($caller, $phone, null);
            }

            /*
             * Se aprovecha que acaba de contestar para confirmar su nombre:
             * muchas fichas vinieron de ManyChat con "?", "." o un apodo
             * (35 de las 161 del aviso), y las demás pueden estar a medias.
             * Si ya tenemos uno usable se le muestra, y un "sí" lo confirma.
             * La respuesta siguiente la atiende fromNamePrompt.
             */
            Cache::put(self::ASKING_NAME.$phone, true, now()->addHours(8));

            $actual = trim($caller->client->name.' '.$caller->client->last_name);
            $pregunta = NombreDePila::deSaludo($caller->client->name) === null
                ? '¿Nos confirmas tu nombre completo? ✍️'
                : "Te tenemos como *{$actual}*. ¿Nos confirmas tu nombre completo? ✍️";

            return $this->reply(
                $phone,
                "¡Qué alegría! 💅 Ya quedaste con nuestro número nuevo.\n\nPara tenerte bien guardada: {$pregunta}",
                'pedir_nombre',
            );
        }

        return null;
    }

    /**
     * La respuesta a "¿cómo te llamas?".
     *
     * Solo si parece un nombre: si en vez de eso escribe "quiero una cita
     * mañana", no se guarda nada y el bot la atiende como siempre -- un
     * nombre mal guardado es peor que ninguno, porque con él se la saluda.
     *
     * @return array{text: string, conversation_id: null, tools_used: list<string>}|null
     */
    private function fromNamePrompt(AiCaller $caller, string $phone, string $texto): ?array
    {
        if (! Cache::pull(self::ASKING_NAME.$phone)) {
            return null;
        }

        $client = $caller->client;

        if ($client === null) {
            return null;
        }

        // "Sí", "correcto", "así es": confirma el que ya tenemos.
        $pilaActual = NombreDePila::deSaludo($client->name);
        $dicho = trim((string) preg_replace('/[\s,]+/u', ' ', $this->plain($texto)));
        if ($pilaActual !== null && preg_match('/^(si|sip|correcto|asi es|exacto|ok|listo|ese|ese es|si senora|si senor)( es| asi| correcto| gracias)*$/u', $dicho)) {
            $this->noteOnClient($caller, 'Confirmó su nombre.');

            return $this->welcomeBack($caller, $phone, $pilaActual);
        }

        $nombre = $this->nameFrom($texto);

        if ($nombre === null) {
            return null;
        }

        // Lo que escribió es su nombre completo: reemplaza nombre y apellido
        // guardados, para no terminar con "Ana María Gómez Gómez".
        $this->noteOnClient($caller, 'Nos dio su nombre al confirmar que sigue siendo clienta.', ['name' => $nombre, 'last_name' => null]);

        return $this->welcomeBack($caller, $phone, NombreDePila::deSaludo($nombre));
    }

    /** "me llamo ana maría" -> "Ana María"; null si no parece un nombre. */
    private function nameFrom(string $texto): ?string
    {
        $t = trim((string) preg_replace('/^(hola[,! ]*)?(me llamo|mi nombre es|soy)\s+/iu', '', trim($texto)));
        $t = trim($t, " \t\n\r.,!¡?¿");

        if ($t === '' || mb_strlen($t) > 40 || count(preg_split('/\s+/u', $t)) > 4) {
            return null;
        }

        $normal = $this->plain($t);
        foreach (['cita', 'agendar', 'quiero', 'gracias', 'buenas', 'precio', 'cuanto', 'manana', 'hoy', 'servicio', 'unas'] as $palabra) {
            if (preg_match('/\b'.$palabra.'\b/u', $normal)) {
                return null;
            }
        }

        if (NombreDePila::deSaludo($t) === null) {
            return null;
        }

        return mb_convert_case(mb_strtolower($t), MB_CASE_TITLE);
    }

    /**
     * El cierre de "sí, sigo viniendo": el granizado y los dos botones del
     * menú de agendar, con ese menú guardado para el toque siguiente.
     *
     * @return array{text: string, conversation_id: null, tools_used: list<string>}
     */
    private function welcomeBack(AiCaller $caller, string $phone, ?string $pila): array
    {
        $texto = '¡Qué alegría'.($pila ? ", {$pila}" : '').'! 💅 Ya quedaste con nuestro número nuevo.'
            ."\n\nRecuerda que por hacerte cualquier servicio te llevas un granizado gratis 🍧 ¿Te agendamos tu próxima cita?";

        return $this->send($caller, $phone, $texto, [self::HERE, self::WEB], ['menu' => 'agendar'], 'sigue_siendo_clienta', fresh: true)
            ?? $this->reply($phone, $texto, 'sigue_siendo_clienta');
    }

    /**
     * Una línea con fecha al final de las notas de la clienta (y, si
     * hace falta, otros cambios). Sin clienta reconocida no hay dónde
     * anotarlo, y la respuesta sale igual.
     *
     * @param  array<string, mixed>  $cambios
     */
    private function noteOnClient(AiCaller $caller, string $nota, array $cambios = []): void
    {
        $client = $caller->client;

        if ($client === null) {
            return;
        }

        $linea = '['.now()->timezone('America/Bogota')->format('d/m/Y').'] '.$nota;
        $notas = trim((string) $client->notes);

        // Tocar dos veces el mismo botón no repite la nota.
        if (! str_contains($notas, $nota)) {
            $cambios['notes'] = $notas === '' ? $linea : $notas."\n".$linea;
        }

        if ($cambios !== []) {
            $client->forceFill($cambios)->save();
        }
    }

    // -- Retoque -----------------------------------------------------------

    /**
     * Los dos botones del recordatorio de retoque.
     *
     * Null = no tocó ninguno, o el atajo no pudo encaminarla: sigue el
     * camino de siempre.
     *
     * @return array{text: string, conversation_id: null, tools_used: list<string>}|null
     */
    private function fromRetouchReminder(AiCaller $caller, string $phone, string $texto): ?array
    {
        $tocado = $this->plain($texto);

        if ($tocado === $this->plain(self::RETOUCH)) {
            return $this->startRetouch($caller, $phone);
        }

        if ($tocado === $this->plain(self::UNSUBSCRIBE)) {
            return $this->unsubscribe($caller, $phone);
        }

        if ($tocado === $this->plain(self::RESUBSCRIBE)) {
            return $this->resubscribe($caller, $phone);
        }

        if ($tocado === $this->plain(self::FROM_SCRATCH)) {
            // Nada del retoque queda pegado: el catálogo como quien llega
            // nueva, que es lo que ese botón promete.
            UltimoPedido::olvidar($phone);

            return $this->bookHere($caller, $phone);
        }

        return null;
    }

    /**
     * «Darme de baja»: no más mensajes que el spa mande por su cuenta.
     *
     * Se apaga `accepts_marketing`, que es la misma llave que ya frena las
     * difusiones -- una sola, y no una por tipo de mensaje: quien pidió que
     * no le escriban no tiene por qué volver a pedirlo cada vez que
     * inventemos una campaña.
     *
     * Lo que SIGUE llegando es lo de sus propias citas (confirmación y
     * recordatorio). No es publicidad: es el servicio que ella pidió, y
     * callarlo la deja sin saber a qué hora es su cita.
     *
     * @return array{text: string, conversation_id: null, tools_used: list<string>}
     */
    private function unsubscribe(AiCaller $caller, string $phone): array
    {
        $caller->client?->forceFill(['accepts_marketing' => false])->save();

        $texto = 'Listo, no te enviaremos más promociones ni recordatorios de retoque ❤️'
            ."\n\nLo de tus propias citas (confirmación y recordatorio) te sigue llegando, "
            .'y aquí estoy si quieres agendar.';

        $enviado = app(EnvioDirecto::class)->opciones($caller, $texto, [
            ['id' => 'volver_a_recibir', 'title' => self::RESUBSCRIBE],
        ]);

        return $enviado
            ? ['text' => '', 'conversation_id' => null, 'tools_used' => ['darse_de_baja']]
            : $this->reply($phone, $texto, 'darse_de_baja');
    }

    /** Se arrepintió: volver a recibir tiene que ser tan fácil como salirse. */
    private function resubscribe(AiCaller $caller, string $phone): array
    {
        $caller->client?->forceFill(['accepts_marketing' => true])->save();

        return $this->reply(
            $phone,
            '¡Qué bueno tenerte de vuelta! 🌟 Te avisaremos cuando se acerque tu retoque. '
                .'Puedes darte de baja otra vez cuando quieras.',
            'volver_a_recibir',
        );
    }

    /**
     * «Agendar retoque»: mismo servicio, misma persona, solo falta el día.
     *
     * El botón de ManyChat mandaba al inicio y había que repetir todo el
     * procedimiento; esa es justo la molestia que esto quita. El servicio
     * y la profesional salen de su última visita -- que es lo que el
     * recordatorio le acaba de nombrar --, así que preguntar de nuevo
     * sería hacerle repetir lo que el salón ya sabe.
     *
     * Null = no hay última visita que copiar: sigue el camino normal.
     *
     * @return array{text: string, conversation_id: null, tools_used: list<string>}|null
     */
    private function startRetouch(AiCaller $caller, string $phone): ?array
    {
        $ultimo = $this->lastService($caller);
        $servicio = $ultimo?->items->first(fn ($i) => $i->service !== null)?->service;

        if ($servicio === null) {
            return null;
        }

        // La profesional, solo si sigue activa y se puede reservar con ella:
        // prometerle la de siempre y que no esté es peor que no ofrecerla.
        $persona = $ultimo->items->first(fn ($i) => $i->resource !== null)?->resource;
        $conElla = $persona !== null && $persona->is_active && $persona->is_bookable_online;

        UltimoPedido::guardar($phone, [
            'servicios' => [$servicio->name],
            // Ya se preguntó -- en su última visita: no se vuelve a preguntar.
            'empleado_preguntado' => true,
            ...($conElla ? ['empleado' => $persona->name] : []),
            'preludio' => sprintf(
                '¡Listo! Te repito tu *%s*%s 💅',
                $servicio->name,
                $conElla ? ' con *'.$persona->name.'*' : '',
            ),
        ]);

        // Sin fecha, la agenda pregunta el día (botones o calendario).
        $resultado = $this->agenda->execute($caller, []);

        if (empty($resultado['eligiendo_fecha']) && empty($resultado['ofrecidas'])) {
            UltimoPedido::olvidar($phone);

            return null;
        }

        return ['text' => '', 'conversation_id' => null, 'tools_used' => ['retoque']];
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

    /**
     * Las que todavía se pueden gestionar: las que NO han empezado.
     *
     * El portal muestra también las de hoy que ya pasaron (sirve para
     * verlas), pero aquí se gestionan: Alejandro abrió "Mis citas" al
     * mediodía, tocó la de las 9 am de ese mismo día y le dijeron que ya
     * no se podía cancelar -- una cita que ya había pasado.
     *
     * @return Collection<int, Appointment>
     */
    private function upcoming(AiCaller $caller): Collection
    {
        return $caller->client === null
            ? collect()
            : $this->portal->upcoming($caller->client, $caller->business)
                ->filter(fn (Appointment $c) => $c->starts_at->isFuture())
                ->values();
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

    /**
     * "¡Hola, Mateo! 👋 / Te damos la bienvenida a *Luxury Nails* 💅"
     *
     * El nombre solo si parece de persona (NombreRaro). "Te damos la
     * bienvenida" y no "Bienvenido/a": así no hay que adivinar el género
     * de quien escribe.
     */
    private function welcome(AiCaller $caller): string
    {
        $nombre = trim((string) $caller->client?->fullName());
        $hola = $nombre === '' || NombreRaro::es($nombre)
            ? '¡Hola! 👋'
            : '¡Hola, '.explode(' ', $nombre)[0].'! 👋';

        $negocio = trim((string) $caller->business->name);

        return $negocio === ''
            ? $hola
            : $hola."\nTe damos la bienvenida a *".$negocio.'* 💅';
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
        unset($pedido['menu'], $pedido['citas'], $pedido['cita_id'], $pedido['acepta_multa']);
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

    /** "Hola, quiero agendar": empieza saludando, aunque diga más. */
    private function startsWithGreeting(string $texto): bool
    {
        return (bool) preg_match('/^(hola|holi|buenas|buenos dias|buen dia|buenas tardes|buenas noches|hey)\b/u', $this->plain($texto));
    }

    /**
     * "Hola", "buenas tardes", "H hola" — un saludo y NADA MÁS.
     *
     * Se mira palabra por palabra y no desde el principio: Alejandro
     * escribió "H hola" (un dedazo) y, como no empezaba por el saludo, la
     * respuesta se la quedó el modelo en vez del menú. "Hola, quiero
     * agendar" tampoco es un saludo a secas: trae un pedido, y lo atienden
     * los atajos.
     */
    private function isGreeting(string $texto): bool
    {
        $t = trim((string) preg_replace('/[^\p{L}\s]/u', '', $this->plain($texto)));
        $palabras = array_values(array_filter(preg_split('/\s+/u', $t) ?: []));

        return $palabras !== []
            && count($palabras) <= 4
            && collect($palabras)->contains(fn ($p) => (bool) preg_match('/^(hola+|holi|buenas|buenos|dias|dia|tardes|noches|hey|saludos)$/u', $p))
            && ! $this->wantsBooking($texto)
            && ! $this->wantsMyAppointments($texto)
            && ! $this->wantsToMove($texto)
            && ! $this->wantsWarranty($texto)
            && ! $this->wantsHuman($texto);
    }

    /** "Vale", "gracias", "listo", un 👍: cierra, no pregunta. */
    private function isClosing(string $texto): bool
    {
        $t = $this->plain($texto);

        // Solo emojis (plain los quita): un 👍 es un cierre, no una charla.
        if ($t === '') {
            return mb_strlen(trim($texto)) > 0 && mb_strlen(trim($texto)) <= 8;
        }

        return (bool) preg_match(
            '/^(vale|ok|oki|okey|listo|dale|gracias|muchas gracias|mil gracias|perfecto|excelente|de una|bueno|ya|entendido)( \S+){0,2}$/u',
            $t,
        );
    }

    /** «reiniciar», «menú», «empezar de nuevo»: volver al inicio. */
    private function wantsRestart(string $texto): bool
    {
        return (bool) preg_match(
            '/^(reiniciar|reinicia|reinicio|menu|menu principal|inicio|volver al inicio|empezar de nuevo|empezar de cero)$/u',
            $this->plain($texto),
        );
    }

    /**
     * En qué iban, dicho corto: "moviendo tu cita de Semi + Rubber".
     *
     * @param  array<string, mixed>  $pedido
     */
    private function whatWasPending(array $pedido): string
    {
        $servicio = implode(' y ', array_unique($pedido['servicios'] ?? []));
        $de = $servicio !== '' ? ' de *'.$servicio.'*' : '';

        return match (true) {
            isset($pedido['mudanza']) => 'moviendo tu cita'.$de,
            isset($pedido['decidir_mover']) => 'viendo si mueves tu cita o agendas otra',
            default => 'agendando tu cita'.$de,
        };
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
