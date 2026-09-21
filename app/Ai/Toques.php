<?php

namespace App\Ai;

use App\Ai\Capabilities\AvailabilityCapability;
use App\Ai\Capabilities\CreateAppointmentCapability;
use App\Models\Message;
use App\Models\WhatsappConversation;
use App\Services\WhatsApp\NexoluCommsChannel;
use App\Support\ChannelPhone;

/**
 * Lo que la clienta TOCÓ no pasa por el modelo.
 *
 * Después de arreglar la memoria del pedido, las clientas simuladas
 * seguían cayéndose en el mismo sitio: tocaban una hora ("6:15 pm") y el
 * modelo no sabía qué hacer con dos palabras sueltas. Volvía a mandar la
 * misma lista tres veces, o preguntaba "¿qué servicio y qué día?", o
 * decía que ya no había horas. Cinco de ocho se fueron ahí.
 *
 * Un botón tocado es un dato estructurado: sabemos exactamente qué se le
 * ofreció y qué eligió. Dárselo al modelo para que lo "interprete" es
 * pedirle que adivine algo que ya sabemos. Así lo hacen todas las
 * plataformas que mezclan flujos y lenguaje libre: los botones los
 * atiende el flujo, el texto libre el modelo.
 *
 * Esto atiende, sin modelo, la cadena completa que sigue a una lista:
 *  - tocó un servicio → se buscan las horas (con el día que ya había dicho);
 *  - tocó una hora → se le confirma en una frase, con botones Sí / Otra hora;
 *  - tocó Sí → se agenda y se le dice;
 *  - tocó Otra hora → se le vuelven a mandar las horas;
 *  - tocó "Muéstrame más servicios" → la siguiente tanda.
 *
 * Todo lo demás -- cualquier texto que no sea exactamente una opción
 * ofrecida -- sigue yendo al modelo, que es lo que sabe hacer bien.
 */
final class Toques
{
    /** Lo que dice el botón de confirmar. */
    public const SI = 'Sí, agendar';

    /** Lo que dice el botón de pedir otras horas. */
    public const OTRA_HORA = 'Otra hora';

    public function __construct(
        private readonly AvailabilityCapability $disponibilidad,
        private readonly CreateAppointmentCapability $reserva,
        private readonly NexoluCommsChannel $channel,
    ) {}

    /**
     * Atiende el mensaje si es un toque sobre algo ofrecido.
     *
     * Devuelve lo mismo que `IaCoreClient::ask` para que quien llama no
     * tenga que distinguir quién respondió; null si el mensaje no es un
     * toque y le toca al modelo.
     *
     * @return array{text: string, conversation_id: null, tools_used: list<string>}|null
     */
    public function atender(WhatsappConversation $conversacion, string $texto): ?array
    {
        $business = $conversacion->business;
        $phone = ChannelPhone::normalize((string) $conversacion->phone, $business->country_code ?? 'CO');

        if ($phone === null) {
            return null;
        }

        $pedido = UltimoPedido::ver($phone);

        if ($pedido === []) {
            return null;
        }

        $plano = $this->plano($texto);
        $caller = AiCaller::customer($business, $phone, $conversacion->client, 'whatsapp');

        // 1) Había una confirmación esperando.
        if (isset($pedido['confirmar'])) {
            if (in_array($plano, ['si, agendar', 'si agendar', 'si', 'dale', 'confirmo', 'confirmar', 'listo', 'ok', 'de una', 'agendame', 'agendala'], true)) {
                return $this->agendar($caller, $conversacion, $phone, $pedido);
            }

            if (in_array($plano, ['otra hora', 'cambiar hora', 'otra', 'no', 'no, otra hora'], true)) {
                unset($pedido['confirmar']);

                return $this->otrasHoras($conversacion, $phone, $pedido);
            }

            // Escribió otra cosa: eso sí es conversación, y es del modelo.
            return null;
        }

        // 2) Tocó una hora de las que se le mostraron.
        if (! empty($pedido['horas']) && isset($pedido['horas'][$plano])) {
            return $this->confirmar($conversacion, $phone, $pedido, $pedido['horas'][$plano]);
        }

        // 3) Tocó un servicio de una lista de servicios.
        $ofrecidos = array_map(fn ($o) => $this->plano((string) $o), $pedido['opciones'] ?? []);

        if (empty($pedido['servicios']) && in_array($plano, $ofrecidos, true)) {
            return $this->respuestaDe(
                $this->disponibilidad->execute($caller, ['servicio' => $texto]),
                'elegir_servicio',
            );
        }

        // 4) Pidió la siguiente tanda de servicios.
        if (ServiciosPendientes::pideVerMas($texto) && ServiciosPendientes::ver($phone) !== []) {
            return $this->respuestaDe($this->disponibilidad->execute($caller, ['servicio' => $texto]), 'mas_servicios');
        }

        return null;
    }

