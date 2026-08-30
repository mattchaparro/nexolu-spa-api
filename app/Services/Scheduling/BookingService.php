<?php

namespace App\Services\Scheduling;

use App\Models\Appointment;
use App\Models\AppointmentItem;
use App\Models\Business;
use App\Models\Client;
use App\Models\Resource;
use App\Models\ResourceOccupancy;
use App\Models\Service;
use App\Models\ServicePackage;
use App\Models\User;
use App\Services\Scheduling\Exceptions\OutsideWorkingHoursException;
use App\Services\Scheduling\Exceptions\SlotUnavailableException;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Crea, reagenda y cancela citas.
 *
 * La garantia contra doble reserva no vive aca: vive en el indice unico
 * (resource_id, slot_start) de resource_occupancy. Este servicio solo se
 * encarga de escribir esas filas DENTRO de la misma transaccion que la cita, y
 * de traducir la violacion de constraint a un error de dominio.
 *
 * Es una diferencia importante. Un chequeo previo tipo "esta libre?" siempre
 * deja una ventana entre la lectura y la escritura; con reserva online abierta
 * y un agente de WhatsApp agendando en paralelo, esa ventana se materializa.
 * Aca la base rechaza la segunda escritura y no hay carrera que ganar.
 */
class BookingService
{
    public function __construct(private readonly AvailabilityService $availability) {}

    /**
     * @param  list<array{service_id:int, resource_id:int, starts_at:string|CarbonImmutable}>  $items
     */
    public function book(
        Business $business,
        array $items,
        ?Client $client,
        ?string $clientName = null,
        ?string $clientPhone = null,
        string $source = Appointment::SOURCE_ADMIN,
        ?string $notes = null,
        bool $enforceSchedule = true,
        ?ServicePackage $package = null,
    ): Appointment {
        if ($items === []) {
            throw new \InvalidArgumentException('Una cita necesita al menos un servicio.');
        }

        $tz = $business->businessTimezone();
        $granularity = (int) $business->schedulingSetting('slot_granularity_min');

        return DB::transaction(function () use ($business, $items, $client, $clientName, $clientPhone, $source, $notes, $tz, $granularity, $enforceSchedule, $package) {
            $resolved = $this->resolveItems($business, $items, $tz, $enforceSchedule);
            $this->assertSingleLocation($resolved);

            $appointment = Appointment::create([
                'business_id' => $business->id,
                /*
                 * La sede sale de quien atiende, y se CONGELA. Si manana esa
                 * persona se traslada, el cierre de caja de hace tres meses no
                 * puede cambiar de local: misma regla que el precio y la
                 * comision.
                 *
                 * De la primera linea: una visita ocurre en un solo local, y
                 * repartir una cita entre sedes no es algo que exista.
                 */
                'location_id' => $resolved[0]['resource']->location_id,
                'client_id' => $client?->id,
                'client_name' => $clientName ?? $client?->fullName(),
                'client_phone' => $clientPhone ?? $client?->phone,
                'starts_at' => min(array_column($resolved, 'service_starts_at')),
                'ends_at' => max(array_column($resolved, 'service_ends_at')),
                'status' => Appointment::STATUS_PENDING,
                // La etapa inicial del flujo del negocio, si tiene uno. La
                // cita nace con el nombre que el negocio le da, no con el
                // interno: en el mostrador dice "Agendada", no "pending".
                'stage_id' => $business->appointmentWorkflow?->initialStage()?->id,
                // De que combo sale. El combo se elige al AGENDAR y su
                // descuento se aplica al COBRAR: sin recordarlo, quien cobra
                // tres dias despues no sabe que esas lineas eran un combo y
                // las cobra a precio de lista.
                'service_package_id' => $package?->id,
                'source' => $source,
                'notes' => $notes,
            ]);

            foreach ($resolved as $index => $row) {
                $item = AppointmentItem::create([
                    'business_id' => $business->id,
                    'appointment_id' => $appointment->id,
                    'service_id' => $row['service']->id,
                    'resource_id' => $row['resource']->id,
                    'starts_at' => $row['starts_at'],
                    'ends_at' => $row['ends_at'],
                    'service_starts_at' => $row['service_starts_at'],
                    'service_ends_at' => $row['service_ends_at'],
                    /*
                     * Una garantia vale 0 y no paga comision, y se fuerza aca
                     * y no en el cobro: si dependiera de que alguien acuerde
                     * poner el descuento al cobrar, tarde o temprano se cobra
                     * una garantia, y eso se descubre con la clienta delante.
                     */
                    'price' => ($row['is_warranty'] ?? false) ? 0 : $row['service']->price,
                    // Se congela el porcentaje vigente al agendar, y el del
                    // recurso concreto -- no el generico del servicio. Que
                    // el negocio cambie sus porcentajes manana no debe
                    // reescribir lo que ya se pacto en una cita existente.
                    'commission_rate' => ($row['is_warranty'] ?? false)
                        ? 0
                        : $row['service']->commissionRateFor($row['resource']),
                    'sort_order' => $index,

                    'is_warranty' => $row['is_warranty'] ?? false,
                    // A quien se le anota: quien hizo el trabajo que fallo, no
                    // quien lo rehace.
                    'warranty_for_resource_id' => $row['warranty_for_resource_id'] ?? null,
                    'warranty_for_item_id' => $row['warranty_for_item_id'] ?? null,
                    'warranty_note' => $row['warranty_note'] ?? null,
                ]);

                $this->claimOccupancy($business, $item, $granularity);
            }

            /*
             * El abono se congela AL RESERVAR, sobre lo que el cliente va a
             * pagar de verdad -- con el descuento del combo ya aplicado.
             *
             * Congelado y no recalculado: si el negocio sube el abono del 20%
             * al 40% la semana entrante, a quien ya reservo se le sigue
             * pidiendo lo que le dijo la pantalla el dia que reservo.
             *
             * Solo para lo que entra por internet. Una cita que agenda el
             * mostrador por telefono no necesita que el sistema le pida un
             * adelanto: ahi hay alguien del local decidiendo.
             */
            if ($source === Appointment::SOURCE_ONLINE) {
                $base = $package !== null
                    ? $package->loadMissing('services')->quote()['total']
                    : array_sum(array_map(fn (array $row) => (float) $row['service']->price, $resolved));

                $deposit = $business->depositFor((float) $base);

                if ($deposit > 0) {
                    $appointment->update(['deposit_amount' => $deposit]);
                }
            }

            return $appointment->load('items');
        });
    }

