<?php

namespace App\Services\Migration\Importadores;

use App\Models\Client;
use App\Models\Service;
use App\Models\ServicePackage;
use App\Services\Scheduling\BookingService;
use App\Services\Scheduling\Exceptions\SlotUnavailableException;
use Throwable;

/**
 * Las citas que todavia no han ocurrido.
 *
 * ES EL UNICO PASO QUE NO ESCRIBE CON SQL. Todas las demas filas de esta
 * migracion son historia -- ya paso, no puede chocar con nada -- pero una
 * cita futura ocupa un horario, y dos citas encima de la misma manicurista a
 * la misma hora es el problema que este sistema existe para no tener.
 *
 * Por eso entran por `BookingService::book()`, que reclama la ocupacion
 * contra el indice unico de `resource_occupancy`. Insertarlas a mano seria
 * saltarse justo la garantia que justifica todo el diseno del motor.
 *
 * `enforceSchedule` va APAGADO. El sistema viejo agendaba sobre una rejilla
 * de bloques fijos de 120 minutos y con horarios que no siempre coincidian
 * con los turnos configurados; exigir el horario aqui rechazaria citas que en
 * el local existen de verdad y que la clienta tiene anotadas. El anti-solape
 * NO se apaga: eso se respeta siempre.
 *
 * Si una cita choca, se reporta y se sigue. Un reporte de tres citas
 * conflictivas que alguien resuelve a mano es mejor que un solape silencioso.
 */
class ImportaCitasFuturas extends Importador
{
    /** AGENDADO en `service_statuses` del legacy. */
    private const AGENDADO = 4;

    /** @var array<int, int> id de servicio nuevo => duracion en minutos */
    private array $duraciones = [];

    public function nombre(): string
    {
        return 'Citas futuras';
    }

    public function correr(): void
    {
        $booking = app(BookingService::class);

        $this->duraciones = Service::withoutGlobalScope('business')
            ->where('business_id', $this->business->id)
            ->pluck('duration_min', 'id')
            ->map(fn ($m) => (int) $m)
            ->all();

        $filas = $this->legacy('employee_services')
            ->join('appointments', 'appointments.id', '=', 'employee_services.appointment_id')
            ->join('time_slots', 'time_slots.id', '=', 'appointments.time_slot_id')
            ->where('employee_services.status_id', self::AGENDADO)
            ->whereNull('employee_services.deleted_at')
            ->whereDate('employee_services.date', '>=', now(self::ZONA_LEGACY)->toDateString())
            ->orderBy('employee_services.date')
            ->get([
                'employee_services.id as id',
                'employee_services.service_id',
                'employee_services.employee_id',
                'employee_services.client_id',
                'employee_services.client_name',
                'employee_services.client_cellphone',
                'employee_services.date',
                'time_slots.start_time',
            ]);

        foreach ($filas as $fila) {
            $this->una($booking, $fila);
        }
    }

    /**
     * Los servicios que hay que reservar: uno, o las partes de un combo.
     *
     * @return list<int>
     */
    private function serviciosDe(int $legacyServicio): array
    {
        if (! isset(ImportaCatalogo::COMBOS[$legacyServicio])) {
            $id = $this->map->idNuevo('service', $legacyServicio);

            return $id === null ? [] : [$id];
        }

        $partes = [];

        foreach (ImportaCatalogo::COMBOS[$legacyServicio] as $parteLegacy) {
            $id = $this->map->idNuevo('service', $parteLegacy);

            if ($id === null) {
                return [];
            }

            $partes[] = $id;
        }

        return $partes;
    }

    private function una(BookingService $booking, object $fila): void
    {
        $legacyId = (int) $fila->id;

        if ($this->map->yaExiste('future_appointment', $legacyId)) {
            $this->reporte->saltado('Citas futuras');

            return;
        }

        $recurso = $this->map->idNuevo('resource', $fila->employee_id);

        if ($recurso === null) {
            $this->reporte->aviso(
                'Citas futuras',
                "La cita {$legacyId} la presta una empleada que no se importo.",
            );

            return;
        }

        /*
         * Un combo agendado a futuro reserva sus DOS partes, una detras de
         * otra, igual que en el historial: aca los combos son paquetes de
         * servicios que ya existen, no un servicio suelto. Sin esto, la unica
         * cita futura de combo se quedaria sin migrar y la clienta llegaria
         * el sabado a una agenda que no la tiene.
         */
        $servicios = $this->serviciosDe((int) $fila->service_id);

        if ($servicios === []) {
            $this->reporte->aviso(
                'Citas futuras',
                "La cita {$legacyId} apunta a un servicio que no se importo.",
            );

            return;
        }

        $inicio = $this->utcDe($fila->date, (string) $fila->start_time);

        if ($inicio === null) {
            $this->reporte->aviso('Citas futuras', "La cita {$legacyId} no tiene fecha utilizable.");

            return;
        }

        if ($this->simular) {
            $this->reporte->creado('Citas futuras');

            return;
        }

        $clienteId = $this->map->idNuevo('client', $fila->client_id);
        $cliente = $clienteId === null ? null : Client::withoutGlobalScope('business')->find($clienteId);

        try {
            $desde = $inicio->setTimezone(self::ZONA_LEGACY);
            $items = [];

            foreach ($servicios as $servicioId) {
                $items[] = [
                    'service_id' => $servicioId,
                    'resource_id' => $recurso,
                    // En la zona del negocio: `book()` interpreta la hora
                    // local, y pasarle UTC la correria cinco horas.
                    'starts_at' => $desde,
                ];

                $desde = $desde->addMinutes($this->duraciones[$servicioId] ?? 60);
            }

            $cita = $booking->book(
                $this->business,
                $items,
                $cliente,
                $cliente === null ? (trim((string) $fila->client_name) ?: 'Sin nombre') : null,
                $cliente === null ? (trim((string) $fila->client_cellphone) ?: null) : null,
                'admin',
                'Importada del sistema anterior.',
                // Ver el comentario de la clase: el horario no se exige, el
                // anti-solape si.
                enforceSchedule: false,
                package: $this->map->idNuevo('service_package', $fila->service_id) === null
                    ? null
                    : ServicePackage::withoutGlobalScope('business')
                        ->find($this->map->idNuevo('service_package', $fila->service_id)),
            );
        } catch (SlotUnavailableException) {
            /*
             * Otra cita ya ocupa ese horario. Casi siempre significa que el
             * sistema viejo permitia lo que este no: dos clientas encima de
             * la misma persona.
             */
            $this->reporte->aviso(
                'Citas futuras',
                "La cita {$legacyId} ({$fila->date} {$fila->start_time}) choca con otra ya agendada. "
                .'Hay que resolverla a mano.',
            );

            return;
        } catch (Throwable $e) {
            $this->reporte->aviso('Citas futuras', "La cita {$legacyId} no se pudo crear: {$e->getMessage()}");

            return;
        }

        $this->map->anotar('future_appointment', $legacyId, $cita->id);
        $this->reporte->creado('Citas futuras');
    }
}
