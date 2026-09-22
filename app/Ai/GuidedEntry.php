<?php

namespace App\Ai;

use App\Ai\Capabilities\AvailabilityCapability;
use App\Models\WhatsappConversation;
use App\Services\ClientPortalService;
use App\Support\ChannelPhone;

/**
 * La entrada guiada: quien llega a pedir cita empieza tocando, no chateando.
 *
 * Casi todas las conversaciones abren igual: "hola, quiero una cita",
 * "¿tienen turno?", "quiero agendar". Pasarle eso al modelo compraba dos
 * turnos seguros — "¡Hola! ¿qué servicio te gustaría?" y la clienta
 * deletreando un nombre de catálogo — antes de llegar a lo que importa.
 * Es la apertura de un flujo clásico, y los flujos clásicos esto lo hacen
 * bien: menú al primer mensaje.
 *
 * Así que el primer mensaje CON intención de cita y SIN servicio nombrado
 * recibe, sin modelo, los servicios más pedidos con precio y duración
 * (`welcomeMenu`). Todo lo demás sigue siendo conversación: si ya dijo
 * "manicure", el flujo normal filtra mejor; si dijo "mañana en la tarde",
 * DateInText ya lo dejó guardado y el menú no se lo hace repetir; si
 * viene molesta o pide el link de la página, esto ni se asoma.
 */
final class GuidedEntry
{
    /** Los tres botones del iniciador (tope de Meta: 20 caracteres). */
    public const HERE = 'Agendar por aquí';

    public const WEB = 'Agendar en la web';

    public const OTHER = 'Otra consulta';

    /** Lo que marca una gestión a MEDIAS: ahí un menú interrumpe. */
    private const PENDING = ['confirmar', 'decidir_mover', 'mudanza', 'eligiendo_fecha'];

    /** Lo que deja una gestión anterior y un "quiero una cita" nuevo borra. */
    private const LEFTOVERS = ['servicios', 'opciones', 'horas', 'todas', 'fechas', 'mostrado_at', 'fecha_iso', 'dia'];

    public function __construct(
        private readonly AvailabilityCapability $agenda,
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

        // Tocó uno de los botones del iniciador.
        $eleccion = $this->chosen($caller, $phone, $texto);

        if ($eleccion !== null) {
            return $eleccion;
        }

        // "Quiero cambiar mi cita" tiene su propio riel: directo a horas.
        if ($this->wantsToMove($texto)) {
            $mudanza = $this->startMove($caller, $phone);

            if ($mudanza !== null) {
                return $mudanza;
            }
        }

        $pedido = UltimoPedido::ver($phone);

        if (array_intersect(array_keys($pedido), self::PENDING) !== []) {
            return null;
        }

        /*
         * El iniciador: "¿Cómo prefieres agendar?" con Agendar por aquí /
         * Agendar en la web / Otra consulta — la puerta que las clientas
         * conocen de ManyChat. Sale con un "quiero una cita" o con un
         * saludo a secas, a cualquier hora (salvo gestión a medias, ya
         * descartada arriba). Antes solo con la conversación fría, y quien
         * conversa seguido -- Alejandro probando -- nunca la vio: sus
         * saludos iban al modelo, que contestaba dos veces lo mismo, y el
         * cortador de bucles lo dejaba en pausa.
         */
        $pideCita = $this->wantsBooking($texto);

        if (! $pideCita && ! $this->isGreeting($texto)) {
            return null;
        }

        // "quiero semi mañana" ya dijo qué: el camino normal lo lleva mejor.
        if ($pideCita && $this->agenda->mentionsService($caller, $texto)) {
            return null;
        }

        return $this->offerChoice($caller, $phone, $pedido, $pideCita);
    }

