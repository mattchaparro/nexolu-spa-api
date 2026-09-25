<?php

namespace App\Ai\Capabilities;

use App\Ai\AiArgumentException;
use App\Ai\AiCaller;
use App\Ai\BookingForm;
use App\Ai\Capability;
use App\Ai\ComoLoPide;
use App\Ai\EnvioDirecto;
use App\Ai\FechaDicha;
use App\Ai\HoraLegible;
use App\Ai\Resolves;
use App\Ai\ServiciosPendientes;
use App\Ai\UltimoPedido;
use App\Models\Location;
use App\Models\ResourceSchedule;
use App\Models\Service;
use App\Services\Scheduling\AvailabilityService;
use App\Services\Scheduling\CitasSimultaneas;
use App\Services\WhatsApp\NexoluCommsChannel;
use App\Support\ChannelPhone;
use App\Support\TituloCorto;
use Carbon\Carbon;
use Carbon\CarbonImmutable;

/**
 * Las horas que de verdad quedan libres.
 *
 * Reusa `AvailabilityService`, el mismo motor que alimenta la pagina publica
 * y la agenda: horarios, descansos, buffers, excepciones y preaviso minimo
 * salen de ahi. Una version propia "mas simple" ofreceria huecos que no
 * existen, y el agente terminaria prometiendo horas que el sistema rechaza.
 *
 * Acepta VARIOS servicios (`servicios: ["Semipermanente", "Pedicure"]`)
 * porque asi se pide en la vida real -- "manos y pies" es una sola visita,
 * no dos citas. Para eso existe `slotsForChain`, que encadena los servicios
 * respetando la continuidad y, si puede, con la misma persona. Antes el
 * agente solo podia mandar uno y terminaba diciendo "el sistema no me deja",
 * cuando el sistema si dejaba.
 *
 * Y ENVIA las horas como botones, no las devuelve para que el modelo las
 * escriba. Pedirselo en el prompt y en el resultado no alcanzo: seguia
 * escribiendo "tengo a las 10:00, 10:15, 10:30..." y la clienta tenia que
 * transcribir una. Ofrecer horas por WhatsApp ES mostrar opciones
 * tocables; que dependa de que el modelo obedezca es dejarlo al azar.
 */
class AvailabilityCapability implements Capability
{
    use Resolves;

    // Cuantas se ofrecen. Mas que esto se lee como un formulario; menos,
    // parece que no hay agenda.
    private const MAX_OPCIONES = 4;

    // Tope de Meta para las filas de una lista. Cuando sobran servicios,
    // la ultima se gasta en "No veo el mio".
    private const MAX_FILAS = 10;

    /** Quien no tiene preferencia de profesional. */
    /** Las salidas al final de la lista de horas. */
    public const OTRO_DIA = 'Otro día';

    public const OTRA_PERSONA = 'Con otra persona';

    public const CUALQUIERA = 'Cualquiera';

    public function __construct(
        private readonly AvailabilityService $availability,
        private readonly CitasSimultaneas $simultaneas,
        private readonly NexoluCommsChannel $channel,
    ) {}

