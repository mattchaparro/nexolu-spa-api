<?php

namespace App\Services\Migration\Importadores;

use App\Models\Appointment;
use App\Models\AppointmentItem;
use App\Models\Client;
use App\Models\Service;
use App\Support\ChannelPhone;
use App\Support\Money\Reparto;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * El historial: cada atencion cobrada del sistema viejo, como una cita
 * terminada aca.
 *
 * Es el paso que hace que la ficha de una clienta diga "12 visitas, ha
 * gastado 540.000, la ultima fue en julio" en vez de nacer vacia. Y de
 * regalo, reconstruye la contabilidad: al traer la fecha de cobro y lo que
 * de verdad se pago, el reporte de Ventas del sistema nuevo arma los meses
 * solo, desde julio de 2024.
 *
 * TRES COSAS QUE HAY QUE SABER ANTES DE LEER EL CODIGO:
 *
 * 1. EL 64% DE LAS ATENCIONES NO DICE A QUIEN SE ATENDIO. De 3.324 cobradas,
 *    2.118 no tienen `client_id`, y 2.064 de esas tampoco tienen telefono ni
 *    nombre. No es un error de la migracion: es como quedo registrado. El
 *    propio sistema viejo lo sabe -- su contador de sellos suma 1.218, casi
 *    exactamente las 1.206 atenciones que si tienen clienta.
 *
 *    Por eso esas atenciones se traen SIN clienta: la plata entra al reporte
 *    de ventas (que es real y hay que conservar) y no inventan visitas en la
 *    ficha de nadie.
 *
 * 2. LOS COMBOS SE PARTEN. En el legacy "Tradi Manos + Pies" es un servicio
 *    con un precio; aca es un paquete de dos servicios que ya existen. Una
 *    atencion de combo genera entonces DOS lineas, y la plata se reparte
 *    entre ellas a prorrata del precio de lista de cada parte. Los totales de
 *    la cita no cambian ni un peso -- la ultima linea absorbe el redondeo.
 *
 * 3. NO se crea `resource_occupancy`. La ocupacion existe para impedir
 *    solapes al agendar, y estas citas ya ocurrieron. Escribirlas ademas
 *    duplicaria filas de una tabla que solo sirve para el futuro.
 */
class ImportaHistorial extends Importador
{
    /** FINALIZADO en `service_statuses` del legacy. */
    private const FINALIZADO = 2;

    /** @var array<int, int> id de servicio nuevo => duracion en minutos */
    private array $duraciones = [];

    /** @var array<int, float> id de servicio nuevo => precio de lista */
    private array $precios = [];

    public function nombre(): string
    {
        return 'Historial';
    }

    public function correr(): void
    {
        $this->cargarServicios();
        $telefonos = $this->telefonosDeClientas();

        $this->legacy('employee_services')
            ->where('status_id', self::FINALIZADO)
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->chunk(400, function ($filas) use ($telefonos) {
                foreach ($filas as $fila) {
                    $this->una($fila, $telefonos);
                }
            });
    }

