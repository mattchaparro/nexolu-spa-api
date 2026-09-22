<?php

namespace App\Ai;

use App\Ai\Capabilities\AvailabilityCapability;
use App\Models\Message;
use App\Models\WhatsappConversation;
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

    public function __construct(private readonly AvailabilityCapability $agenda) {}

    /**
     * El mismo contrato que Toques: null = esto no es para mí.
     *
     * @return array{text: string, conversation_id: null, tools_used: list<string>}|null
     */
    public function attend(WhatsappConversation $conversacion, string $texto): ?array
    {
        $business = $conversacion->business;
        $phone = ChannelPhone::normalize((string) $conversacion->phone, $business->country_code ?? 'CO');

        if ($phone === null || ! $this->wantsBooking($texto) || ! $this->isColdOpen($conversacion, $phone)) {
            return null;
        }

        $caller = AiCaller::customer($business, $phone, $conversacion->client, 'whatsapp');

        if ($this->agenda->welcomeMenu($caller, $texto) === null) {
            // Ya nombró el servicio, o el canal no pudo: que hable el modelo.
            return null;
        }

        return ['text' => '', 'conversation_id' => null, 'tools_used' => ['menu_inicial']];
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