    public function reschedule(Appointment $appointment, CarbonImmutable $newStart, ?Resource $newResource = null): Appointment
    {
        $business = $appointment->business;
        $tz = $business->businessTimezone();
        $granularity = (int) $business->schedulingSetting('slot_granularity_min');

        return DB::transaction(function () use ($appointment, $newStart, $newResource, $business, $tz, $granularity) {
            $items = $appointment->items()->with(['service', 'resource'])->orderBy('sort_order')->get();

            if ($items->count() > 1) {
                /*
                 * Una cita de varios servicios se mueve ENTERA, conservando la
                 * separacion entre sus partes. Mover una pieza suelta dejaria
                 * al cliente con el pedicure media hora antes del manicure.
                 *
                 * Tampoco se le cambia de persona: en una cadena cada eslabon
                 * puede ser de alguien distinto, y "muevela a Lucia" no dice a
                 * cual de los tres se refiere. Para eso se cancela y se vuelve
                 * a reservar.
                 */
                if ($newResource !== null) {
                    throw new \DomainException(
                        'Una cita de varios servicios se mueve de hora, no de persona. '
                        .'Si hay que cambiar quién atiende, cancélala y vuelve a agendarla.'
                    );
                }

                return $this->shiftChain($appointment, $items, $newStart->setTimezone($tz), $business, $tz, $granularity);
            }

            $item = $items->first();
            $resource = $newResource ?? $item->resource;
            $service = $item->service;

            /*
             * Cambiar de persona dentro de la misma sede es rutina; cambiarla
             * a otra sede no es reagendar, es otra visita.
             *
             * Se rechaza en vez de mover la sede de la cita porque el cliente
             * cree que va al local de siempre: quien mueve la cita en la
             * pantalla no es quien se aparece en la puerta equivocada.
             */
            if ($resource->location_id !== $item->resource->location_id) {
                throw new \DomainException(
                    'Esa persona atiende en otra sede. Cancela la cita y agéndala allá, '
                    .'para que el cliente sepa a qué local ir.'
                );
            }

            // Liberar antes de reclamar: si el hueco nuevo se solapa con el
            // viejo (mover 30 minutos), el indice unico rechazaria una fila
            // que este mismo item ya posee.
            ResourceOccupancy::where('appointment_item_id', $item->id)->delete();

            $start = $newStart->setTimezone($tz);
            $window = $this->windowFor($service, $resource, $start);

            // Arrastrar en el calendario entra por aca. Sin este chequeo, una
            // cita se puede soltar encima del almuerzo con el raton.
            $this->assertWorkable($business, $resource, $window, $tz);

            $item->update([
                'resource_id' => $resource->id,
                'starts_at' => $window['starts_at'],
                'ends_at' => $window['ends_at'],
                'service_starts_at' => $window['service_starts_at'],
                'service_ends_at' => $window['service_ends_at'],
            ]);

            $this->claimOccupancy($business, $item->fresh(), $granularity);

            $appointment->update([
                'starts_at' => $window['service_starts_at'],
                'ends_at' => $window['service_ends_at'],
            ]);

            return $appointment->fresh('items');
        });
    }

