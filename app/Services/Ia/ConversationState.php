<?php

namespace App\Services\Ia;

use App\Ai\ServiciosPendientes;
use App\Ai\UltimoPedido;
use App\Models\Appointment;
use App\Models\Message;
use App\Models\WhatsappConversation;
use App\Support\Scheduling\AppointmentStateMachine;
use Carbon\CarbonImmutable;

/**
 * Lo que ya pasó en esta conversación, para el modelo.
 *
 * El modelo solo ve sus propios turnos. Lo que la clienta eligió tocando
 * botones -- el servicio, el día, la persona, las horas que vio -- pasa por
 * Toques y GuidedEntry, no por él. La auditoría del 13-oct lo mostró en 10
 * de 32 conversaciones: tocaba «Capping», escribía «sábado en la mañana» y
 * el modelo le preguntaba qué servicio quería. Y en otra escribió «estoy
 * afuera del local para mi cita» y le contestaron que no tenía citas.
 *
 * Va como DATOS (ver ia-core, `conversation_state`), con la regla de no
 * volver a preguntar lo que ya está aquí.
 */
class ConversationState
{
    private const MAX_CHARS = 3800;

    private const MENSAJES = 12;

    public function for(WhatsappConversation $conversacion): string
    {
        $business = $conversacion->business;
        $tz = $business->businessTimezone();

        $partes = array_filter([
            $this->citas($conversacion, $tz),
            $this->pedido((string) $conversacion->phone),
        ]);

        $transcript = $this->ultimosMensajes($conversacion, $tz);
        $cabeza = implode("\n\n", $partes);
        $espacio = self::MAX_CHARS - mb_strlen($cabeza) - 2;

        if ($transcript !== '' && $espacio > 200) {
            // Si no cabe todo, se queda lo más reciente.
            $partes[] = mb_strlen($transcript) > $espacio
                ? '…'.mb_substr($transcript, -($espacio - 1))
                : $transcript;
        }

        return mb_substr(implode("\n\n", $partes), 0, self::MAX_CHARS);
    }

    /** Sus citas de hoy (también la que empezó hace un rato) y las próximas. */
    private function citas(WhatsappConversation $conversacion, string $tz): ?string
    {
        if ($conversacion->client_id === null) {
            return null;
        }

        $ahora = CarbonImmutable::now($tz);

        $citas = Appointment::withoutGlobalScopes()
            ->where('business_id', $conversacion->business_id)
            ->where('client_id', $conversacion->client_id)
            ->whereIn('status', [Appointment::STATUS_PENDING, Appointment::STATUS_CONFIRMED, Appointment::STATUS_IN_PROGRESS])
            // La que empezó hace un rato todavía es «su cita»: «estoy afuera»
            // a las 2:03 es sobre la de las 2:00.
            ->where('starts_at', '>=', $ahora->subHours(4)->utc())
            ->with(['items.service', 'items.resource'])
            ->orderBy('starts_at')
            ->limit(4)
            ->get();

        if ($citas->isEmpty()) {
            return 'Citas de esta clienta: ninguna próxima.';
        }

        $lineas = $citas->map(function (Appointment $c) use ($tz, $ahora) {
            $inicio = CarbonImmutable::parse($c->starts_at)->setTimezone($tz);
            $cuando = $inicio->isSameDay($ahora) ? 'HOY' : $inicio->locale('es')->isoFormat('dddd D [de] MMMM');
            $servicios = $c->items->map(fn ($i) => $i->service?->name)->filter()->unique()->implode(' y ');
            $con = $c->items->map(fn ($i) => $i->resource?->name)->filter()->unique()->implode(' y ');
            $empezo = $inicio->isPast()
                ? ' (empezó hace '.(int) $inicio->diffInMinutes($ahora).' min)'
                : '';

            return sprintf(
                '- %s %s: %s%s, %s (cita #%d)%s',
                $cuando,
                $inicio->format('g:i a'),
                $servicios ?: 'cita',
                $con ? ' con '.$con : '',
                mb_strtolower(AppointmentStateMachine::label($c->status)),
                $c->id,
                $empezo,
            );
        });

        return "Citas de esta clienta:\n".$lineas->implode("\n");
    }

    /** Lo que ya eligió en el agendamiento en curso. */
    private function pedido(string $phone): ?string
    {
        $p = UltimoPedido::ver($phone);
        $lineas = [];

        if (! empty($p['servicios'])) {
            $lineas[] = 'Servicio(s) ya elegido(s): '.implode(' y ', (array) $p['servicios']);
        } elseif (! empty($p['opciones'])) {
            $lineas[] = 'Se le mostraron para elegir: '.implode(', ', array_slice((array) $p['opciones'], 0, 10));
        }

        if (ServiciosPendientes::ver($phone) !== []) {
            $lineas[] = 'Está viendo el catálogo por páginas («Muéstrame más servicios»).';
        }

        if (! empty($p['dia']) || ! empty($p['fecha'])) {
            $lineas[] = 'Día: '.($p['dia'] ?? $p['fecha']);
        }

        if (! empty($p['franja'])) {
            $lineas[] = 'Franja: '.$p['franja'];
        }

        if (! empty($p['empleado'])) {
            $lineas[] = 'Con: '.$p['empleado'];
        }

        if (! empty($p['horas'])) {
            $lineas[] = 'Horas que se le mostraron como botones: '.implode(', ', array_keys((array) $p['horas']));
        }

        if (! empty($p['confirmar']['hora'])) {
            $lineas[] = 'Se le pidió confirmar la cita a las '.$p['confirmar']['hora'].' (falta su sí).';
        }

        if (! empty($p['sin_horas'])) {
            $lineas[] = 'Se le dijo que no había horas ese día; se le ofrecieron otro día u otra persona.';
        }

        if (! empty($p['para_quien'])) {
            $lineas[] = 'Es para: '.$p['para_quien'];
        }

        return $lineas === [] ? null : "Agendamiento en curso:\n- ".implode("\n- ", $lineas);
    }

    /** Los últimos mensajes de todos: clienta, bot y equipo. */
    private function ultimosMensajes(WhatsappConversation $conversacion, string $tz): string
    {
        $mensajes = Message::withoutGlobalScopes()
            ->where('conversation_id', $conversacion->id)
            ->where('created_at', '>=', now()->subDay())
            ->latest('id')
            ->limit(self::MENSAJES)
            ->get()
            ->reverse();

        if ($mensajes->isEmpty()) {
            return '';
        }

        $lineas = $mensajes->map(function (Message $m) use ($tz) {
            $quien = match (true) {
                $m->direction === Message::DIRECTION_IN => 'Clienta',
                $m->kind === Message::KIND_HUMAN => 'Equipo (persona)',
                $m->kind === Message::KIND_STAFF => 'Nota interna',
                default => 'Bot',
            };
            $texto = trim((string) preg_replace('/\s+/u', ' ', (string) $m->body));

            return '['.$m->created_at?->setTimezone($tz)->format('H:i').'] '.$quien.': '.mb_substr($texto, 0, 220);
        });

        return "Últimos mensajes:\n".$lineas->implode("\n");
    }
}