    /**
     * "Te confirmo: X el día D a las H con P. ¿Lo agendo?" con dos botones.
     *
     * @param  array<string, mixed>  $pedido
     * @param  array{hora_24: string, hora: string, con?: string}  $hora
     * @return array{text: string, conversation_id: null, tools_used: list<string>}
     */
    private function confirmar(WhatsappConversation $conversacion, string $phone, array $pedido, array $hora): array
    {
        $texto = sprintf(
            'Te confirmo: *%s* el *%s* a las *%s*%s. ¿Lo agendo?',
            $this->nombreDe($pedido),
            $pedido['dia'] ?? $pedido['fecha'],
            $hora['hora'],
            empty($hora['con']) ? '' : ' con *'.$hora['con'].'*',
        );

        $enviado = $this->channel->sendOptions(
            $phone,
            $texto,
            [
                ['id' => 'si', 'title' => self::SI],
                ['id' => 'otra', 'title' => self::OTRA_HORA],
            ],
            $conversacion->business_id,
        );

        if (! $enviado) {
            // Sin canal no hay botones: que el modelo lo lleve en palabras.
            return null;
        }

        OpcionesEnviadas::marcar($phone);
        UltimoPedido::guardar($phone, [...$pedido, 'confirmar' => $hora]);
        $this->anotar($conversacion, $phone, $texto."\n\n▸ ".self::SI."\n▸ ".self::OTRA_HORA);

        return ['text' => '', 'conversation_id' => null, 'tools_used' => ['confirmar_hora']];
    }

    /**
     * "Otra hora": las que NO se le mostraron todavía.
     *
     * Volver a mandar las mismas cuatro sería contestarle "otra" con lo
     * mismo. La agenda ya devolvió todas las horas del día; se guardaron
     * junto con las mostradas, así que acá no hay que consultar nada.
     *
     * @param  array<string, mixed>  $pedido
     * @return array{text: string, conversation_id: null, tools_used: list<string>}
     */
    private function otrasHoras(WhatsappConversation $conversacion, string $phone, array $pedido): array
    {
        $yaVistas = array_keys($pedido['horas'] ?? []);
        $otras = array_slice(
            array_filter($pedido['todas'] ?? [], fn ($h, $k) => ! in_array($k, $yaVistas, true), ARRAY_FILTER_USE_BOTH),
            0,
            4,
            true,
        );

        if ($otras === []) {
            UltimoPedido::olvidar($phone);

            return [
                'text' => sprintf('Esas eran todas las horas que tengo para *%s* el *%s* 😕 ¿Te sirve otro día?', $this->nombreDe($pedido), $pedido['dia'] ?? $pedido['fecha']),
                'conversation_id' => null,
                'tools_used' => ['otra_hora'],
            ];
        }

        $texto = sprintf('Otras horas para *%s* el *%s* 👇', $this->nombreDe($pedido), $pedido['dia'] ?? $pedido['fecha']);
        $filas = [];
        $i = 0;

        foreach ($otras as $h) {
            $filas[] = array_filter([
                'id' => 'h'.($i++),
                'title' => $h['hora'],
                'description' => empty($h['con']) ? null : 'con '.$h['con'],
            ]);
        }

        if (! $this->channel->sendOptions($phone, $texto, $filas, $conversacion->business_id, 'Ver horas')) {
            return [
                'text' => $texto."\n".implode("\n", array_map(fn ($h) => '⏰ '.$h['hora'], $otras)),
                'conversation_id' => null,
                'tools_used' => ['otra_hora'],
            ];
        }

        OpcionesEnviadas::marcar($phone);
        UltimoPedido::guardar($phone, [...$pedido, 'horas' => $otras, 'mostrado_at' => now()->toIso8601String()]);
        $this->anotar($conversacion, $phone, $texto."\n\n".implode("\n", array_map(fn ($h) => '▸ '.$h['hora'], $otras)));

        return ['text' => '', 'conversation_id' => null, 'tools_used' => ['otra_hora']];
    }