    /**
     * Mueve una cita de varios servicios conservando su forma.
     *
     * Se calcula el desfase entre la hora nueva y la vieja y se aplica a TODAS
     * las lineas por igual. Asi la separacion entre los servicios se mantiene:
     * si el pedicure empezaba 15 minutos despues del manicure, sigue haciendolo.
     *
     * @param  \Illuminate\Support\Collection<int, AppointmentItem>  $items
     */
    private function shiftChain(
        Appointment $appointment,
        $items,
        CarbonImmutable $newStart,
        Business $business,
        string $tz,
        int $granularity,
    ): Appointment {
        $oldStart = CarbonImmutable::parse($items->first()->service_starts_at)->setTimezone($tz);
        $offset = $oldStart->diffInSeconds($newStart, false);

        // Liberar TODA la cadena antes de reclamar: moverla una hora hace que
        // el tercer servicio caiga donde estaba el primero, y el indice unico
        // rechazaria filas que la propia cita ya posee.
        ResourceOccupancy::whereIn('appointment_item_id', $items->pluck('id'))->delete();

        foreach ($items as $item) {
            $start = CarbonImmutable::parse($item->service_starts_at)->setTimezone($tz)->addSeconds($offset);
            $window = $this->windowFor($item->service, $item->resource, $start);

            $this->assertWorkable($business, $item->resource, $window, $tz);

            $item->update([
                'starts_at' => $window['starts_at'],
                'ends_at' => $window['ends_at'],
                'service_starts_at' => $window['service_starts_at'],
                'service_ends_at' => $window['service_ends_at'],
            ]);

            $this->claimOccupancy($business, $item->fresh(), $granularity);
        }

        $fresh = $appointment->items()->get();

        $appointment->update([
            'starts_at' => $fresh->min('service_starts_at'),
            'ends_at' => $fresh->max('service_ends_at'),
        ]);

        return $appointment->fresh('items');
    }

    public function cancel(Appointment $appointment, ?int $byUserId = null, ?string $reason = null): Appointment
    {
        return DB::transaction(function () use ($appointment, $byUserId, $reason) {
            // Liberar el horario es la garantia del nucleo, no una
            // automatizacion opcional: una cita cancelada que sigue ocupando
            // deja el hueco muerto. La accion `release_slot` existe aparte
            // para el negocio que quiera liberar en OTRA etapa -- al marcar
            // una inasistencia, por ejemplo.
            ResourceOccupancy::whereIn('appointment_item_id', $appointment->items()->pluck('id'))->delete();

            $appointment->update(['cancellation_reason' => $reason]);

            app(StageTransitionService::class)->moveToStatus(
                $appointment,
                Appointment::STATUS_CANCELLED,
                $byUserId ? User::find($byUserId) : null,
            );

            return $appointment->refresh();
        });
    }

    /**
     * Escribe una fila por unidad de granularidad que el item ocupa. Si otra
     * transaccion ya reclamo cualquiera de esas unidades, el indice unico
     * revienta y la cita entera se deshace.
     */
    private function claimOccupancy(Business $business, AppointmentItem $item, int $granularity): void
    {
        $rows = [];
        $cursor = CarbonImmutable::parse($item->starts_at);
        $end = CarbonImmutable::parse($item->ends_at);

        while ($cursor < $end) {
            $rows[] = [
                'business_id' => $business->id,
                'resource_id' => $item->resource_id,
                'appointment_item_id' => $item->id,
                'slot_start' => $cursor,
            ];

            $cursor = $cursor->addMinutes($granularity);
        }

        try {
            ResourceOccupancy::insert($rows);
        } catch (QueryException $e) {
            if ($this->isUniqueViolation($e)) {
                throw new SlotUnavailableException(
                    'Ese horario acaba de ser tomado. Elige otro.',
                    previous: $e,
                );
            }

            throw $e;
        }
    }

    /**
     * Una visita ocurre en un solo local.
     *
     * Encadenar manos en Chapinero con pies en Cedritos no es una cita larga,
     * son dos visitas -- nadie cruza la ciudad entre servicio y servicio. Y si
     * se dejara pasar, la cita quedaria contada en la caja de una sede con
     * trabajo hecho en la otra, que es exactamente lo que la sede viene a
     * ordenar.
     *
     * @param  list<array{resource:Resource}>  $resolved
     */
    private function assertSingleLocation(array $resolved): void
    {
        $sedes = array_unique(array_map(
            fn (array $row) => $row['resource']->location_id,
            $resolved,
        ));

        if (count($sedes) > 1) {
            throw new \DomainException(
                'Una misma cita no puede repartirse entre dos sedes. Agenda una cita por sede.'
            );
        }
    }

