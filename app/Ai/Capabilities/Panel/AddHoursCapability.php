<?php

namespace App\Ai\Capabilities\Panel;

use App\Ai\AiArgumentException;
use App\Ai\AiCaller;
use App\Ai\Capability;
use App\Ai\HoraLegible;
use App\Ai\Resolves;
use App\Models\ScheduleException;
use App\Services\Scheduling\AvailabilityService;
use Carbon\CarbonImmutable;

/**
 * «Alejandra trabaja hoy hasta las 8.» / «Mañana trabaja de 10 a 12.»
 *
 * Es la pareja de `bloquear_horario`: en vez de quitar disponibilidad, la
 * suma. Crea un cambio de un día (ScheduleException `extra_hours`), el mismo
 * que el modal «Cambios de un día» del horario: esas horas salen disponibles
 * en la agenda, en el bot y en la web, aunque ese día no sea de su turno.
 *
 * En el IA Core es una escritura: el asistente arma un borrador y esto solo
 * corre cuando la persona lo confirma en la tarjeta.
 */
class AddHoursCapability implements Capability
{
    use PanelDates, Resolves;

    public function __construct(private readonly AvailabilityService $availability) {}

    public function requiredPermission(): ?string
    {
        return 'horarios.gestionar';
    }

    public function requiredFeature(): ?string
    {
        return null;
    }

    public function allowsCustomers(): bool
    {
        return false;
    }

    public function rules(): array
    {
        return [
            'persona' => ['required', 'string', 'max:120'],
            'fecha' => ['nullable', 'string', 'max:40'],
            'hora_inicio' => ['nullable', 'date_format:H:i'],
            'hora_fin' => ['required', 'date_format:H:i'],
            'motivo' => ['nullable', 'string', 'max:60'],
        ];
    }

    public function execute(AiCaller $caller, array $arguments): array
    {
        $business = $caller->business;
        $tz = $business->businessTimezone();
        $persona = $this->resolveResource($business->id, $arguments['persona']);
        $dia = $this->dia($arguments['fecha'] ?? null, $tz);

        if ($dia->lt(CarbonImmutable::now($tz)->startOfDay())) {
            throw new AiArgumentException('Ese día ya pasó.');
        }

        $fin = $arguments['hora_fin'];
        $inicio = $arguments['hora_inicio'] ?? null;

        /*
         * «Hasta las 8» no dice desde cuándo: se suma desde donde TERMINA su
         * turno de ese día (así el cambio se lee «de 5:00 pm a 8:00 pm» y no
         * repite lo que ya trabaja). Si ese día no tiene turno no hay de
         * dónde adivinarlo, y se pregunta.
         */
        if ($inicio === null) {
            $ventanas = $this->availability->workingWindowsFor($business, $persona, $dia, $tz);

            if ($ventanas === []) {
                throw new AiArgumentException(
                    "{$persona->name} no tiene turno ese día: pregunta desde qué hora y hasta qué hora trabaja."
                );
            }

            $termina = end($ventanas)->end->setTimezone($tz)->format('H:i');

            if ($fin <= $termina) {
                throw new AiArgumentException(
                    "{$persona->name} ya trabaja hasta las ".HoraLegible::deTexto($termina).' ese día.'
                );
            }

            $inicio = $termina;
        }

        if ($fin <= $inicio) {
            throw new AiArgumentException('La hora final tiene que ser después de la inicial.');
        }

        $cambio = ScheduleException::withoutGlobalScopes()->create([
            'business_id' => $business->id,
            'resource_id' => $persona->id,
            'kind' => ScheduleException::KIND_EXTRA_HOURS,
            'starts_at' => CarbonImmutable::parse($dia->toDateString().' '.$inicio, $tz)->utc(),
            'ends_at' => CarbonImmutable::parse($dia->toDateString().' '.$fin, $tz)->utc(),
            'reason' => isset($arguments['motivo']) ? trim($arguments['motivo']) ?: null : null,
        ]);

        return [
            'agregado' => true,
            'id' => $cambio->id,
            'persona' => $persona->name,
            'fecha' => $dia->locale('es')->isoFormat('dddd D [de] MMMM'),
            'horas' => HoraLegible::de(CarbonImmutable::parse($dia->toDateString().' '.$inicio, $tz), $tz)
                .' a '.HoraLegible::de(CarbonImmutable::parse($dia->toDateString().' '.$fin, $tz), $tz),
        ];
    }
}
