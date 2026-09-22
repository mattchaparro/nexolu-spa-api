<?php

namespace App\Ai;

use App\Ai\Capabilities\AvailabilityCapability;
use App\Ai\Capabilities\CreateAppointmentCapability;
use App\Ai\Capabilities\RescheduleAppointmentCapability;
use App\Models\Message;
use App\Models\WhatsappConversation;
use App\Services\WhatsApp\NexoluCommsChannel;
use App\Support\ChannelPhone;
use Carbon\CarbonImmutable;

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

    /** Ya tenía una cita del mismo servicio: moverla… */
    public const MOVER = 'Mover mi cita';

    /** …o de verdad quiere las dos. */
    public const OTRA_CITA = 'Agendar otra';

    public function __construct(
        private readonly AvailabilityCapability $disponibilidad,
        private readonly CreateAppointmentCapability $reserva,
        private readonly RescheduleAppointmentCapability $mudanza,
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

        /*
         * El texto puede traer VARIOS mensajes pegados: el debounce junta
         * lo que llego en la ventana, y si el turno anterior quedo sin
         * responder tambien se arrastra ("Semi\n9 am"). El toque casi
         * siempre es lo ULTIMO que escribio, asi que se prueba el texto
         * completo y, si no, la ultima linea.
         */
        $ultimaLinea = trim((string) collect(preg_split('/\r?\n/', $texto))->filter(fn ($l) => trim($l) !== '')->last());
        $candidatos = array_unique(array_filter([$this->plano($texto), $this->plano($ultimaLinea)]));

        $caller = AiCaller::customer($business, $phone, $conversacion->client, 'whatsapp');

        // 0) Le preguntamos si movía su cita o agendaba otra.
        if (isset($pedido['decidir_mover'])) {
            foreach ($candidatos as $plano) {
                if (in_array($plano, ['mover mi cita', 'mover', 'moverla', 'muevela', 'cambiala', 'mejor muevela', 'si, muevela'], true)) {
                    return $this->mover($caller, $conversacion, $phone, $pedido);
                }

                if (in_array($plano, ['agendar otra', 'otra cita', 'las dos', 'aparte', 'una mas', 'otra aparte'], true)) {
                    unset($pedido['decidir_mover']);

                    return $this->agendar($caller, $conversacion, $phone, $pedido, otraMas: true);
                }
            }

            // Escribió otra cosa: conversación, y es del modelo.
            return null;
        }

        // 1) Había una confirmación esperando.
        if (isset($pedido['confirmar'])) {
            foreach ($candidatos as $plano) {
                if (in_array($plano, ['si, agendar', 'si agendar', 'si', 'dale', 'confirmo', 'confirmar', 'listo', 'ok', 'de una', 'agendame', 'agendala'], true)) {
                    return $this->agendar($caller, $conversacion, $phone, $pedido);
                }

                if (in_array($plano, ['otra hora', 'cambiar hora', 'otra', 'no', 'no, otra hora'], true)) {
                    unset($pedido['confirmar']);

                    return $this->otrasHoras($conversacion, $phone, $pedido);
                }
            }

            // Escribió otra cosa: eso sí es conversación, y es del modelo.
            return null;
        }

        // 1.5) Le preguntamos el día con botones (Hoy / Mañana / Otro día).
        if (! empty($pedido['eligiendo_fecha'])) {
            foreach ($candidatos as $plano) {
                if (in_array($plano, ['hoy', 'manana'], true)) {
                    return $this->conElDia($caller, $phone, $pedido, $plano === 'hoy' ? 'hoy' : 'mañana');
                }

                if (isset($pedido['fechas'][$plano])) {
                    return $this->conElDia($caller, $phone, $pedido, $pedido['fechas'][$plano]);
                }

                if (in_array($plano, ['otro dia', 'otro'], true)) {
                    return $this->losProximosDias($conversacion, $phone, $pedido);
                }
            }

            // Escribió el día con sus palabras ("el viernes"): del modelo,
            // que DateInText ya dejó mandando lo que dijo.
            return null;
        }

        // 2) Tocó una hora de las que se le mostraron.
        foreach ($candidatos as $plano) {
            if (! empty($pedido['horas']) && isset($pedido['horas'][$plano])) {
                return $this->confirmar($conversacion, $phone, $pedido, $pedido['horas'][$plano]);
            }
        }

        // 3) Tocó un servicio de una lista de servicios. El toque llega
        // RECORTADO (WhatsApp corta los títulos a 24): se busca la opción
        // completa y es esa la que se consulta, no el pedazo.
        foreach ($candidatos as $plano) {
            $eleccion = UltimoPedido::destruncar($plano, $pedido['opciones'] ?? []);

            if (empty($pedido['servicios']) && $eleccion !== null) {
                return $this->respuestaDe(
                    $this->disponibilidad->execute($caller, ['servicio' => $eleccion]),
                    'elegir_servicio',
                );
            }
        }

        // 4) Pidió la siguiente tanda de servicios.
        if (ServiciosPendientes::pideVerMas($texto) && ServiciosPendientes::ver($phone) !== []) {
            return $this->respuestaDe($this->disponibilidad->execute($caller, ['servicio' => $texto]), 'mas_servicios');
        }

        return null;
    }

    /**
     * Tocó un día: se buscan las horas con todo lo que ya se sabía.
     *
     * @param  array<string, mixed>  $pedido
     * @return array{text: string, conversation_id: null, tools_used: list<string>}|null
     */
    private function conElDia(AiCaller $caller, string $phone, array $pedido, string $fecha): ?array
    {
        unset($pedido['eligiendo_fecha'], $pedido['fechas']);
        UltimoPedido::guardar($phone, [...$pedido, 'fecha' => $fecha]);
        // Un boton tocado es palabra suya: manda sobre el modelo.
        DateInText::pin($phone, ['fecha']);

        return $this->respuestaDe(
            $this->disponibilidad->execute($caller, ['fecha' => $fecha]),
            'elegir_dia',
        );
    }

    /**
     * "Otro día": los siete siguientes, tocables.
     *
     * Desde pasado mañana — «Hoy» y «Mañana» ya eran botones — y con el
     * título como lo lee la gente ("Jueves 24 de sep"). La fecha ISO de
     * cada fila queda guardada: el toque devuelve el TÍTULO, no un id.
     *
     * @param  array<string, mixed>  $pedido
     * @return array{text: string, conversation_id: null, tools_used: list<string>}
     */
    private function losProximosDias(WhatsappConversation $conversacion, string $phone, array $pedido): array
    {
        $tz = $conversacion->business->businessTimezone();
        $filas = [];
        $fechas = [];
        $i = 0;

        foreach (range(2, 8) as $enDias) {
            $dia = CarbonImmutable::now($tz)->addDays($enDias);
            $titulo = ucfirst($dia->locale('es')->isoFormat('dddd D [de] MMM'));
            $filas[] = ['id' => 'd'.($i++), 'title' => $titulo];
            $fechas[$this->plano($titulo)] = $dia->format('Y-m-d');
        }

        $texto = '¿Qué día te sirve? 📅';

        if (! $this->channel->sendOptions($phone, $texto, $filas, $conversacion->business_id, 'Ver días')) {
            return [
                'text' => '¿Qué día te sirve? Puede ser hoy, mañana o el día de la semana que prefieras 😊',
                'conversation_id' => null,
                'tools_used' => ['elegir_dia'],
            ];
        }

        OpcionesEnviadas::marcar($phone);
        UltimoPedido::guardar($phone, [...$pedido, 'fechas' => $fechas]);
        $this->anotar($conversacion, $phone, $texto."\n\n".implode("\n", array_map(fn ($f) => '▸ '.$f['title'], $filas)));

        return ['text' => '', 'conversation_id' => null, 'tools_used' => ['elegir_dia']];
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
    private function agendar(AiCaller $caller, WhatsappConversation $conversacion, string $phone, array $pedido, bool $otraMas = false): array
    {
        /*
         * Un pedido marcado como MUDANZA no crea: mueve. Laura pidio
         * "cambiar mi cita", vio horas, toco una y confirmo -- ese Si es
         * de reagendar_cita, en una transaccion, no de una cita nueva.
         */
        if (isset($pedido['mudanza']['id'])) {
            $pedido['decidir_mover'] = $pedido['mudanza'];

            return $this->mover($caller, $conversacion, $phone, $pedido)
                ?? ['text' => '', 'conversation_id' => null, 'tools_used' => ['reagendar_cita']];
        }

        $hora = $pedido['confirmar'];

        /*
         * Con quien se le ofrecio, si fue una sola persona. La agenda dijo
         * "2:30 pm con Anyi"; reservar sin decir con quien dejaba que la
         * reserva tomara a la primera del servicio, que a esa hora no
         * trabaja, y dos clientas simuladas "agendaron" en el vacio.
         */
        $con = (string) ($hora['con'] ?? '');
        $argumentos = [...$this->argumentosDe($pedido), 'hora' => $hora['hora_24']];

        if ($otraMas) {
            $argumentos['otra_mas'] = true;
        }

        if ($con !== '' && ! str_contains($con, ' y ') && empty($argumentos['empleado']) && empty($argumentos['juntas'])) {
            $argumentos['empleado'] = $con;
        }

        $resultado = $this->reserva->execute($caller, $argumentos);

        // La cita que pidio YA existe, tal cual: decirselo y no crear otra.
        if (! empty($resultado['ya_existia'])) {
            UltimoPedido::olvidar($phone);

            return [
                'text' => sprintf(
                    'Esa cita ya la tienes agendada 😊 *%s* el *%s* a las *%s*. Te esperamos 💅',
                    $resultado['cita']['servicio'],
                    $resultado['cita']['dia'],
                    $resultado['cita']['hora'],
                ),
                'conversation_id' => null,
                'tools_used' => ['crear_cita'],
            ];
        }

        // Ya tiene una del mismo servicio en otra fecha: ¿la mueve o son dos?
        if (! empty($resultado['ya_tiene_cita'])) {
            return $this->preguntarSiMueve($conversacion, $phone, $pedido, $hora, $resultado['ya_tiene_cita']);
        }

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

        // La confirmacion ya la mando la propia herramienta de reserva
        // (EnvioDirecto): repetirla aqui seria el mismo mensaje dos veces.
        return ['text' => '', 'conversation_id' => null, 'tools_used' => ['crear_cita']];
    }

    /**
     * Ya tiene una cita del mismo servicio: ¿la movemos o agenda otra?
     *
     * Decidirlo por ella es recrear el bug de Laura (quedó con dos citas
     * porque el modelo "movió" creando) o su espejo (moverle la cita a
     * quien quería dos). Se pregunta con dos botones y se guarda la cita
     * en juego para que el toque siguiente no tenga que averiguar nada.
     *
     * @param  array<string, mixed>  $pedido
     * @param  array{hora_24: string, hora: string, con?: string}  $hora
     * @param  array{id: int, servicio: string, dia: string, hora: string}  $existente
     * @return array{text: string, conversation_id: null, tools_used: list<string>}
     */
    private function preguntarSiMueve(WhatsappConversation $conversacion, string $phone, array $pedido, array $hora, array $existente): array
    {
        $texto = sprintf(
            'Ya tienes una cita de *%s* el *%s* a las *%s* 🤔 ¿La muevo para el *%s* a las *%s*, o te agendo otra aparte?',
            $existente['servicio'],
            $existente['dia'],
            $existente['hora'],
            $pedido['dia'] ?? $pedido['fecha'],
            $hora['hora'],
        );

        $enviado = $this->channel->sendOptions(
            $phone,
            $texto,
            [
                ['id' => 'mover', 'title' => self::MOVER],
                ['id' => 'otra_cita', 'title' => self::OTRA_CITA],
            ],
            $conversacion->business_id,
        );

        UltimoPedido::guardar($phone, [...$pedido, 'decidir_mover' => $existente]);

        if (! $enviado) {
            // Sin canal no hay botones: la pregunta va en palabras y la
            // respuesta escrita la atiende el mismo flujo.
            return ['text' => $texto, 'conversation_id' => null, 'tools_used' => ['crear_cita']];
        }

        OpcionesEnviadas::marcar($phone);
        $this->anotar($conversacion, $phone, $texto."\n\n▸ ".self::MOVER."\n▸ ".self::OTRA_CITA);

        return ['text' => '', 'conversation_id' => null, 'tools_used' => ['crear_cita']];
    }

    /**
     * Tocó "Mover mi cita": la existente pasa a la fecha y hora confirmadas.
     *
     * @param  array<string, mixed>  $pedido
     * @return array{text: string, conversation_id: null, tools_used: list<string>}|null
     */
    private function mover(AiCaller $caller, WhatsappConversation $conversacion, string $phone, array $pedido): ?array
    {
        $hora = $pedido['confirmar'] ?? null;
        $existente = $pedido['decidir_mover'];

        if ($hora === null || empty($pedido['fecha'])) {
            // Se perdio la mitad del pedido (cache vencida a medias): mejor
            // que lo lleve el modelo a inventar una mudanza.
            return null;
        }

        $resultado = $this->mudanza->execute($caller, array_filter([
            'cita_id' => $existente['id'],
            'fecha' => $pedido['fecha'],
            'hora' => $hora['hora_24'],
            'empleado' => (isset($hora['con']) && ! str_contains((string) $hora['con'], ' y ')) ? $hora['con'] : null,
        ], fn ($v) => $v !== null));

        if (! ($resultado['movida'] ?? false)) {
            UltimoPedido::olvidar($phone);

            return [
                'text' => 'No pude moverla 😕 '.rtrim((string) ($resultado['motivo'] ?? ''), '.').'.',
                'conversation_id' => null,
                'tools_used' => ['reagendar_cita'],
            ];
        }

        UltimoPedido::olvidar($phone);

        // La confirmacion del cambio ya la mando la herramienta de mover.
        return ['text' => '', 'conversation_id' => null, 'tools_used' => ['reagendar_cita']];
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
            // Si la cita es para otra persona, el local necesita saberlo
            // aunque quien confirme sea un boton y no el modelo.
            'para_quien' => $pedido['para_quien'] ?? null,
            'nombres' => $pedido['nombres'] ?? null,
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
