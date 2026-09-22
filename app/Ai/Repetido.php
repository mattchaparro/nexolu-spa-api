<?php

namespace App\Ai;

use App\Models\Message;
use App\Models\WhatsappConversation;

/**
 * El bot repitiendo lo que ya dijo no es una respuesta: es un bucle.
 *
 * Gloria, simulada, dijo cuatro veces "en la sede principal, para mañana
 * por la mañana" y cuatro veces recibió la misma pregunta. A Andrea le
 * llegó dos veces, letra por letra, el mismo "¡qué bueno verte por
 * aquí!". Cuando el modelo pierde el hilo, insistir no lo recupera: cada
 * vuelta le cuesta un turno a la clienta y una clienta al negocio.
 *
 * Esto mira lo ÚLTIMO que el agente mandó en esa conversación y, si lo
 * que está por salir es lo mismo, corta: en vez del eco le manda el
 * enlace para agendar sola, pausa al agente y deja la conversación
 * pendiente en la bandeja para que la termine una persona. La salida
 * más barata que hay -- cero tokens, cero vueltas -- y la única honesta
 * cuando ya se demostró que por chat no está saliendo.
 */
final class Repetido
{
    /**
     * Un bucle es repetir lo que se ACABA de decir. Era media hora y
     * mirando tres respuestas: Alejandro volvió a saludar 20 minutos
     * después, el bot le devolvió el mismo saludo de antes, esto lo tomó
     * por bucle y lo dejó en pausa dos horas -- y nadie le contestó más.
     */
    private const VENTANA_MINUTOS = 10;

    /** Solo contra la ÚLTIMA respuesta: la inmediatamente anterior. */
    private const CUANTAS_MIRAR = 1;

    /**
     * Pausa corta: esta pausa la decide la máquina, no la clienta. Si
     * nadie la atiende, al rato el bot vuelve a estar para ella en vez
     * de dejarla dos horas sin respuesta.
     */
    private const PAUSA_MINUTOS = 15;

    /**
     * Por debajo de esto no se corta nada: "¿Para qué día?" repetido
     * puede ser legítimo (la clienta cambió de servicio, por ejemplo).
     */
    private const MINIMO_CHARS = 25;

    /**
     * Si el texto es un eco, lo que se manda en su lugar; null si no lo es.
     *
     * `$recientes` es para el simulador, que no persiste las respuestas
     * del modelo como mensajes: le pasa las últimas del transcript. En
     * producción se deja en null y se miran las del hilo.
     *
     * @param  list<string>|null  $recientes
     */
    public function atajar(WhatsappConversation $conversacion, string $texto, ?array $recientes = null, ?string $entrante = null): ?string
    {
        if (mb_strlen(trim($texto)) < self::MINIMO_CHARS) {
            return null;
        }

        /*
         * Si ella escribió algo cortito ("hola", "buenas noches", "ok"),
         * que el bot conteste lo mismo que antes no es un bucle: es una
         * respuesta igual a un mensaje igual. Bucle es cuando ella DIJO
         * algo y el bot no avanza.
         */
        if ($entrante !== null && count(preg_split('/\s+/u', trim($entrante)) ?: []) <= 3) {
            return null;
        }

        $recientes ??= Message::withoutGlobalScope('business')
            ->where('conversation_id', $conversacion->id)
            ->where('direction', Message::DIRECTION_OUT)
            ->where('kind', Message::KIND_AGENT)
            ->where('created_at', '>=', now()->subMinutes(self::VENTANA_MINUTOS))
            ->orderByDesc('id')
            ->limit(self::CUANTAS_MIRAR)
            ->pluck('body')
            ->all();

        $plano = $this->plano($texto);

        $esEco = collect($recientes)
            ->reverse()
            ->take(self::CUANTAS_MIRAR)
            ->contains(fn ($b) => $this->plano((string) $b) === $plano);

        if (! $esEco) {
            return null;
        }

        $this->dejarlaEnLaBandeja($conversacion);

        $link = $this->linkDeAgenda($conversacion);

        if ($link === null) {
            return 'Creo que no te estoy entendiendo bien 😅 Ya le avisé a alguien del equipo '
                .'para que te escriba y te ayude directamente.';
        }

        return "Creo que no te estoy entendiendo bien 😅 Si prefieres, aquí puedes ver la agenda y reservar tú misma:\n"
            .$link
            ."\nIgual ya le avisé a alguien del equipo para que te escriba.";
    }

    /**
     * Lo mismo que hace `hablar_con_persona`: el agente se calla y la
     * conversación salta como pendiente para que la atienda una persona.
     */
    private function dejarlaEnLaBandeja(WhatsappConversation $conversacion): void
    {
        $conversacion->pauseAgent(self::PAUSA_MINUTOS);

        Message::create([
            'business_id' => $conversacion->business_id,
            'conversation_id' => $conversacion->id,
            'client_id' => $conversacion->client_id,
            'kind' => Message::KIND_STAFF,
            'direction' => Message::DIRECTION_OUT,
            'to' => $conversacion->phone,
            'body' => '⚑ El bot entró en bucle (iba a repetir su última respuesta). '
                .'Le mandé el enlace de la agenda y quedó en pausa '.self::PAUSA_MINUTOS
                .' minutos: si puedes, respóndele tú.',
            // Nota interna: nace enviada para que el outbox no la despache.
            'status' => Message::STATUS_SENT,
            'sent_at' => now(),
        ]);

        $conversacion->update([
            'last_message_at' => now(),
            'read_at' => null,
            'status' => WhatsappConversation::STATUS_OPEN,
        ]);
    }

    private function linkDeAgenda(WhatsappConversation $conversacion): ?string
    {
        $base = rtrim((string) config('spa.public_booking_url'), '/');
        $slug = (string) ($conversacion->business->slug ?? '');

        return ($base === '' || $slug === '') ? null : "{$base}/reservar/{$slug}";
    }

    private function plano(string $texto): string
    {
        $t = mb_strtolower(trim($texto));
        $t = strtr($t, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n']);

        return preg_replace('/\s+/u', ' ', $t) ?? $t;
    }
}