    /**
     * Manda los tres botones del iniciador.
     *
     * @param  array<string, mixed>  $pedido
     * @return array{text: string, conversation_id: null, tools_used: list<string>}|null
     */
    private function offerChoice(AiCaller $caller, string $phone, array $pedido, bool $pideCita): ?array
    {
        $nombre = trim((string) $caller->client?->fullName());
        $saludo = $nombre === '' || NombreRaro::es($nombre)
            ? '¡Hola! 💅'
            : '¡Hola, '.explode(' ', $nombre)[0].'! 💅';

        $enviado = app(EnvioDirecto::class)->opciones(
            $caller,
            $saludo.($pideCita ? ' ¿Cómo prefieres agendar?' : ' ¿En qué te puedo ayudar?'),
            [
                ['id' => 'aqui', 'title' => self::HERE],
                ['id' => 'web', 'title' => self::WEB],
                ['id' => 'otra', 'title' => self::OTHER],
            ],
        );

        if (! $enviado) {
            return null;
        }

        // Un "quiero una cita" nuevo empieza de cero: lo de la gestión
        // anterior no puede colarse en la nueva. La fecha que haya dicho
        // en ESTE mensaje (DateInText) sí se queda.
        UltimoPedido::guardar($phone, [
            ...array_diff_key($pedido, array_flip(self::LEFTOVERS)),
            'eligiendo_canal' => true,
        ]);

        return ['text' => '', 'conversation_id' => null, 'tools_used' => ['iniciador']];
    }

    /**
     * Lo que pasa al tocar un botón del iniciador.
     *
     * @return array{text: string, conversation_id: null, tools_used: list<string>}|null
     */
    private function chosen(AiCaller $caller, string $phone, string $texto): ?array
    {
        $pedido = UltimoPedido::ver($phone);

        if (empty($pedido['eligiendo_canal'])) {
            return null;
        }

        $t = $this->plain(trim((string) collect(preg_split('/\r?\n/', $texto))->filter(fn ($l) => trim($l) !== '')->last()));
        unset($pedido['eligiendo_canal']);

        if (in_array($t, ['agendar por aqui', 'por aqui', 'aqui'], true)) {
            UltimoPedido::guardar($phone, $pedido);

            if ($this->agenda->welcomeMenu($caller, '') !== null) {
                return ['text' => '', 'conversation_id' => null, 'tools_used' => ['menu_inicial']];
            }

            return ['text' => '¡Claro! ¿Qué servicio te quieres hacer y para qué día? 💅', 'conversation_id' => null, 'tools_used' => ['iniciador']];
        }

        if (in_array($t, ['agendar en la web', 'en la web', 'web', 'pagina', 'link'], true)) {
            UltimoPedido::guardar($phone, $pedido);
            $link = $this->bookingLink($caller);

            if ($link === null) {
                // Sin página configurada, se agenda por aquí.
                return $this->agenda->welcomeMenu($caller, '') !== null
                    ? ['text' => '', 'conversation_id' => null, 'tools_used' => ['menu_inicial']]
                    : null;
            }

            return [
                'text' => "Aquí puedes ver la agenda completa y reservar tú misma 👇\n{$link}\n\nSi prefieres, también te agendo por aquí 😊",
                'conversation_id' => null,
                'tools_used' => ['agenda_web'],
            ];
        }

        if (in_array($t, ['otra consulta', 'otra', 'consulta'], true)) {
            UltimoPedido::guardar($phone, $pedido);

            return ['text' => '¡Claro! Cuéntame, ¿en qué te ayudo? 😊', 'conversation_id' => null, 'tools_used' => ['iniciador']];
        }

        // Escribió otra cosa en vez de tocar: es conversación, y la marca
        // se va para no atrapar el mensaje siguiente.
        UltimoPedido::guardar($phone, $pedido);

        return null;
    }

    private function bookingLink(AiCaller $caller): ?string
    {
        $base = rtrim((string) config('spa.public_booking_url'), '/');
        $slug = (string) ($caller->business->slug ?? '');

        return ($base === '' || $slug === '') ? null : "{$base}/reservar/{$slug}";
    }

