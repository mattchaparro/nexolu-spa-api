<?php

namespace App\Ai\Capabilities;

use App\Ai\AiCaller;
use App\Ai\Capability;
use App\Ai\HoraLegible;
use App\Ai\Resolves;
use App\Models\Location;
use App\Models\Service;
use App\Services\Scheduling\AvailabilityService;
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
 */
class AvailabilityCapability implements Capability
{
    use Resolves;

    public function __construct(private readonly AvailabilityService $availability) {}

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
            'fecha' => ['required', 'date_format:Y-m-d'],
            'empleado' => ['nullable', 'string', 'max:255'],
            'sede' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function execute(AiCaller $caller, array $arguments): array
    {
        $business = $caller->business;
        $tz = $business->businessTimezone();
        $fecha = CarbonImmutable::parse($arguments['fecha'], $tz);

        $nombres = $arguments['servicios'] ?? [$arguments['servicio']];
        $servicios = array_map(fn (string $n) => $this->resolveService($business->id, $n), $nombres);

        $sede = $this->resolveLocation($business->id, $arguments['sede'] ?? null);
        $persona = isset($arguments['empleado'])
            ? $this->resolveResource($business->id, $arguments['empleado'], $sede?->id)
            : null;

        $slots = count($servicios) === 1
            ? $this->availability->slotsForService($business, $servicios[0], $fecha, $persona, null, $sede?->id)
            : $this->availability->slotsForChain($business, $servicios, $fecha, null, $persona?->id, $sede?->id);

        return array_filter([
            'servicios' => array_map(fn (Service $s) => $s->name, $servicios),
            'fecha' => $arguments['fecha'],
            // Con una sola sede, nombrarla es ruido: la clienta no esta
            // eligiendo entre dos locales, y "en la sede Principal" en cada
            // mensaje suena a sistema, no a la recepcion del salon.
            'sede' => $this->variasSedes($business->id) ? $sede?->name : null,
            /*
             * Un tope: una jornada entera en granularidad de 15 minutos son
             * decenas de horas, y volcarlas todas en el contexto del modelo
             * gasta tokens para que igual recite las primeras.
             */
            'horas' => collect($slots)->take(12)->map(fn (array $s) => array_filter([
                // `hora` es para MOSTRAR ("3 pm") y `hora_24` para volver a
                // llamar (crear_cita pide H:i).
                'hora' => HoraLegible::de($s['starts_at'], $tz),
                'hora_24' => $s['starts_at']->setTimezone($tz)->format('H:i'),
                'con' => $s['resource_name'] ?? collect($s['legs'] ?? [])->pluck('resource_name')->unique()->implode(' y '),
            ], fn ($v) => $v !== null && $v !== ''))->all(),
            'hay_mas' => count($slots) > 12,
            /*
             * La instruccion viaja con el dato, no solo en el prompt: el
             * modelo escribia las horas como texto y la clienta tenia que
             * transcribir una. Decirselo aca, pegado a las horas, es lo que
             * de verdad cambia el comportamiento.
             */
            'instruccion' => 'Muestraselas con `ofrecer_opciones` (tres o cuatro, repartidas). '
                .'NO las escribas en el texto.',
        ], fn ($v) => $v !== null);
    }

    private function variasSedes(int $businessId): bool
    {
        return Location::withoutGlobalScope('business')
            ->where('business_id', $businessId)
            ->where('is_active', true)
            ->count() > 1;
    }
}