    /** @param array<string, int> $telefonos */
    private function una(object $fila, array $telefonos): void
    {
        $legacyId = (int) $fila->id;

        /*
         * El historial es inmutable: una atencion cobrada hace ocho meses no
         * cambia. Si ya se importo, no se vuelve a mirar -- y eso es lo que
         * hace que la corrida diaria mire 12 filas y no 3.324.
         */
        if ($this->map->yaExiste('appointment', $legacyId)) {
            $this->reporte->saltado('Historial');

            return;
        }

        [$servicios, $paquete] = $this->serviciosDe($fila);

        if ($servicios === []) {
            return;
        }

        $recurso = $this->map->idNuevo('resource', $fila->employee_id);

        if ($recurso === null) {
            $this->reporte->aviso(
                'Historial',
                "La atencion {$legacyId} la presto una empleada que no se importo.",
            );

            return;
        }

        $inicio = $this->inicio($fila);

        if ($inicio === null) {
            $this->reporte->aviso('Historial', "La atencion {$legacyId} no tiene fecha utilizable.");

            return;
        }

        [$clienteId, $nombre, $telefono] = $this->quien($fila, $telefonos);

        $lista = (float) $fila->price;
        $cobrado = (float) $fila->final_price;
        $descuento = max(0.0, round($lista - $cobrado, 2));
        $comision = (float) $fila->commission;

        // La plata repartida entre las partes, sin perder ni ganar un peso.
        $repartoLista = $this->repartir($lista, $servicios);
        $repartoCobrado = $this->repartir($cobrado, $servicios);
        $repartoComision = $this->repartir($comision, $servicios);

        $duracion = array_sum(array_map(fn (int $s) => $this->duraciones[$s] ?? 60, $servicios));
        $fin = $inicio->addMinutes(max(5, $duracion));

        /*
         * La cita y sus lineas, o ninguna de las dos.
         *
         * Sin esto, un fallo al insertar una linea deja una cita sin lineas
         * -- y una cita huerfana no es inofensiva: su `total` sigue sumando
         * en el reporte de ventas, asi que el mes queda inflado por una
         * atencion que ademas se vuelve a importar en la corrida siguiente.
         * Paso de verdad la primera vez que esto corrio contra datos reales.
         *
         * La transaccion es por CITA, no por corrida: 3.324 inserciones en
         * una sola transaccion tendrian la tabla bloqueada varios minutos.
         */
        $id = $this->crear(fn () => DB::transaction(function () use (
            $fila, $inicio, $fin, $clienteId, $nombre, $telefono, $servicios, $paquete,
            $recurso, $lista, $cobrado, $descuento, $comision,
            $repartoLista, $repartoCobrado, $repartoComision
        ) {
            $cita = Appointment::create([
                'business_id' => $this->business->id,
                'client_id' => $clienteId,
                'client_name' => $clienteId === null ? $nombre : null,
                'client_phone' => $clienteId === null ? $telefono : null,
                'service_package_id' => $paquete,
                'starts_at' => $inicio,
                'ends_at' => $fin,
                'status' => 'completed',
                'source' => 'admin',
                'payment_method_id' => $this->map->idNuevo('payment_method', $fila->payment_method_id),
                'checked_out_at' => $this->utc($fila->finished_at) ?? $fin,
                'subtotal' => $lista,
                'discount_amount' => $descuento,
                'discount_reason' => $descuento > 0 ? $this->motivo($fila) : null,
                'total' => $cobrado,
                'commission_total' => $comision,
            ]);

            $desde = $inicio;

            foreach ($servicios as $i => $servicioId) {
                $hasta = $desde->addMinutes(max(5, $this->duraciones[$servicioId] ?? 60));

                AppointmentItem::create([
                    'business_id' => $this->business->id,
                    'appointment_id' => $cita->id,
                    'service_id' => $servicioId,
                    'resource_id' => $recurso,
                    'starts_at' => $desde,
                    'ends_at' => $hasta,
                    /*
                     * Lo que de verdad duro, que casi nunca coincide con lo
                     * agendado. Es el dato con el que se corrigen las
                     * duraciones. En un combo, las dos lineas comparten el
                     * mismo tramo real: el legacy solo cronometro el conjunto.
                     *
                     * Con respaldo en lo agendado porque la columna no admite
                     * nulo y hay una atencion, de 3.324, sin hora de inicio.
                     * Perder esa cita entera por un campo vacio no compensa.
                     */
                    'service_starts_at' => $this->utc($fila->started_at) ?? $desde,
                    'service_ends_at' => $this->utc($fila->finished_at) ?? $hasta,
                    'price' => $repartoLista[$i],
                    'final_price' => $repartoCobrado[$i],
                    'commission_rate' => round(((float) $fila->commission_percentage) / 100, 4),
                    'commission_amount' => $repartoComision[$i],
                    'sort_order' => $i,
                ]);

                $desde = $hasta;
            }

            return $cita->id;
        }));

        $this->anotar('appointment', $legacyId, $id);
        $this->reporte->creado('Historial');
    }

    /**
     * Que servicios se prestaron, y si fue un combo.
     *
     * @return array{0: list<int>, 1: ?int}
     */
    private function serviciosDe(object $fila): array
    {
        $legacyServicio = (int) $fila->service_id;

        if (! isset(ImportaCatalogo::COMBOS[$legacyServicio])) {
            $id = $this->map->idNuevo('service', $legacyServicio);

            if ($id === null) {
                $this->reporte->aviso(
                    'Historial',
                    "La atencion {$fila->id} apunta al servicio {$legacyServicio}, que no se importo.",
                );

                return [[], null];
            }

            return [[$id], null];
        }

        $partes = [];

        foreach (ImportaCatalogo::COMBOS[$legacyServicio] as $parteLegacy) {
            $id = $this->map->idNuevo('service', $parteLegacy);

            if ($id === null) {
                $this->reporte->aviso(
                    'Historial',
                    "La atencion {$fila->id} es un combo cuyas partes no se importaron.",
                );

                return [[], null];
            }

            $partes[] = $id;
        }

        return [$partes, $this->map->idNuevo('service_package', $legacyServicio)];
    }

