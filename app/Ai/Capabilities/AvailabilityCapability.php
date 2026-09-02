<?php

namespace App\Ai\Capabilities;

use App\Ai\AiCaller;
use App\Ai\Capability;
use App\Ai\Resolves;
use App\Services\Scheduling\AvailabilityService;
use Carbon\CarbonImmutable;

/**
 * Las horas que de verdad quedan libres.
 *
 * Reusa `AvailabilityService`, el mismo motor que alimenta la pagina publica
 * y la agenda: horarios, descansos, buffers, excepciones y preaviso minimo
 * salen de ahi. Una version propia "mas simple" ofreceria huecos que no
 * existen, y el agente terminaria prometiendo horas que el sistema rechaza.
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
            'servicio' => ['required', 'string', 'max:255'],
            'fecha' => ['required', 'date_format:Y-m-d'],
            'empleado' => ['nullable', 'string', 'max:255'],
            'sede' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function execute(AiCaller $caller, array $arguments): array
    {
        $business = $caller->business;
        $tz = $business->businessTimezone();

        $servicio = $this->resolveService($business->id, $arguments['servicio']);
        $sede = $this->resolveLocation($business->id, $arguments['sede'] ?? null);

        $persona = isset($arguments['empleado'])
            ? $this->resolveResource($business->id, $arguments['empleado'], $sede?->id)
            : null;

        $slots = $this->availability->slotsForService(
            $business,
            $servicio,
            CarbonImmutable::parse($arguments['fecha'], $tz),
            $persona,
            null,
            $sede?->id,
        );

        return [
            'servicio' => $servicio->name,
            'fecha' => $arguments['fecha'],
            'sede' => $sede?->name,
            /*
             * Un tope: una jornada entera en granularidad de 15 minutos son
             * decenas de horas, y volcarlas todas en el contexto del modelo
             * gasta tokens para que igual recite las primeras. Con 12 alcanza
             * para ofrecer opciones repartidas en el dia.
             */
            'horas' => collect($slots)->take(12)->map(fn (array $s) => [
                'hora' => $s['starts_at']->setTimezone($tz)->format('H:i'),
                'con' => $s['resource_name'],
            ])->all(),
            'hay_mas' => count($slots) > 12,
        ];
    }
}
