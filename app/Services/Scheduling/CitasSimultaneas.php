<?php

namespace App\Services\Scheduling;

use App\Models\Business;
use App\Models\Service;
use Carbon\CarbonImmutable;

/**
 * Dos personas a la MISMA hora: la señora y su hija, ella y su esposo.
 *
 * Es distinto de una cadena ("manos y pies"), que es una sola persona
 * pasando de un servicio al siguiente. Acá hay dos clientas sentadas al
 * tiempo, y por eso hacen falta dos profesionales libres a la vez.
 *
 * Es de los pedidos que más plata mueven y el bot no podía con ellos:
 * terminaba agendando una sola cita y dejando a alguien sin puesto, o
 * diciendo que no se podía cuando sí.
 *
 * El cálculo no lo inventa: pregunta por cada servicio las horas libres
 * con CADA profesional (el mismo motor de siempre, con sus buffers y
 * excepciones) y se queda con las horas donde alcanza a repartir gente
 * distinta para cada persona.
 */
class CitasSimultaneas
{
    public function __construct(private readonly AvailabilityService $availability) {}

    /**
     * Las horas en que las N personas caben al tiempo.
     *
     * @param  list<Service>  $servicios  uno por persona, en orden
     * @return list<array{
     *   starts_at: CarbonImmutable,
     *   asignacion: list<array{service_id: int, service_name: string, resource_id: int, resource_name: string}>
     * }>
     */
    public function slots(
        Business $business,
        array $servicios,
        CarbonImmutable $fecha,
        ?int $locationId = null,
    ): array {
        if (count($servicios) < 2) {
            return [];
        }

        // Por persona: qué profesionales puede tener a cada hora.
        $porPersona = [];

        foreach ($servicios as $indice => $servicio) {
            foreach ($this->availability->slotsForService($business, $servicio, $fecha, null, null, $locationId) as $slot) {
                $hora = $slot['starts_at']->format('H:i');
                $porPersona[$indice][$hora][] = [
                    'resource_id' => $slot['resource_id'],
                    'resource_name' => $slot['resource_name'],
                    'starts_at' => $slot['starts_at'],
                ];
            }
        }

        if (count($porPersona) !== count($servicios)) {
            // Alguien no tiene NINGUNA hora ese día: no hay nada que cruzar.
            return [];
        }

        $comunes = array_keys($porPersona[0]);

        foreach ($porPersona as $opciones) {
            $comunes = array_intersect($comunes, array_keys($opciones));
        }

        sort($comunes);

        $resultado = [];

        foreach ($comunes as $hora) {
            $asignacion = $this->repartir($servicios, $porPersona, $hora);

            if ($asignacion === null) {
                // La hora existe para las dos, pero con la MISMA persona:
                // no se puede partir en dos sillas.
                continue;
            }

            $resultado[] = [
                'starts_at' => $porPersona[0][$hora][0]['starts_at'],
                'asignacion' => $asignacion,
            ];
        }

        return $resultado;
    }

    /**
     * Reparte una profesional distinta a cada persona, si alcanza.
     *
     * Backtracking y no "la primera libre": con dos personas y dos
     * profesionales donde una sola puede hacer el segundo servicio,
     * elegirla para la primera persona rompe la cita entera aunque sí
     * cupiera. Son dos o tres personas, así que el costo no importa.
     *
     * @param  list<Service>  $servicios
     * @return list<array{service_id: int, service_name: string, resource_id: int, resource_name: string}>|null
     */
    private function repartir(array $servicios, array $porPersona, string $hora, int $persona = 0, array $tomadas = []): ?array
    {
        if ($persona >= count($servicios)) {
            return [];
        }

        foreach ($porPersona[$persona][$hora] as $opcion) {
            if (in_array($opcion['resource_id'], $tomadas, true)) {
                continue;
            }

            $resto = $this->repartir(
                $servicios,
                $porPersona,
                $hora,
                $persona + 1,
                [...$tomadas, $opcion['resource_id']],
            );

            if ($resto !== null) {
                return [
                    [
                        'service_id' => $servicios[$persona]->id,
                        'service_name' => $servicios[$persona]->name,
                        'resource_id' => $opcion['resource_id'],
                        'resource_name' => $opcion['resource_name'],
                    ],
                    ...$resto,
                ];
            }
        }

        return null;
    }
}