    /**
     * @param  array<string, mixed>  $pedido
     * @return array{text: string, conversation_id: null, tools_used: list<string>}
     */
    private function agendar(AiCaller $caller, WhatsappConversation $conversacion, string $phone, array $pedido): array
    {
        $hora = $pedido['confirmar'];

        /*
         * Con quien se le ofrecio, si fue una sola persona. La agenda dijo
         * "2:30 pm con Anyi"; reservar sin decir con quien dejaba que la
         * reserva tomara a la primera del servicio, que a esa hora no
         * trabaja, y dos clientas simuladas "agendaron" en el vacio.
         */
        $con = (string) ($hora['con'] ?? '');
        $argumentos = [...$this->argumentosDe($pedido), 'hora' => $hora['hora_24']];

        if ($con !== '' && ! str_contains($con, ' y ') && empty($argumentos['empleado']) && empty($argumentos['juntas'])) {
            $argumentos['empleado'] = $con;
        }

        $resultado = $this->reserva->execute($caller, $argumentos);

        if (! ($resultado['agendada'] ?? false)) {
            /*
             * Se ocupo mientras decidia, o no alcanzan los servicios a esa
             * hora. Se dice -- callarse aqui es dejarla creyendo que
             * agendo -- y se le vuelven a mandar las horas que si hay,
             * consultadas de nuevo (sin la guarda de "ya las vio": la
             * situacion cambio).
             */
            unset($pedido['confirmar'], $pedido['mostrado_at']);
            UltimoPedido::guardar($phone, $pedido);

            $otras = $this->disponibilidad->execute($caller, $this->argumentosDe($pedido));
            $aviso = 'No pude dejar esa hora 😕 ('.mb_strtolower(rtrim((string) ($resultado['motivo'] ?? 'se acabó de ocupar'), '.')).').';

            if (($otras['ofrecidas'] ?? []) === []) {
                UltimoPedido::olvidar($phone);

                return [
                    'text' => $aviso.' Y no me quedan más horas ese día. ¿Te sirve otro día?',
                    'conversation_id' => null,
                    'tools_used' => ['crear_cita'],
                ];
            }

            // La lista ya salio; el aviso va aparte para que se lea antes.
            return ['text' => $aviso.' Estas son las que sí tengo 👆', 'conversation_id' => null, 'tools_used' => ['crear_cita', 'disponibilidad']];
        }

        UltimoPedido::olvidar($phone);

        $texto = sprintf(
            '¡Listo! Quedó agendada: *%s* el *%s* a las *%s*%s. Te esperamos 💅',
            $this->nombreDe($pedido),
            $pedido['dia'] ?? $pedido['fecha'],
            $hora['hora'],
            empty($hora['con']) ? '' : ' con *'.$hora['con'].'*',
        );

        return ['text' => $texto, 'conversation_id' => null, 'tools_used' => ['crear_cita']];
    }

    /**
     * Lo que una herramienta devolvió, en la forma que espera quien llama.
     *
     * Si mandó botones, el texto va vacío (los botones ya son la
     * respuesta). Si no hay horas, se dice en una frase. Si no se
     * entendió, null: que lo lleve el modelo.
     *
     * @param  array<string, mixed>  $resultado
     * @return array{text: string, conversation_id: null, tools_used: list<string>}|null
     */
    private function respuestaDe(array $resultado, string $herramienta): ?array
    {
        if (! empty($resultado['eligiendo_servicio']) || ! empty($resultado['ofrecidas'])) {
            return ['text' => '', 'conversation_id' => null, 'tools_used' => [$herramienta]];
        }

        if (isset($resultado['servicios'], $resultado['dia']) && ($resultado['horas'] ?? []) === []) {
            return [
                'text' => sprintf('Para *%s* el *%s* no me quedan horas 😕 ¿Te sirve otro día?', implode(' y ', $resultado['servicios']), $resultado['dia']),
                'conversation_id' => null,
                'tools_used' => [$herramienta],
            ];
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $pedido
     * @return array<string, mixed>
     */
    private function argumentosDe(array $pedido): array
    {
        return array_filter([
            'servicios' => $pedido['servicios'] ?? null,
            'fecha' => $pedido['fecha'] ?? null,
            'franja' => $pedido['franja'] ?? null,
            'juntas' => $pedido['juntas'] ?? null,
            'empleado' => $pedido['empleado'] ?? null,
            'sede' => $pedido['sede'] ?? null,
        ], fn ($v) => $v !== null);
    }

    /** @param array<string, mixed> $pedido */
    private function nombreDe(array $pedido): string
    {
        $servicios = $pedido['servicios'] ?? [];
        $distintos = array_values(array_unique($servicios));

        if (count($distintos) === 1 && count($servicios) > 1) {
            return $distintos[0].' (para '.count($servicios).' personas)';
        }

        return implode(' y ', $distintos);
    }

    /** Deja el mensaje en el hilo, como hace `ofrecer_opciones`. */
    private function anotar(WhatsappConversation $conversacion, string $phone, string $body): void
    {
        Message::create([
            'business_id' => $conversacion->business_id,
            'conversation_id' => $conversacion->id,
            'client_id' => $conversacion->client_id,
            'kind' => Message::KIND_AGENT,
            'direction' => Message::DIRECTION_OUT,
            'to' => $phone,
            'body' => $body,
            'status' => Message::STATUS_SENT,
            'sent_at' => now(),
        ]);

        $conversacion->update(['last_message_at' => now()]);
    }

    private function plano(string $texto): string
    {
        $t = mb_strtolower(trim($texto));
        $t = strtr($t, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n']);

        return preg_replace('/\s+/u', ' ', $t) ?? $t;
    }
}