    /**
     * @param  list<array{service_id:int, resource_id:int, starts_at:string|CarbonImmutable}>  $items
     * @return list<array{service:Service, resource:Resource, starts_at:CarbonImmutable, ends_at:CarbonImmutable, service_starts_at:CarbonImmutable, service_ends_at:CarbonImmutable}>
     */
    private function resolveItems(Business $business, array $items, string $tz, bool $enforceSchedule = true): array
    {
        $resolved = [];

        foreach ($items as $raw) {
            $service = Service::where('business_id', $business->id)->findOrFail($raw['service_id']);
            $resource = Resource::where('business_id', $business->id)->findOrFail($raw['resource_id']);

            if (! $resource->is_active) {
                throw new \DomainException("El recurso {$resource->name} no esta activo.");
            }

            $start = $raw['starts_at'] instanceof CarbonImmutable
                ? $raw['starts_at']->setTimezone($tz)
                : CarbonImmutable::parse($raw['starts_at'], $tz);

            $window = $this->windowFor($service, $resource, $start);

            if ($enforceSchedule) {
                $this->assertWorkable($business, $resource, $window, $tz);
            }

            $resolved[] = array_merge(
                ['service' => $service, 'resource' => $resource],
                $window,
                // Lo de garantia viaja tal cual desde quien llama: son datos
                // del negocio, no del calculo de horarios.
                array_intersect_key($raw, array_flip([
                    'is_warranty', 'warranty_for_resource_id', 'warranty_for_item_id', 'warranty_note',
                ])),
            );
        }

        return $resolved;
    }

    /**
     * Rechaza agendar fuera de la jornada: antes de abrir, despues de cerrar,
     * en un dia que no trabaja, o encima de un almuerzo.
     *
     * La lista de huecos ya lo oculta, pero ocultar no es impedir: el
     * calendario manda una hora arbitraria al arrastrar, la reserva publica y
     * el agente de WhatsApp mandan la que les pidan, y hasta aca llegaba
     * cualquiera de las tres. Un almuerzo que se puede pisar mandando la hora a
     * mano no es un almuerzo.
     *
     * @param  array{starts_at:CarbonImmutable, ends_at:CarbonImmutable, service_starts_at:CarbonImmutable, service_ends_at:CarbonImmutable}  $window
     */
    private function assertWorkable(Business $business, Resource $resource, array $window, string $tz): void
    {
        // Se valida la ventana OCUPADA, buffers incluidos: si el servicio
        // termina a la una y su buffer de limpieza se mete en el almuerzo, la
        // profesional igual esta trabajando en su hora de comer.
        $occupied = new TimeWindow(
            $window['starts_at']->setTimezone($tz),
            $window['ends_at']->setTimezone($tz),
        );

        if ($this->availability->windowIsWorkable($business, $resource, $occupied, $tz)) {
            return;
        }

        throw new OutsideWorkingHoursException(
            "{$resource->name} no atiende en ese horario. Revisa su jornada y sus descansos."
        );
    }

    /**
     * Ventanas de un item, SIEMPRE en UTC.
     *
     * Laravel formatea un Carbon al bindearlo usando la zona que el propio
     * objeto lleva, sin convertirla. Si un negocio persistiera en su hora
     * local y otro en UTC, las comparaciones entre ambos serian silenciosamente
     * incorrectas -- y el sintoma (huecos ocupados que aparecen libres) no
     * apunta a la causa en absoluto. La regla es una sola: se persiste en UTC,
     * y la zona del negocio se usa solo para interpretar la entrada y para
     * presentar la salida.
     *
     * @return array{starts_at:CarbonImmutable, ends_at:CarbonImmutable, service_starts_at:CarbonImmutable, service_ends_at:CarbonImmutable}
     */
    private function windowFor(Service $service, Resource $resource, CarbonImmutable $serviceStart): array
    {
        $duration = $service->durationFor($resource);

        return [
            // La ventana ocupada incluye los buffers; la del servicio, no.
            'starts_at' => $serviceStart->subMinutes($service->buffer_before_min)->utc(),
            'ends_at' => $serviceStart->addMinutes($duration + $service->buffer_after_min)->utc(),
            'service_starts_at' => $serviceStart->utc(),
            'service_ends_at' => $serviceStart->addMinutes($duration)->utc(),
        ];
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        // 23000 en MySQL, 23505 en Postgres. Se cubren los dos por si el
        // motor cambia: el codigo de dominio no deberia enterarse.
        return in_array($e->getCode(), ['23000', '23505'], true);
    }
}
