<?php

namespace App\Services\Migration\Importadores;

use App\Models\Appointment;
use App\Models\Client;
use App\Models\Resource;
use App\Models\Service;
use App\Models\ServicePackage;
use App\Services\Scheduling\BookingService;
use App\Services\Scheduling\Exceptions\SlotUnavailableException;
use Illuminate\Support\Facades\DB;
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

        /*
         * Para decidir que cancelar se miran TODAS las agendadas del sistema
         * viejo, sin filtro de fecha.
         *
         * La lista de arriba solo trae las de hoy en adelante, que es lo que
         * hay que crear. Pero si se usara esa misma lista para cancelar, una
         * cita de ayer que alla sigue agendada -- porque nadie la cerro --
         * se cancelaria aca sin que nadie la haya cancelado.
         */
        $this->cancelarLasQueYaNoEstan(
            $this->legacy('employee_services')
                ->where('status_id', self::AGENDADO)
                ->whereNull('deleted_at')
                ->pluck('id')
                ->all(),
        );
    }

    /**
     * Si la cita se movio de hora o de persona en el sistema viejo, se mueve aca.
     *
     * Sin esto, reagendar alla no llegaba: la fila sigue siendo la misma y el
     * paso la daba por hecha. La clienta quedaba en la agenda nueva a la hora
     * vieja, y quien la mirara preparia el puesto a la hora equivocada.
     *
     * Se mueve por `BookingService::reschedule()` y no con un UPDATE: mover
     * una cita libera su ocupacion y reclama la nueva, y saltarse eso seria
     * dejar el horario viejo bloqueado y el nuevo libre para que otra clienta
     * lo tome encima.
     */
    private function moverSiCambio(int $citaId, object $fila): void
    {
        $cita = Appointment::withoutGlobalScope('business')->find($citaId);

        // Solo las que siguen pendientes: una ya cobrada no se mueve.
        if ($cita === null || $cita->status !== Appointment::STATUS_PENDING) {
            $this->reporte->saltado('Citas futuras');

            return;
        }

        $inicio = $this->utcDe($fila->date, (string) $fila->start_time);
        $recurso = $this->map->idNuevo('resource', $fila->employee_id);

        if ($inicio === null || $recurso === null) {
            $this->reporte->saltado('Citas futuras');

            return;
        }

        $mismaHora = $cita->starts_at !== null
            && $cita->starts_at->equalTo($inicio);

        $mismaPersona = (int) ($cita->items()->value('resource_id') ?? 0) === $recurso;

        if ($mismaHora && $mismaPersona) {
            $this->reporte->saltado('Citas futuras');

            return;
        }

        if ($this->simular) {
            $this->reporte->actualizado('Citas futuras');

            return;
        }

        try {
            app(BookingService::class)->reschedule(
                $cita,
                $inicio->setTimezone(self::ZONA_LEGACY),
                $mismaPersona ? null : Resource::withoutGlobalScope('business')->find($recurso),
            );
        } catch (SlotUnavailableException) {
            $this->reporte->aviso(
                'Citas futuras',
                "La cita {$fila->id} se movio en el sistema viejo a un horario que aca ya esta "
                .'ocupado. Hay que resolverla a mano.',
            );

            return;
        } catch (Throwable $e) {
            $this->reporte->aviso('Citas futuras', "La cita {$fila->id} no se pudo mover: {$e->getMessage()}");

            return;
        }

        $this->reporte->actualizado('Citas futuras');
        $this->reporte->aviso(
            'Citas futuras',
            "La cita {$fila->id} se movio en el sistema viejo: se movio aca tambien.",
        );
    }

    /**
     * Las citas que se cancelaron o se movieron en el sistema viejo.
     *
     * Sincronizando cada cinco minutos esto deja de ser un detalle: una cita
     * que la clienta cancelo a las diez sigue ocupando el horario aca y
     * bloquea a quien quiera ese cupo. Y quien mire la agenda va a preparar
     * un servicio que nadie va a recibir.
     *
     * Se cancela, no se borra: una cita cancelada es informacion -- explica un
     * hueco en el dia -- y ademas `cancel()` libera la ocupacion, que es lo
     * que de verdad hay que soltar.
     *
     * @param  list<int>  $vigentes  Los ids que el sistema viejo tiene agendados.
     */
    private function cancelarLasQueYaNoEstan(array $vigentes): void
    {
        if ($this->simular) {
            return;
        }

        $mias = DB::table('legacy_map')
            ->where('business_id', $this->business->id)
            ->where('entity', 'appointment')
            ->pluck('new_id', 'legacy_id');

        $pendientes = Appointment::withoutGlobalScope('business')
            ->where('business_id', $this->business->id)
            ->where('status', Appointment::STATUS_PENDING)
            ->pluck('id')
            ->all();

        if ($pendientes === []) {
            return;
        }

        $vigentes = array_flip($vigentes);
        $booking = app(BookingService::class);

        foreach ($mias as $legacyId => $nuevoId) {
            if (! in_array($nuevoId, $pendientes, true) || isset($vigentes[(int) $legacyId])) {
                continue;
            }

            $cita = Appointment::withoutGlobalScope('business')->find($nuevoId);

            if ($cita === null) {
                continue;
            }

            $booking->cancel($cita, null, 'Cancelada en el sistema anterior.');

            $this->reporte->actualizado('Citas futuras');
            $this->reporte->aviso(
                'Citas futuras',
                "La cita {$legacyId} ya no esta agendada en el sistema viejo: se cancelo aca y se "
                .'libero el horario.',
            );
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

        /*
         * MISMA llave que el historial: `appointment`.
         *
         * Es la misma fila del sistema viejo. Cuando esa cita se cobre alla,
         * el paso del historial la va a encontrar ya creada y la va a
         * COMPLETAR en su sitio, en vez de crear una segunda cita para la
         * misma atencion. Con llaves distintas se duplicaba, y sincronizando
         * cada cinco minutos eso pasa el mismo dia.
         */
        $yaCreada = $this->map->idNuevo('appointment', $legacyId);

        if ($yaCreada !== null) {
            $this->moverSiCambio($yaCreada, $fila);

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

        $this->map->anotar('appointment', $legacyId, $cita->id);
        $this->reporte->creado('Citas futuras');
    }
}