    /** "Hola", "buenas tardes", "buen día 🙏" — un saludo y nada más. */
    private function isGreeting(string $texto): bool
    {
        $t = trim((string) preg_replace('/[^\p{L}\s]/u', '', $this->plain($texto)));

        return mb_strlen($t) <= 40
            && (bool) preg_match('/^(hola|holi|holaa+|buenas|buenos dias|buen dia|buenas tardes|buenas noches|hey|que tal)(\s+\w+){0,3}$/u', $t);
    }

    /**
     * Mover una cita, por el riel y no por el modelo.
     *
     * Laura, tres corridas seguidas: "quiero cambiar mi cita de mañana"
     * y el modelo respondía "¿a qué hora te gustaría?" en vez de MIRAR
     * la agenda -- y de ahí al bucle. Si tiene UNA cita próxima y pidió
     * moverla, no hay nada que interpretar: se le muestran las horas del
     * día que dijo (o los botones de día), y el «Sí, agendar» de ese
     * pedido ejecuta reagendar_cita, no crear_cita (ver Toques).
     *
     * Con varias citas el modelo sigue al mando: elegir cuál sí es
     * conversación.
     *
     * @return array{text: string, conversation_id: null, tools_used: list<string>}|null
     */
    private function startMove(AiCaller $caller, string $phone): ?array
    {
        if ($caller->client === null) {
            return null;
        }

        $pedido = UltimoPedido::ver($phone);

        if (array_intersect(array_keys($pedido), ['confirmar', 'decidir_mover', 'mudanza']) !== []) {
            return null;
        }

        $citas = $this->portal->upcoming($caller->client, $caller->business);

        if ($citas->count() !== 1) {
            return null;
        }

        $cita = $citas->first();

        if (! $this->portal->canBeChanged($cita, $caller->business)) {
            return null;
        }

        $tz = $caller->business->businessTimezone();

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

        // Con fecha dicha van directo las horas; sin fecha, los botones
        // de día. Ambas rutas ya son de la agenda.
        $resultado = $this->agenda->execute($caller, []);

        if (! empty($resultado['ofrecidas']) || ! empty($resultado['eligiendo_fecha']) || ! empty($resultado['eligiendo_servicio'])) {
            return ['text' => '', 'conversation_id' => null, 'tools_used' => ['mover_cita']];
        }

        if (isset($resultado['servicios'], $resultado['dia']) && ($resultado['horas'] ?? []) === []) {
            return [
                'text' => sprintf('Para mover tu cita de *%s*: el *%s* no me quedan horas 😕 ¿Te sirve otro día?', implode(' y ', $resultado['servicios']), $resultado['dia']),
                'conversation_id' => null,
                'tools_used' => ['mover_cita'],
            ];
        }

        // No se pudo encaminar: que lo lleve el modelo, sin la marca.
        UltimoPedido::guardar($phone, $pedido);

        return null;
    }

    /**
     * ¿Pidió mover una cita que ya tiene?
     */
    private function wantsToMove(string $texto): bool
    {
        $t = $this->plain($texto);

        return (bool) preg_match('/\b(mover|moverla|cambiar|cambiarla|pasar|pasarla|correr|correrla|reagendar|aplazar|adelantar)\b/u', $t)
            && (bool) preg_match('/\b(cita|turno|hora|reserva)\b/u', $t);
    }

    /**
     * ¿Vino a agendar, sin decir todavía qué?
     */
    private function wantsBooking(string $texto): bool
    {
        $t = $this->plain($texto);

        // Pide la página: la salida más barata es el link, no un menú.
        if (preg_match('/\b(link|enlace|pagina|web|sitio)\b/u', $t)) {
            return false;
        }

        return (bool) preg_match(
            '/\b(cita|citas|agendar|agendarme|agendame|agenda|turno|turnos|reserva|reservar|disponibilidad|cupo|espacio)\b/u',
            $t,
        );
    }

    private function plain(string $texto): string
    {
        $t = mb_strtolower(trim($texto));

        return strtr($t, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n']);
    }
}
