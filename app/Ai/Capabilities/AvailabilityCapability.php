<?php

namespace App\Ai\Capabilities;

use App\Ai\AiCaller;
use App\Ai\Capability;
use App\Ai\FechaDicha;
use App\Ai\HoraLegible;
use App\Ai\OpcionesEnviadas;
use App\Ai\Resolves;
use App\Models\Location;
use App\Models\Service;
use App\Services\Scheduling\AvailabilityService;
use App\Services\Scheduling\CitasSimultaneas;
use App\Services\WhatsApp\NexoluCommsChannel;
use App\Support\ChannelPhone;
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
            'servicio' => ['required_without:servicios', 'string', 'max:255'],
            'servicios' => ['required_without:servicio', 'array', 'min:1', 'max:5'],
            'servicios.*' => ['required', 'string', 'max:255'],
            // Texto y no `date_format`: "el lunes" lo resuelve el código,
            // no el modelo, que se equivocó ofreciendo el martes.
            'fecha' => ['required', 'string', 'max:40'],
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
        ];
    }

    public function execute(AiCaller $caller, array $arguments): array
    {
        $business = $caller->business;
        $tz = $business->businessTimezone();
        $fecha = FechaDicha::resolver($arguments['fecha'], $tz);

        if ($fecha === null) {
            return [
                'horas' => [],
                'falta_informacion' => "No entendí la fecha «{$arguments['fecha']}».",
                'instruccion' => 'Pregúntale qué día quiere (puedes decirle "hoy", "mañana" '
                    .'o un día de la semana) y vuelve a intentarlo.',
            ];
        }

        $nombres = $arguments['servicios'] ?? [$arguments['servicio']];
        $servicios = array_map(fn (string $n) => $this->resolveService($business->id, $n), $nombres);

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
            return [
                'servicios' => $nombreServicios,
                'fecha' => $fecha->format('Y-m-d'),
                'dia' => $fecha->locale('es')->isoFormat('dddd D [de] MMMM'),
                'horas' => [],
                'instruccion' => ($juntas
                    ? 'No hay ninguna hora ese día con suficientes profesionales libres al tiempo'
                    : 'No hay horas ese día').($arguments['franja'] ?? null ? ' en esa franja' : '')
                    .'. Ofrécele otro día u otra franja, y vuelve a llamarme.',
            ];
        }

        // Repartidas, no las primeras cuatro seguidas: ofrecer 10:00,
        // 10:15, 10:30 y 10:45 es ofrecer la misma hora cuatro veces.
        $ofrecidas = $this->repartidas($horas->all(), self::MAX_OPCIONES);
        $mostrado = $this->mostrar($caller, $nombreServicios, $fecha, $ofrecidas);

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
            'horas' => $horas->take(12)->all(),
            'instruccion' => $mostrado
                ? 'Las horas YA le llegaron como botones y las está viendo. NO llames a '
                    .'`ofrecer_opciones` con ellas ni las escribas: responde con una cadena '
                    .'vacía. Cuando toque una, te llega como su próximo mensaje.'
                : 'Ofrécele dos o tres de `ofrecidas` usando el campo `hora`, nunca `hora_24`.',
        ], fn ($v) => $v !== null);
    }

    /**
     * Manda las horas como botones y deja la marca para que el job no
     * escriba encima. Si el canal falla, el modelo las escribe.
     *
     * @param  list<string>  $servicios
     * @param  list<array{hora: string, hora_24: string, con?: string}>  $horas
     */
    private function mostrar(AiCaller $caller, array $servicios, CarbonImmutable $fecha, array $horas): bool
    {
        $phone = ChannelPhone::normalize((string) $caller->phone, $caller->business->country_code ?? 'CO');

        if ($phone === null || $caller->isStaff()) {
            return false;
        }

        $queServicio = $this->comoSeNombran($servicios);

        $texto = sprintf(
            'Para *%s* el *%s* tengo estas horas 👇',
            $queServicio,
            $fecha->locale('es')->isoFormat('dddd D [de] MMMM'),
        );

        $enviado = $this->channel->sendOptions(
            $phone,
            $texto,
            array_map(fn (array $h, int $i) => array_filter([
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
            ]), $horas, array_keys($horas)),
            $caller->business->id,
            'Ver horas',
        );

        if ($enviado) {
            OpcionesEnviadas::marcar($phone);
        }

        return $enviado;
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