    public function requiredPermission(): ?string
    {
        return null;
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
            // Opcionales: si no vienen, se completan con lo ultimo que pidio
            // (ver UltimoPedido). Cuando tocaba "6 pm" el modelo llamaba sin
            // servicio, esto rechazaba la llamada y la clienta leia "no pude
            // consultar la agenda". Faltar un dato es una pregunta, no un error.
            'servicio' => ['nullable', 'string', 'max:255'],
            'servicios' => ['nullable', 'array', 'min:1', 'max:5'],
            'servicios.*' => ['required', 'string', 'max:255'],
            // Texto y no `date_format`: "el lunes" lo resuelve el código,
            // no el modelo, que se equivocó ofreciendo el martes.
            // Opcional a proposito: si acaba de tocar un servicio de la
            // lista, el dia con que se pidio esa lista ya quedo guardado
            // (ver ServiciosPendientes::contexto) y no hay que hacerla
            // repetirlo. Sin lista pendiente sigue haciendo falta.
            'fecha' => ['nullable', 'string', 'max:40'],
            // "en la tarde" lo filtra la herramienta, no el modelo: si el
            // filtro lo hace el, ofrece horas que no pidio o descarta las
            // que si servian.
            'franja' => ['nullable', 'string', 'in:mañana,manana,tarde,noche'],
            /*
             * `juntas` distingue los dos "varios servicios" que la gente
             * pide y que no se parecen en nada:
             *  - false (por defecto): UNA persona, un servicio despues del
             *    otro ("manos y pies").
             *  - true: VARIAS personas a la MISMA hora (ella y su hija),
             *    que necesita una profesional libre por cada una.
             */
            'juntas' => ['nullable', 'boolean'],
            'empleado' => ['nullable', 'string', 'max:255'],
            'sede' => ['nullable', 'string', 'max:255'],
            /*
             * Para quien es la visita, si no es para quien escribe, y los
             * nombres cuando son varias personas. La agenda no los usa:
             * viajan aqui para que queden GUARDADOS con el pedido y la
             * reserva los reciba aunque quien confirme sea un boton.
             */
            'para_quien' => ['nullable', 'string', 'max:120'],
            'nombres' => ['nullable', 'array', 'max:5'],
            'nombres.*' => ['required', 'string', 'max:120'],
        ];
    }

    public function execute(AiCaller $caller, array $arguments): array
    {
        $business = $caller->business;
        $tz = $business->businessTimezone();

        /*
         * Lo que no vino, se completa con lo que se pidio la ultima vez.
         *
         * Despues de mandar la lista de servicios lo unico que vuelve es el
         * nombre tocado; el dia, la franja y si eran varias personas se
         * dijeron antes. Al modelo se le olvidaba y volvia a preguntar el
         * dia a quien ya habia dicho "hoy" -- paso en la simulacion y en
         * una conversacion real.
         */
        $phoneCtx = ChannelPhone::normalize((string) $caller->phone, $business->country_code ?? 'CO');

        if ($phoneCtx !== null) {
            $arguments = UltimoPedido::completar($phoneCtx, $arguments);

            /*
             * Lo dicho se guarda ANTES de intentar resolver nada. Yesica
             * pidio "manicure tradicional para el jueves"; el servicio no
             * existia con ese nombre, la resolucion lanzo, y "el jueves" se
             * perdio con ella: la siguiente llamada consulto HOY y el bot
             * le dijo que ya no habia horas. La fecha, la franja y para
             * quien es no dependen de que el servicio se haya entendido.
             */
            UltimoPedido::guardar($phoneCtx, [
                ...UltimoPedido::ver($phoneCtx),
                ...array_filter([
                    'fecha' => $arguments['fecha'] ?? null,
                    'franja' => $arguments['franja'] ?? null,
                    'juntas' => $arguments['juntas'] ?? null,
                    'empleado' => $arguments['empleado'] ?? null,
                    'sede' => $arguments['sede'] ?? null,
                    'para_quien' => $arguments['para_quien'] ?? null,
                    'nombres' => $arguments['nombres'] ?? null,
                ], fn ($v) => $v !== null && $v !== ''),
            ]);
        }

        if (empty($arguments['servicio']) && empty($arguments['servicios'])) {
            return [
                'horas' => [],
                'falta_informacion' => 'No sé qué servicio quiere.',
                'instruccion' => 'Pregúntale qué se quiere hacer -- o mándame sus palabras tal cual '
                    .'en `servicio` ("las uñas", "manos y pies") -- y vuelve a llamarme.',
            ];
        }

        /*
         * «Muéstrame más servicios»: la fila que se gasta cuando el
         * catálogo no cabe en una lista. De la fila tocada solo vuelve el
         * TÍTULO -- WhatsApp no dice en qué página iba -- así que los que
         * faltaban quedaron guardados al mandar la tanda anterior.
         *
         * Va ANTES del día a propósito: pedir la siguiente página no es
         * pedir una cita, es pasar la hoja. Cuando esto estaba más abajo,
         * la clienta tocaba «Muéstrame más servicios» y el bot le
         * respondía «¿Para qué día?» -- los servicios que pidió le
         * llegaban tres turnos después, cuando ya había elegido un día
         * para un servicio que todavía no había escogido.
         */
        $nombres = $arguments['servicios'] ?? [$arguments['servicio']];

        // También cuando el pedido guardado traía varios servicios: lo que
        // acaba de decir manda sobre lo que se venía arrastrando.
        $pidioVerMas = ServiciosPendientes::pideVerMas((string) ($arguments['servicio'] ?? ''))
            || (count($nombres) === 1 && ServiciosPendientes::pideVerMas((string) $nombres[0]));

        if ($pidioVerMas) {
            $siguientes = $this->siguienteTanda($caller);

            if ($siguientes !== null) {
                return $siguientes;
            }

            /*
             * Sin tanda guardada (se le venció la memoria, o lo escribió
             * sin que hubiera lista antes) esto NO es el nombre de un
             * servicio: buscarlo en el catálogo no encuentra nada y el
             * error que sale no le dice nada a nadie.
             */
            unset($arguments['servicio'], $arguments['servicios']);

            return [
                'horas' => [],
                'falta_informacion' => 'Pidió ver más servicios pero ya no queda ninguna lista pendiente.',
                'instruccion' => 'Vuelve a preguntarle qué se quiere hacer y llámame con sus '
                    .'palabras en `servicio`; NO le mandes «'.ServiciosPendientes::VER_MAS.'» como servicio.',
            ];
        }

        if (! isset($arguments['fecha'])) {
            /*
             * ¿QUÉ, antes que CUÁNDO? Si lo que dijo puede ser varios
             * servicios ("hacerme las uñas"), elegir cuál va primero.
             *
             * No es orden estético: los días que se ofrecen salen de la
             * duración del servicio, así que preguntar el día sin saberlo
             * es preguntar sobre una agenda que todavía no se puede
             * calcular. Y quien contestaba el día quedaba eligiendo fecha
             * para un servicio que nunca escogió.
             */
            try {
                $this->resolveServices($business->id, $nombres);
            } catch (AiArgumentException $cualDeTodos) {
                $elegir = $this->queEligaServicio($caller, $cualDeTodos->opciones);

                if ($elegir !== null) {
                    if ($phoneCtx !== null) {
                        UltimoPedido::guardar($phoneCtx, [
                            ...UltimoPedido::ver($phoneCtx),
                            'opciones' => $cualDeTodos->opciones,
                        ]);
                    }

                    return $elegir;
                }
            }

            /*
             * ¿Con alguien en particular? Antes del día: quien viene por su
             * manicurista de siempre no quiere elegir un día y descubrir
             * después que ella no trabaja. Una sola vez por pedido, y solo
             * si hay entre quiénes elegir. Quien ya dijo el día recibe la
             * misma pregunta más abajo, ya con la fecha guardada.
             */
            $preferencia = $this->queEligaPersona($caller, $arguments);

            if ($preferencia !== null) {
                return $preferencia;
            }

            return $this->queEligaDia($caller, $arguments['servicios'] ?? [$arguments['servicio']]) ?? [
                'horas' => [],
                'falta_informacion' => 'No sé para qué día.',
                'instruccion' => 'Pregúntale qué día quiere (puedes decirle "hoy", "mañana" '
                    .'o un día de la semana) y vuelve a llamarme con `fecha`.',
            ];
        }

        $fecha = FechaDicha::resolver($arguments['fecha'], $tz);

        if ($fecha === null) {
            return [
                'horas' => [],
                'falta_informacion' => "No entendí la fecha «{$arguments['fecha']}».",
                'instruccion' => 'Pregúntale qué día quiere (puedes decirle "hoy", "mañana" '
                    .'o un día de la semana) y vuelve a intentarlo.',
            ];
        }

        try {
            [$servicios, $supuestos] = $this->resolveServices($business->id, $nombres);
        } catch (AiArgumentException $cualDeTodos) {
            /*
             * Pidio algo que puede ser varias cosas ("hacerme las unas").
             * Elegir entre servicios que existen es exactamente lo que se
             * toca, no lo que se conversa: le llegan los nombres reales
             * como botones y de paso nadie tiene que deletrear el del
             * catalogo.
             */
            $elegir = $this->queEligaServicio($caller, $cualDeTodos->opciones);

            if ($elegir === null) {
                throw $cualDeTodos;
            }

            if ($phoneCtx !== null) {
                UltimoPedido::guardar($phoneCtx, [
                    'opciones' => $cualDeTodos->opciones,
                    'fecha' => $arguments['fecha'],
                    'franja' => $arguments['franja'] ?? null,
                    'juntas' => $arguments['juntas'] ?? null,
                    'empleado' => $arguments['empleado'] ?? null,
                    'sede' => $arguments['sede'] ?? null,
                ]);
            }

            $elegir['instruccion'] .= " El día ya lo dijo («{$arguments['fecha']}»): cuando toque un "
                .'servicio, llámame con ese nombre en `servicio` y NO le vuelvas a preguntar el día.';

            return $elegir;
        }

        /*
         * ¿Con alguien en particular? También cuando ya dijo el día.
         *
         * Antes esto se saltaba: quien escribía «semi para mañana» recibía
         * las horas de las dos manicuristas mezcladas, sin que nadie le
         * preguntara con quién, y solo al confirmar se enteraba de a quién
         * le había tocado. Con los botones sí se preguntaba, y escribir no
         * puede dar una experiencia peor que tocar.
         *
         * El día NO se vuelve a preguntar: ya quedó guardado en el pedido, y
         * al tocar a alguien se va derecho a las horas de ese día con ella.
         * Y sigue siendo una sola vez por pedido: si ya se preguntó, o si
         * solo hay una persona que lo hace, no hay pregunta.
         */
        $preferencia = $this->queEligaPersona($caller, $arguments);

        if ($preferencia !== null) {
            return $preferencia;
        }

        $sede = $this->resolveLocation($business->id, $arguments['sede'] ?? null);
        $persona = isset($arguments['empleado'])
            ? $this->resolveResource($business->id, $arguments['empleado'], $sede?->id)
            : null;

        $juntas = (bool) ($arguments['juntas'] ?? false) && count($servicios) > 1;

        if ($juntas) {
            $slots = array_map(
                fn (array $s) => [
                    'starts_at' => $s['starts_at'],
                    'resource_name' => collect($s['asignacion'])->pluck('resource_name')->implode(' y '),
                ],
                $this->simultaneas->slots($business, $servicios, $fecha, $sede?->id),
            );
        } elseif (count($servicios) === 1) {
            $slots = $this->availability->slotsForService($business, $servicios[0], $fecha, $persona, null, $sede?->id);
        } else {
            $slots = $this->availability->slotsForChain($business, $servicios, $fecha, null, $persona?->id, $sede?->id);
        }

        $slots = $this->deLaFranja($slots, $arguments['franja'] ?? null, $tz);

        $horas = collect($slots)->map(fn (array $s) => array_filter([
            // `hora` es para MOSTRAR ("3 pm") y `hora_24` para volver a
            // llamar (crear_cita pide H:i).
            'hora' => HoraLegible::de($s['starts_at'], $tz),
            'hora_24' => $s['starts_at']->setTimezone($tz)->format('H:i'),
            'con' => $s['resource_name'] ?? collect($s['legs'] ?? [])->pluck('resource_name')->unique()->implode(' y '),
        ], fn ($v) => $v !== null && $v !== ''))->values();

        $nombreServicios = array_map(fn (Service $s) => $s->name, $servicios);

        if ($horas->isEmpty()) {
            /*
             * Tambien SIN horas el pedido se recuerda. Valentina pidio
             * "semi con rubber" para hoy, no habia, y al contestar "para
             * mañana despues de las 5" el servicio ya se habia olvidado:
             * recibio la lista completa para volver a elegir lo que habia
             * dicho con todas las letras -- dos veces.
             */
            if ($phoneCtx !== null) {
                UltimoPedido::guardar($phoneCtx, [
                    ...UltimoPedido::ver($phoneCtx),
                    'servicios' => $nombreServicios,
                ]);
            }

            return [
                'servicios' => $nombreServicios,
                'fecha' => $fecha->format('Y-m-d'),
                'dia' => $fecha->locale('es')->isoFormat('dddd D [de] MMMM'),
                'horas' => [],
                'instruccion' => ($juntas
                    ? 'No hay ninguna hora ese día con suficientes profesionales libres al tiempo'
                    : 'No hay horas ese día').($arguments['franja'] ?? null ? ' en esa franja' : '')
                    .'. Ofrécele otro día u otra franja, y vuelve a llamarme '
                    .'(recuerdo el servicio: basta la fecha nueva).',
            ];
        }

        // Repartidas, no las primeras cuatro seguidas: ofrecer 10:00,
        // 10:15, 10:30 y 10:45 es ofrecer la misma hora cuatro veces.
        $ofrecidas = $this->repartidas($horas->all(), self::MAX_OPCIONES);

        /*
         * La misma consulta dos veces en pocos minutos NO vuelve a mandar
         * la lista. Cuando la clienta tocaba una hora, el modelo volvia a
         * llamar aca con los mismos datos y ella recibia las mismas cuatro
         * horas otra vez -- tres veces seguidas en una simulacion. Lo que
         * pidio ya lo esta viendo; lo que falta es que el modelo confirme.
         */
        $previo = $phoneCtx === null ? [] : UltimoPedido::ver($phoneCtx);
        $repetida = ($previo['fecha_iso'] ?? null) === $fecha->format('Y-m-d')
            && ($previo['servicios'] ?? null) === $nombreServicios
            && ($previo['franja'] ?? null) === ($arguments['franja'] ?? null)
            && isset($previo['mostrado_at'])
            && now()->diffInMinutes(Carbon::parse($previo['mostrado_at'])) < 10;

        if ($repetida) {
            return [
                'servicios' => $nombreServicios,
                'fecha' => $fecha->format('Y-m-d'),
                'dia' => $fecha->locale('es')->isoFormat('dddd D [de] MMMM'),
                'ofrecidas' => $ofrecidas,
                'ya_las_vio' => true,
                'instruccion' => 'Estas horas YA se las mandaste hace un momento y las está viendo: '
                    .'NO las repitas ni llames a `ofrecer_opciones`. Si te dijo una hora, confírmale '
                    .'en una frase servicio, día, hora y con quién, y con su sí llama a `crear_cita` '
                    .'con esa `hora_24`. Si no dijo ninguna, pregúntale cuál le sirve o si prefiere '
                    .'otro día.',
            ];
        }

        $mostrado = $this->mostrar($caller, $nombreServicios, $fecha, $ofrecidas, ! empty($arguments['empleado']));

        // Lo que se acaba de entender queda guardado: la siguiente llamada
        // -- "6 pm" tocado, sin mas -- ya sabe de que servicio y de que dia.
        if ($phoneCtx !== null) {
            $tocables = [];
            foreach ($ofrecidas as $h) {
                $tocables[mb_strtolower(trim($h['hora']))] = ['hora_24' => $h['hora_24'], 'hora' => $h['hora'], 'con' => $h['con'] ?? null];
            }

            // Y TODAS las que hay, para que "Otra hora" pueda mandar las
            // que no se mostraron sin volver a consultar la agenda.
            $todas = [];
            foreach ($horas->take(12) as $h) {
                $todas[mb_strtolower(trim($h['hora']))] = ['hora_24' => $h['hora_24'], 'hora' => $h['hora'], 'con' => $h['con'] ?? null];
            }

            UltimoPedido::guardar($phoneCtx, [
                // Si este pedido es la MUDANZA de una cita, la marca tiene
                // que sobrevivir a la lista de horas: el «Sí» de este
                // pedido reagenda, no crea (ver GuidedEntry y Toques).
                'mudanza' => UltimoPedido::ver($phoneCtx)['mudanza'] ?? null,
                'servicios' => $nombreServicios,
                'fecha' => $arguments['fecha'],
                'fecha_iso' => $fecha->format('Y-m-d'),
                'dia' => $fecha->locale('es')->isoFormat('dddd D [de] MMMM'),
                'franja' => $arguments['franja'] ?? null,
                'juntas' => $arguments['juntas'] ?? null,
                'empleado' => $arguments['empleado'] ?? null,
                'sede' => $arguments['sede'] ?? null,
                'para_quien' => $arguments['para_quien'] ?? null,
                'nombres' => $arguments['nombres'] ?? null,
                // Las horas que le llegaron como botones, para que tocar
                // una no tenga que pasar por el modelo (ver Toques).
                'horas' => $mostrado ? $tocables : [],
                'todas' => $todas,
                'mostrado_at' => $mostrado ? now()->toIso8601String() : null,
            ]);
        }

        return array_filter([
            'servicios' => $nombreServicios,
            // La fecha RESUELTA, no la que dijo el modelo: si pidió "el
            // lunes" tiene que escribir el lunes, no lo que el creía.
            'fecha' => $fecha->format('Y-m-d'),
            'dia' => $fecha->locale('es')->isoFormat('dddd D [de] MMMM'),
            // Con una sola sede, nombrarla es ruido: la clienta no esta
            // eligiendo entre dos locales, y "en la sede Principal" en cada
            // mensaje suena a sistema, no a la recepcion del salon.
            'sede' => $this->variasSedes($business->id) ? $sede?->name : null,
            // `ofrecidas` son las que YA vio como botones; `horas` es todo
            // lo libre, por si pide "algo mas temprano" y hay que buscar
            // ahi sin volver a consultar.
            'ofrecidas' => $ofrecidas,
            /*
             * Lo que se eligio por ella al pedir varios servicios de una
             * vez ("pies en semi" -> Pedi + Jelly Spa + Semi, el mas
             * pedido). Va a la vista en la cabecera de las horas, y el
             * modelo lo repite al confirmar para que lo pueda cambiar.
             */
            'supuse' => $supuestos === [] ? null : $supuestos,
            'horas' => $horas->take(12)->all(),
            'instruccion' => $mostrado
                ? 'Las horas YA le llegaron como botones y las está viendo. SOLO POR ESTA VEZ '
                    .'responde con una cadena vacía (no llames a `ofrecer_opciones` ni las '
                    .'escribas); a lo que escriba después respóndele normal. Cuando toque una, '
                    .'te llega como su próximo mensaje: confírmale en '
                    .'UNA frase servicio, día, hora y con quién, y con su sí llama a `crear_cita` '
                    .'con `servicios` = ['.implode(', ', $nombreServicios).'], `fecha` = «'
                    .$arguments['fecha'].'» y la `hora_24` de la que tocó. No vuelvas a '
                    .'preguntarle nada de eso.'
                    .($supuestos === [] ? '' : ' Cuando toque la hora, ANTES de agendar dile qué '
                        .'servicios le busqué (vienen en `supuse`, elegidos por ser los más '
                        .'pedidos) y pregúntale si son esos.')
                : 'Ofrécele dos o tres de `ofrecidas` usando el campo `hora`, nunca `hora_24`.',
        ], fn ($v) => $v !== null);
    }

    /**
     * "¿Con alguien en particular?": las profesionales y su horario.
     *
     * Con «Cualquiera» de primera, que es lo que la mayoría quiere y no
     * debería costar más de un toque. Cada fila lleva los días y la hora
     * en que trabaja, para que elegir no sea adivinar.
     *
     * Null = no hay nada que preguntar (ya eligió, ya se le preguntó, el
     * servicio no está claro todavía, o una sola persona lo presta).
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>|null
     */
    private function queEligaPersona(AiCaller $caller, array $arguments): ?array
    {
        $phone = ChannelPhone::normalize((string) $caller->phone, $caller->business->country_code ?? 'CO');

        if ($phone === null || $caller->isStaff() || ! empty($arguments['empleado']) || ! empty($arguments['juntas'])) {
            return null;
        }

        $pedido = UltimoPedido::ver($phone);

        if (! empty($pedido['empleado_preguntado'])) {
            return null;
        }

        try {
            [$servicios] = $this->resolveServices($caller->business->id, $arguments['servicios'] ?? [$arguments['servicio']]);
        } catch (AiArgumentException) {
            // Todavía no está claro QUÉ quiere: primero eso.
            return null;
        }

        if (count($servicios) !== 1) {
            return null;
        }

        $gente = $this->quienesPrestan($caller->business->id, $servicios[0]->id, $arguments['sede'] ?? null);

        if (count($gente) < 2) {
            return null;
        }

        $filas = [['id' => 'cualquiera', 'title' => self::CUALQUIERA, 'description' => 'La primera que esté libre']];

        foreach (array_slice($gente, 0, self::MAX_FILAS - 1) as $persona) {
            $filas[] = array_filter([
                'id' => 'r'.$persona->id,
                'title' => TituloCorto::de($persona->name, 24),
                'description' => $this->horarioDe($persona->id),
            ]);
        }

        if (! app(EnvioDirecto::class)->opciones($caller, '¿Con alguien en particular? 💅', $filas, 'Ver el equipo')) {
            return null;
        }

        UltimoPedido::guardar($phone, [
            ...$pedido,
            'servicios' => [$servicios[0]->name],
            'empleados' => collect($filas)->mapWithKeys(fn ($f) => [mb_strtolower($f['title']) => $f['title']])->all(),
            'eligiendo_empleado' => true,
        ]);

        return [
            'horas' => [],
            'eligiendo_empleado' => true,
            'instruccion' => 'Le pregunté YO con quién quiere, con botones (incluye «'.self::CUALQUIERA
                .'»): ya los está viendo. SOLO POR ESTA VEZ responde con una cadena vacía. Cuando '
                .'toque a alguien, vuelve a llamarme con ese nombre en `empleado`.',
        ];
    }

    /**
     * Quiénes prestan ese servicio, en el orden del catálogo.
     *
     * @return list<\App\Models\Resource>
     */
    private function quienesPrestan(int $businessId, int $serviceId, ?string $sede): array
    {
        $sedeId = $this->resolveLocation($businessId, $sede)?->id;

        return \App\Models\Resource::withoutGlobalScope('business')
            ->where('business_id', $businessId)
            ->where('type', \App\Models\Resource::TYPE_STAFF)
            ->where('is_active', true)
            ->where('is_bookable_online', true)
            ->when($sedeId !== null, fn ($q) => $q->where('location_id', $sedeId))
            ->whereHas('services', fn ($q) => $q->where('services.id', $serviceId))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->all();
    }

    /** "Lun a Sáb · 9 am a 6 pm", para que elegir no sea adivinar. */
    private function horarioDe(int $resourceId): ?string
    {
        $horarios = ResourceSchedule::withoutGlobalScope('business')
            ->where('resource_id', $resourceId)
            ->orderBy('weekday')
            ->get();

        if ($horarios->isEmpty()) {
            return null;
        }

        $dias = ['', 'Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom'];
        $nombres = $horarios->pluck('weekday')->unique()->sort()->map(fn ($d) => $dias[(int) $d] ?? '')->filter()->values();

        // "Lun a Sáb" cuando son seguidos; si no, la lista.
        $seguidos = $horarios->pluck('weekday')->unique()->sort()->values();
        $rango = $seguidos->count() > 2 && ($seguidos->last() - $seguidos->first() + 1) === $seguidos->count()
            ? $nombres->first().' a '.$nombres->last()
            : $nombres->implode(', ');

        $desde = HoraLegible::de(Carbon::parse($horarios->min('start_time'), 'UTC'), 'UTC');
        $hasta = HoraLegible::de(Carbon::parse($horarios->max('end_time'), 'UTC'), 'UTC');

        return TituloCorto::de($rango.' · '.$desde.' a '.$hasta, 72);
    }

    /**
     * El día también se toca: «Hoy / Mañana / Otro día».
     *
     * Era el único paso del agendamiento que obligaba a ESCRIBIR: el
     * menú, el servicio, la hora y el «Sí, agendar» ya eran botones, pero
     * la fecha había que teclearla — y ahí el modelo a veces entendía
     * otra cosa. Quien viene de un flujo tipo ManyChat espera tocar;
     * ahora puede agendar de punta a punta sin escribir una letra.
     *
     * «Otro día» dispara la lista de los días siguientes (ver Toques).
     * Null = sin canal o quien llama es del negocio: la pregunta vuelve
     * al modelo, como antes.
     *
     * @return array<string, mixed>|null
     */
    private function queEligaDia(AiCaller $caller, array $servicios): ?array
    {
        $phone = ChannelPhone::normalize((string) $caller->phone, $caller->business->country_code ?? 'CO');

        if ($phone === null || $caller->isStaff()) {
            return null;
        }

        /*
         * Quien viene del recordatorio de retoque no puede recibir un
         * "¿Para qué día?" pelado: acaba de tocar un botón que promete que
         * ya sabemos qué y con quién, y el mensaje tiene que DEMOSTRARLO.
         * Lo deja GuidedEntry::startRetouch y se consume una sola vez.
         */
        $pedido = UltimoPedido::ver($phone);
        $preludio = (string) ($pedido['preludio'] ?? '');

        $enviado = app(EnvioDirecto::class)->opciones(
            $caller,
            ($preludio !== '' ? $preludio."\n\n" : '').'¿Para qué día? 📅',
            [
                ['id' => 'hoy', 'title' => 'Hoy'],
                ['id' => 'manana', 'title' => 'Mañana'],
                ['id' => 'otro_dia', 'title' => 'Otro día'],
            ],
        );

        if (! $enviado) {
            return null;
        }

        unset($pedido['preludio']);

        UltimoPedido::guardar($phone, [
            ...$pedido,
            // Tal como los dijo: el toque del dia vuelve a la agenda y
            // alla se resuelven, con lista de por medio si dan para varios.
            'servicios' => array_values(array_filter(array_map('strval', $servicios))),
            'eligiendo_fecha' => true,
        ]);

        return [
            'horas' => [],
            'eligiendo_fecha' => true,
            'instruccion' => 'El día se lo pregunté YO, con botones (Hoy / Mañana / Otro día): '
                .'ya los está viendo. SOLO POR ESTA VEZ responde con una cadena vacía; sus '
                .'mensajes siguientes se responden normal. Cuando toque un día, vuelve a '
                .'llamarme con ese día en `fecha`.',
        ];
    }

    /**
     * ¿El mensaje ya nombra un servicio o una categoría del catálogo?
     *
     * OJO: las palabras de agendar se quitan ANTES de preguntar, porque
     * en "quiero UNA cita" ese "una" parece la uña ("uña" sin tilde) y
     * el traductor creía que ya había nombrado un servicio.
     */
    public function mentionsService(AiCaller $caller, string $texto): bool
    {
        $plano = mb_strtolower(strtr(trim($texto), ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n']));
        $sinAgenda = preg_replace(
            [
                '/\b(una?|el|la|mi|otra|esa)\s+(cita|citas|turno|turnos|reserva|espacio|cupo)\b/u',
                '/\b(cita|citas|turno|turnos|reserva|reservar|agendar|agendarme|agendame|agenda|disponibilidad|cupo|espacio)\b/u',
            ],
            ' ',
            $plano,
        ) ?? $plano;

        return ComoLoPide::candidatos($this->catalogoEnLinea($caller->business->id), $sinAgenda)->isNotEmpty();
    }

    /**
     * El menú de bienvenida: los más pedidos, tocables, sin modelo.
     *
     * Para el mensaje que abre casi todas las conversaciones ("hola,
     * quiero una cita"): en vez de que el modelo pregunte "¿qué servicio
     * te gustaría?" — y la clienta tenga que deletrear un nombre de
     * catálogo — le llegan de una los servicios que más pide la gente,
     * con precio y duración, y la fila de «ver más». Es el pedazo de
     * flujo clásico que faltaba en la puerta de entrada.
     *
     * Devuelve null cuando este menú NO es la respuesta correcta: ya
     * nombró un servicio o una categoría (el flujo normal la atiende
     * mejor), el catálogo está vacío, o el canal no pudo mandar botones.
     *
     * @return array<string, mixed>|null
     */
    public function welcomeMenu(AiCaller $caller, string $texto): ?array
    {
        if ($caller->isStaff()) {
            return null;
        }

        $catalogo = $this->catalogoEnLinea($caller->business->id);

        // "quiero una cita de manicure" ya dice qué: esa lista sale
        // filtrada por el camino normal, no el menú completo.
        if ($catalogo->isEmpty() || $this->mentionsService($caller, $texto)) {
            return null;
        }

        $nombres = $catalogo->pluck('name')->all();

        $menu = $this->queEligaServicio(
            $caller,
            $nombres,
            // Sin "¡Hola!": a esta altura ya se saludó, y saludar dos veces
            // en la misma conversación suena a máquina.
            'Estos son los servicios que más nos piden 💅 Toca uno, o escríbeme qué necesitas 👇',
        );

        if ($menu === null) {
            return null;
        }

        $phone = ChannelPhone::normalize((string) $caller->phone, $caller->business->country_code ?? 'CO');

        if ($phone !== null) {
            // Para que el toque siguiente calce (y conserve la fecha que
            // haya dicho en el mismo mensaje, ya guardada por DateInText).
            UltimoPedido::guardar($phone, [
                ...UltimoPedido::ver($phone),
                'opciones' => $nombres,
            ]);
        }

        return $menu;
    }

    /**
     * Los servicios que encajan, tocables, cuando lo que dijo da para
     * varios.
     *
     * Devuelve null si no se pudieron mandar (una empleada, un telefono
     * raro, el canal caido): ahi el que llama vuelve a lanzar el error y
     * el modelo pregunta escribiendo, como antes.
     *
     * @param  list<string>  $opciones
     * @return array<string, mixed>|null
     */
    private function queEligaServicio(
        AiCaller $caller,
        array $opciones,
        string $titulo = '¿Cuál de estos quieres? 💅',
    ): ?array {
        $phone = ChannelPhone::normalize((string) $caller->phone, $caller->business->country_code ?? 'CO');

        if ($opciones === [] || $phone === null || $caller->isStaff()) {
            return null;
        }

        /*
         * Una lista de WhatsApp aguanta diez filas y Manicure tiene
         * veintitres servicios. Se mandan los NUEVE mas pedidos y la
         * decima fila los trae a todos.
         *
         * Antes esa decima fila era un texto en la cabecera: "hay 13
         * mas, si no ves el tuyo escribelo". Escribirlo es deletrear un
         * nombre de catalogo -- justo lo que esta pantalla vino a
         * evitar. Lo que se puede tocar no se escribe.
         */
        $caben = array_slice($opciones, 0, self::MAX_FILAS);
        $faltan = [];

        if (count($opciones) > self::MAX_FILAS) {
            $caben = array_slice($opciones, 0, self::MAX_FILAS - 1);
            $faltan = array_slice($opciones, self::MAX_FILAS - 1);
        }

        $filas = $this->filasDeServicios($caller, $caben);

        if ($faltan !== []) {
            $filas[] = [
                'id' => 'mas',
                'title' => ServiciosPendientes::VER_MAS,
                'description' => 'Quedan '.count($faltan).' más',
            ];
        }

        if (! app(EnvioDirecto::class)->opciones($caller, $titulo, $filas, 'Ver servicios')) {
            return null;
        }

        ServiciosPendientes::guardar($phone, $faltan);

        return [
            'horas' => [],
            'eligiendo_servicio' => $caben,
            'faltan_por_mostrar' => count($faltan),
            'instruccion' => 'Todavía no se puede mirar la agenda: lo que pidió puede ser '
                .'varios servicios. Los nombres YA le llegaron como botones y los está '
                .'viendo. SOLO POR ESTA VEZ responde con una cadena vacía (no los escribas '
                .'ni preguntes nada); si después escribe algo que NO es tocar un botón, '
                .'respóndele normal. Cuando toque uno, te llega como su próximo mensaje y '
                .'ahí vuelves a llamarme con ese nombre y el mismo día. Si toca «'
                .ServiciosPendientes::VER_MAS.'», vuelve a llamarme con ESAS mismas '
                .'palabras en `servicio` y te mando los que faltan.',
        ];
    }

    /**
     * Los servicios que no cupieron en la lista anterior.
     *
     * Devuelve null si no hay ninguno guardado -- se le vencio la
     * memoria, o toco "No veo el mio" sin que hubiera una lista antes --
     * y entonces esto sigue su camino normal y trata sus palabras como
     * el nombre de un servicio.
     *
     * @return array<string, mixed>|null
     */
    private function siguienteTanda(AiCaller $caller): ?array
    {
        $phone = ChannelPhone::normalize((string) $caller->phone, $caller->business->country_code ?? 'CO');

        if ($phone === null) {
            return null;
        }

        $faltan = ServiciosPendientes::ver($phone);

        if ($faltan === []) {
            return null;
        }

        return $this->queEligaServicio($caller, $faltan, 'Estos son los demás 💅');
    }

    /**
     * Cada servicio con lo que la clienta necesita para decidir.
     *
     * Un nombre suelto -- "Capping", "Semi + Rubber" -- no le dice a
     * nadie cuanto cuesta ni cuanto se va a demorar, que es exactamente
     * lo que se pregunta antes de elegir. Con el precio y la duracion
     * debajo, elegir deja de ser adivinar.
     *
     * @param  list<string>  $nombres
     * @return list<array{id: string, title: string, description?: string}>
     */
    private function filasDeServicios(AiCaller $caller, array $nombres): array
    {
        $moneda = $caller->business->currency ?? 'COP';

        $datos = Service::withoutGlobalScope('business')
            ->where('business_id', $caller->business->id)
            ->whereIn('name', $nombres)
            ->get()
            ->keyBy('name');

        return array_values(array_map(function (string $nombre, int $i) use ($datos, $moneda) {
            $servicio = $datos->get($nombre);

            return array_filter([
                'id' => 's'.$i,
                'title' => TituloCorto::de($nombre),
                'description' => $servicio === null ? null : $this->comoSeLee($servicio, $moneda),
            ], fn ($v) => $v !== null);
        }, $nombres, array_keys($nombres)));
    }

    /**
     * "45 min · 60.000 COP", en ese orden.
     *
     * La duracion primero porque es lo que decide si cabe hoy -- quien
     * sale del trabajo a las 5:30 necesita saber si alcanza antes que
     * cuanto cuesta -- y el precio escrito como lo escribe el local, con
     * los miles en punto y sin decimales: un `180.00` en un chat se lee
     * como ciento ochenta pesos.
     */
    private function comoSeLee(Service $servicio, string $moneda): string
    {
        return mb_substr(
            $servicio->duration_min.' min · '.number_format((float) $servicio->price, 0, ',', '.').' '.$moneda,
            0,
            72,
        );
    }

    /**
     * Manda las horas como botones y deja la marca para que el job no
     * escriba encima. Si el canal falla, el modelo las escribe.
     *
     * @param  list<string>  $servicios
     * @param  list<array{hora: string, hora_24: string, con?: string}>  $horas
     */
    private function mostrar(AiCaller $caller, array $servicios, CarbonImmutable $fecha, array $horas, bool $conPersona = false): bool
    {
        $phone = ChannelPhone::normalize((string) $caller->phone, $caller->business->country_code ?? 'CO');

        if ($phone === null || $caller->isStaff()) {
            return false;
        }

        $queServicio = $this->comoSeNombran($servicios);
        $dia = $fecha->locale('es')->isoFormat('dddd D [de] MMMM');
        $pedido = UltimoPedido::ver($phone);

        /*
         * Con el Flow publicado, la confirmacion es UNA pantalla nativa:
         * la hora a elegir entre las libres, el nombre, y confirmar. Solo
         * para el caso simple -- un servicio, para ella, cita nueva --
         * porque el nfm_reply termina en crear_cita: una mudanza o unas
         * citas juntas siguen por los botones de siempre. Y si el envio
         * falla, tambien: nada nuevo rompe lo que ya funcionaba.
         */
        if (
            count($servicios) === 1
            && ! isset($pedido['mudanza'])
            && empty($pedido['juntas'])
            && app(BookingForm::class)->send(
                $caller,
                $servicios[0],
                $dia,
                $fecha->format('Y-m-d'),
                array_map(fn (array $h) => ['hora_24' => $h['hora_24'], 'hora' => $h['hora'], 'con' => $h['con'] ?? null], $horas),
            )
        ) {
            return true;
        }

        /*
         * Si el pedido es la MUDANZA de una cita, el encabezado lo dice.
         * Laura pidió "cambiar mi cita", recibió "Para Arabe/4D tengo
         * estas horas" y creyó que el bot no la había entendido ("me
         * parece que no me expliqué bien") -- soltó los botones, se fue
         * al texto libre y de ahí al pantano. La lista era la correcta;
         * lo que faltaba era decirle que era para MOVER su cita.
         */
        $mudanza = UltimoPedido::ver($phone)['mudanza'] ?? null;

        $texto = $mudanza !== null
            ? sprintf(
                'Claro, movemos tu cita de *%s*%s. ¿Para qué hora del *%s* te la paso? 👇',
                $queServicio,
                empty($mudanza['desde']) ? '' : ' (quedó '.$mudanza['desde'].')',
                $dia,
            )
            : sprintf('Para *%s* el *%s* tengo estas horas 👇', $queServicio, $dia);

        /*
         * Las salidas, al final de la lista: si ninguna hora le sirve, que
         * pueda mirar otro día o a otra persona sin tener que escribirlo.
         * Laura eligió a Alejandra, no le servían sus horas del viernes y
         * la lista no le daba por dónde seguir.
         */
        $salidas = [['id' => 'otro_dia', 'title' => self::OTRO_DIA, 'description' => 'Ver otras fechas']];

        if ($conPersona) {
            $salidas[] = ['id' => 'otra_persona', 'title' => self::OTRA_PERSONA, 'description' => 'Ver las horas de las demás'];
        }

        // WhatsApp deja diez filas: las horas ceden lugar a las salidas.
        $horas = array_slice($horas, 0, 10 - count($salidas));

        // EnvioDirecto y no el canal a secas: sin rastro en el hilo, el
        // turno siguiente cree que el mensaje anterior sigue sin responder
        // y el manejador de toques recibe dos mensajes pegados. Asi murio
        // la conversacion del 21 ("Semi\n9 am").
        return app(EnvioDirecto::class)->opciones(
            $caller,
            $texto,
            [...array_map(fn (array $h, int $i) => array_filter([
                'id' => 'h'.$i,
                /*
                 * La HORA es el titulo, sola. Es lo unico que la clienta
                 * esta eligiendo y lo que vuelve como respuesta; meterle
                 * el nombre de la persona al lado la hace competir con el
                 * dato que importa y ademas se corta en 24 caracteres.
                 * El servicio y con quien van debajo, en la descripcion.
                 */
                'title' => mb_substr($h['hora'], 0, 24),
                'description' => mb_substr(
                    $queServicio.(($h['con'] ?? '') !== '' ? ' · con '.$h['con'] : ''),
                    0,
                    72,
                ),
            ]), $horas, array_keys($horas)), ...$salidas],
            'Ver horas',
        );
    }

    /**
     * Los servicios, nombrados como los nombraria una persona.
     *
     * "Semipermanente y Semipermanente" se lee como un error: cuando dos
     * clientas piden lo mismo se nombra una vez, diciendo que son dos.
     *
     * @param  list<string>  $servicios
     */
    private function comoSeNombran(array $servicios): string
    {
        $distintos = array_values(array_unique($servicios));

        return count($distintos) === 1 && count($servicios) > 1
            ? $distintos[0].' (para '.count($servicios).' personas)'
            : implode(' y ', $distintos);
    }

    /**
     * Las horas de una franja del dia, como las dice la gente.
     *
     * @param  list<array<string, mixed>>  $slots
     * @return list<array<string, mixed>>
     */
    private function deLaFranja(array $slots, ?string $franja, string $tz): array
    {
        if ($franja === null) {
            return $slots;
        }

        [$desde, $hasta] = match (str_replace('ñ', 'n', $franja)) {
            'manana' => [0, 12],
            'tarde' => [12, 18],
            default => [18, 24],
        };

        return array_values(array_filter($slots, function (array $s) use ($desde, $hasta, $tz) {
            $hora = (int) $s['starts_at']->setTimezone($tz)->format('H');

            return $hora >= $desde && $hora < $hasta;
        }));
    }

    /**
     * Unas cuantas repartidas a lo largo de lo que hay.
     *
     * Ofrecer 10:00, 10:15, 10:30 y 10:45 es ofrecer la misma hora cuatro
     * veces: quien no puede a las diez tampoco puede a las diez y cuarto.
     *
     * @param  list<array<string, mixed>>  $horas
     * @return list<array<string, mixed>>
     */
    private function repartidas(array $horas, int $cuantas): array
    {
        if (count($horas) <= $cuantas) {
            return $horas;
        }

        $paso = (count($horas) - 1) / ($cuantas - 1);

        return array_values(array_map(
            fn (int $i) => $horas[(int) round($i * $paso)],
            range(0, $cuantas - 1),
        ));
    }

    private function variasSedes(int $businessId): bool
    {
        return Location::withoutGlobalScope('business')
            ->where('business_id', $businessId)
            ->where('is_active', true)
            ->count() > 1;
    }
}