    /**
     * Reparte un monto entre servicios, a prorrata del precio de lista.
     *
     * @param  list<int>  $servicios
     * @return list<float>
     */
    private function repartir(float $monto, array $servicios): array
    {
        return Reparto::proporcional(
            $monto,
            array_map(fn (int $s) => $this->precios[$s] ?? 0.0, $servicios),
        );
    }

    /**
     * A quien se atendio.
     *
     * Tres caminos, en orden de confianza: la clienta enlazada, el telefono
     * suelto que coincide con una ficha, o nadie.
     *
     * El segundo camino recupera atenciones que el sistema viejo tiene
     * sueltas aunque el telefono este ahi y coincida con una clienta suya.
     * Se avisa cada una: suma un servicio a la cuenta de esa clienta, y eso
     * puede adelantarle un premio.
     *
     * @param  array<string, int>  $telefonos
     * @return array{0:?int, 1:?string, 2:?string}
     */
    private function quien(object $fila, array $telefonos): array
    {
        $nombre = trim((string) ($fila->client_name ?? '')) ?: null;
        $telefono = ChannelPhone::normalize((string) ($fila->client_cellphone ?? ''));

        $clienteId = $this->map->idNuevo('client', $fila->client_id);

        if ($clienteId !== null) {
            return [$clienteId, $nombre, $telefono];
        }

        if ($telefono !== null && isset($telefonos[$telefono])) {
            $this->reporte->aviso(
                'Historial',
                "La atencion {$fila->id} no tenia clienta enlazada, pero su telefono coincide "
                .'con una ficha: se le atribuyo.',
            );

            return [$telefonos[$telefono], $nombre, $telefono];
        }

        return [null, $nombre, $telefono];
    }

    /**
     * Cuando empezo, en UTC.
     *
     * Se prefiere la hora AGENDADA (el slot) sobre la hora en que la
     * manicurista abrio el registro: es la hora de la cita, y es la que la
     * clienta recuerda. `started_at` queda igual guardado en el item como lo
     * que de verdad paso.
     */
    private function inicio(object $fila): ?CarbonImmutable
    {
        if ($fila->appointment_id !== null) {
            $slot = $this->legacy('appointments')
                ->join('time_slots', 'time_slots.id', '=', 'appointments.time_slot_id')
                ->where('appointments.id', $fila->appointment_id)
                ->value('time_slots.start_time');

            if ($slot !== null) {
                $hora = $this->utcDe($fila->date, (string) $slot);

                if ($hora !== null) {
                    return $hora;
                }
            }
        }

        return $this->utc($fila->started_at) ?? $this->utcDe($fila->date, '09:00:00');
    }

    private function motivo(object $fila): string
    {
        if ($fila->loyalty_card_reward_id !== null) {
            return 'Premio de la tarjeta de sellos';
        }

        if ($fila->promotion_id !== null) {
            return 'Promocion';
        }

        $pct = (int) ($fila->discount_percentage ?? 0);

        return $pct > 0 ? "Descuento del {$pct}%" : 'Descuento';
    }

    /**
     * Duracion y precio de cada servicio nuevo, en memoria.
     *
     * Son 41 filas, y consultarlas por cada una de las 3.324 atenciones
     * serian miles de viajes a la base para leer siempre lo mismo.
     */
    private function cargarServicios(): void
    {
        $filas = Service::withoutGlobalScope('business')
            ->where('business_id', $this->business->id)
            ->get(['id', 'duration_min', 'price']);

        foreach ($filas as $fila) {
            $this->duraciones[(int) $fila->id] = (int) $fila->duration_min;
            $this->precios[(int) $fila->id] = (float) $fila->price;
        }
    }

    /**
     * Telefono normalizado => id de clienta, para rescatar las sueltas.
     *
     * @return array<string, int>
     */
    private function telefonosDeClientas(): array
    {
        if ($this->simular) {
            return [];
        }

        return Client::withoutGlobalScope('business')
            ->where('business_id', $this->business->id)
            ->whereNotNull('phone')
            ->pluck('id', 'phone')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    private function anotar(string $entidad, int $legacyId, int $nuevoId, ?string $huella = null): void
    {
        $this->simular
            ? $this->map->anotarEnMemoria($entidad, $legacyId, $nuevoId, $huella)
            : $this->map->anotar($entidad, $legacyId, $nuevoId, $huella);
    }
}
