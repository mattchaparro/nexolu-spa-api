<?php

namespace App\Ai;

use App\Ai\Capabilities\AvailabilityCapability;
use App\Models\Message;
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
    /** Antes de esto, la conversación sigue caliente y el menú estorba. */
    private const COLD_AFTER_HOURS = 6;

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

        // "Quiero cambiar mi cita" tiene su propio riel: directo a horas.
        if ($this->wantsToMove($texto)) {
            $mudanza = $this->startMove($caller, $phone);

            if ($mudanza !== null) {
                return $mudanza;
            }
        }

        if (! $this->wantsBooking($texto) || ! $this->isColdOpen($conversacion, $phone)) {
            return null;
        }

        if ($this->agenda->welcomeMenu($caller, $texto) === null) {
            // Ya nombró el servicio, o el canal no pudo: que hable el modelo.
            return null;
        }

        return ['text' => '', 'conversation_id' => null, 'tools_used' => ['menu_inicial']];
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

        UltimoPedido::guardar($phone, [
            ...$pedido,
            'servicios' => $cita->items->map(fn ($i) => $i->service?->name)->filter()->unique()->values()->all(),
            'mudanza' => ['id' => $cita->id],
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

    /**
     * ¿Es el arranque de una gestión, o el medio de una?
     *
     * En el medio de una gestión (ya hay servicios, opciones o una
     * confirmación esperando) o de una conversación reciente con el
     * agente, meter un menú es interrumpir. La fecha/franja que DateInText
     * haya guardado del MISMO mensaje no cuenta como gestión en curso.
     */
    private function isColdOpen(WhatsappConversation $conversacion, string $phone): bool
    {
        $pedido = UltimoPedido::ver($phone);

        if (array_intersect(array_keys($pedido), ['servicios', 'opciones', 'horas', 'confirmar', 'decidir_mover']) !== []) {
            return false;
        }

        $ultimaDelAgente = Message::withoutGlobalScope('business')
            ->where('conversation_id', $conversacion->id)
            ->where('direction', Message::DIRECTION_OUT)
            ->where('kind', Message::KIND_AGENT)
            ->latest('id')
            ->first();

        return $ultimaDelAgente === null
            || $ultimaDelAgente->created_at->lte(now()->subHours(self::COLD_AFTER_HOURS));
    }

    private function plain(string $texto): string
    {
        $t = mb_strtolower(trim($texto));

        return strtr($t, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n']);
    }
}
