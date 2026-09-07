<?php

namespace App\Services\Migration\Importadores;

use App\Models\Appointment;
use App\Models\AppointmentItem;
use App\Models\Client;
use App\Models\ResourceOccupancy;
use App\Models\Service;
use App\Services\Migration\LegacyMap;
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

    /**
     * Cuantos dias hacia atras se vuelve a mirar una atencion ya cobrada.
     *
     * Treinta: mas que suficiente para cualquier correccion que alguien haga
     * al darse cuenta, y poco suficiente para que la corrida de cada media
     * hora siga costando segundos.
     */
    private const DIAS_DE_GRACIA = 30;

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

        $this->correcciones();
        $this->desaparecidas();
    }

    /**
     * Cobros que el sistema viejo corrigio DESPUES de haberlos cerrado.
     *
     * El historial se trata como inmutable -- una atencion cobrada en marzo no
     * cambia -- y eso es cierto para el pasado lejano. Pero mientras los dos
     * sistemas convivan no lo es para lo reciente: alguien se equivoca al
     * cobrar, lo corrige media hora despues, y sin esto el sistema nuevo se
     * queda con la cifra mala para siempre.
     *
     * En la base real pasa poco -- una vez en sesenta dias -- pero cuando pasa
     * es plata, y la diferencia aparece en el reporte del mes sin que nadie
     * sepa de donde salio.
     *
     * Solo se miran las TOCADAS RECIENTEMENTE. Revisar las 3.324 en cada
     * corrida seria pagar todos los dias por un caso que ocurre una vez cada
     * dos meses.
     */
    private function correcciones(): void
    {
        $desde = now()->subDays(self::DIAS_DE_GRACIA)->toDateTimeString();

        $filas = $this->legacy('employee_services')
            ->where('status_id', self::FINALIZADO)
            ->whereNull('deleted_at')
            ->where('updated_at', '>=', $desde)
            ->orderBy('id')
            ->get();

        foreach ($filas as $fila) {
            $citaId = $this->map->idNuevo('appointment', $fila->id);

            if ($citaId === null) {
                continue;
            }

            $huella = LegacyMap::huella([
                (float) $fila->price,
                (float) $fila->final_price,
                (float) $fila->commission,
                $fila->payment_method_id,
            ]);

            if (! $this->map->cambio('appointment', (int) $fila->id, $huella)) {
                continue;
            }

            $cita = Appointment::withoutGlobalScope('business')->find($citaId);

            if ($cita === null) {
                continue;
            }

            $this->reescribirDinero($cita, $fila);

            $this->map->anotar('appointment', (int) $fila->id, $citaId, $huella);
            $this->reporte->actualizado('Historial');
            $this->reporte->aviso(
                'Historial',
                "La atencion {$fila->id} se corrigio en el sistema viejo: se actualizo el cobro aca.",
            );
        }
    }

    /**
     * Cobros que el sistema viejo ya no reconoce.
     *
     * Se borran atenciones ya cobradas -- cinco en todo el historico -- y
     * tambien se les puede quitar el estado de finalizada. Sin esto, esa plata
     * se queda sumando aca para siempre y el reporte de ventas dice mas de lo
     * que entro.
     *
     * Se CANCELA, no se borra: un hueco sin explicacion es peor que una cita
     * cancelada con su motivo escrito.
     */
    private function desaparecidas(): void
    {
        $vivas = $this->legacy('employee_services')
            ->where('status_id', self::FINALIZADO)
            ->whereNull('deleted_at')
            ->pluck('id')
            ->flip();

        $mias = DB::table('legacy_map as m')
            ->join('appointments as a', 'a.id', '=', 'm.new_id')
            ->where('m.business_id', $this->business->id)
            ->where('m.entity', 'appointment')
            ->where('a.status', 'completed')
            ->get(['m.legacy_id', 'a.id as cita_id']);

        foreach ($mias as $fila) {
            if (isset($vivas[(int) $fila->legacy_id])) {
                continue;
            }

            $cita = Appointment::withoutGlobalScope('business')->find($fila->cita_id);

            if ($cita === null) {
                continue;
            }

            $cita->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'cancellation_reason' => 'Anulada en el sistema anterior.',
            ]);

            $this->reporte->actualizado('Historial');
            $this->reporte->aviso(
                'Historial',
                "La atencion {$fila->legacy_id} ya no esta cobrada en el sistema viejo: se anulo aca.",
            );
        }
    }

    /** Deja el dinero de una cita igual al del sistema viejo. */
    private function reescribirDinero(Appointment $cita, object $fila): void
    {
        $lista = (float) $fila->price;
        $cobrado = (float) $fila->final_price;
        $descuento = max(0.0, round($lista - $cobrado, 2));
        $comision = (float) $fila->commission;

        $items = AppointmentItem::withoutGlobalScope('business')
            ->where('appointment_id', $cita->id)
            ->orderBy('sort_order')
            ->get();

        $servicios = $items->pluck('service_id')->map(fn ($id) => (int) $id)->all();

        $repartoLista = $this->repartir($lista, $servicios);
        $repartoCobrado = $this->repartir($cobrado, $servicios);
        $repartoComision = $this->repartir($comision, $servicios);

        DB::transaction(function () use (
            $cita, $fila, $items, $lista, $cobrado, $descuento, $comision,
            $repartoLista, $repartoCobrado, $repartoComision
        ) {
            $cita->update([
                'payment_method_id' => $this->map->idNuevo('payment_method', $fila->payment_method_id),
                'subtotal' => $lista,
                'discount_amount' => $descuento,
                'discount_reason' => $descuento > 0 ? $this->motivo($fila) : null,
                'total' => $cobrado,
                'commission_total' => $comision,
            ]);

            foreach ($items as $i => $item) {
                $item->update([
                    'price' => $repartoLista[$i] ?? $item->price,
                    'final_price' => $repartoCobrado[$i] ?? $item->final_price,
                    'commission_rate' => round(((float) $fila->commission_percentage) / 100, 4),
                    'commission_amount' => $repartoComision[$i] ?? 0,
                ]);
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
         * hace que la corrida de cada cinco minutos mire dos filas y no 3.324.
         *
         * SALVO una: la cita que se agendo a futuro y que acaban de cobrar
         * alla. Esa ya existe aca, pendiente, y hay que TERMINARLA en su
         * sitio. Crear una segunda seria contar la visita dos veces en el
         * reporte de ventas y en la tarjeta de sellos de la clienta.
         */
        $yaCreada = $this->map->idNuevo('appointment', $legacyId);

        if ($yaCreada !== null) {
            $this->completarPendiente($yaCreada, $fila);

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

        /*
         * La huella cubre el DINERO, no la fila entera: es lo unico que puede
         * cambiar despues de cobrar y que importa. Cubrirlo todo haria que
         * cualquier toque irrelevante alla -- un `updated_at` movido por una
         * migracion suya -- disparara una reescritura aca.
         */
        $this->anotar('appointment', $legacyId, $id, LegacyMap::huella([
            $lista, $cobrado, $comision, $fila->payment_method_id,
        ]));

        $this->reporte->creado('Historial');
    }

    /**
     * Termina una cita que ya existia aca como pendiente.
     *
     * Es el puente entre los dos pasos: `ImportaCitasFuturas` la creo cuando
     * estaba agendada, y ahora que el sistema viejo la cobro hay que cerrarla
     * con lo que de verdad se pago.
     *
     * Si ya esta terminada no se toca: una atencion cobrada no cambia, y
     * reescribirla cada cinco minutos seria pelear con quien la haya
     * ajustado a mano aca.
     */
    private function completarPendiente(int $citaId, object $fila): void
    {
        $cita = Appointment::withoutGlobalScope('business')->find($citaId);

        if ($cita === null || $cita->status !== Appointment::STATUS_PENDING) {
            $this->reporte->saltado('Historial');

            return;
        }

        $lista = (float) $fila->price;
        $cobrado = (float) $fila->final_price;
        $descuento = max(0.0, round($lista - $cobrado, 2));
        $comision = (float) $fila->commission;

        $items = AppointmentItem::withoutGlobalScope('business')
            ->where('appointment_id', $citaId)
            ->orderBy('sort_order')
            ->get();

        $servicios = $items->pluck('service_id')->map(fn ($id) => (int) $id)->all();

        $repartoLista = $this->repartir($lista, $servicios);
        $repartoCobrado = $this->repartir($cobrado, $servicios);
        $repartoComision = $this->repartir($comision, $servicios);

        DB::transaction(function () use (
            $cita, $fila, $items, $lista, $cobrado, $descuento, $comision,
            $repartoLista, $repartoCobrado, $repartoComision
        ) {
            $cita->update([
                'status' => 'completed',
                'payment_method_id' => $this->map->idNuevo('payment_method', $fila->payment_method_id),
                'checked_out_at' => $this->utc($fila->finished_at) ?? $cita->ends_at,
                'subtotal' => $lista,
                'discount_amount' => $descuento,
                'discount_reason' => $descuento > 0 ? $this->motivo($fila) : null,
                'total' => $cobrado,
                'commission_total' => $comision,
            ]);

            foreach ($items as $i => $item) {
                $item->update([
                    'service_starts_at' => $this->utc($fila->started_at) ?? $item->starts_at,
                    'service_ends_at' => $this->utc($fila->finished_at) ?? $item->ends_at,
                    'price' => $repartoLista[$i] ?? $item->price,
                    'final_price' => $repartoCobrado[$i] ?? $item->final_price,
                    'commission_rate' => round(((float) $fila->commission_percentage) / 100, 4),
                    'commission_amount' => $repartoComision[$i] ?? 0,
                ]);
            }

            /*
             * La ocupacion se suelta: la cita ya ocurrio, y una cita del
             * pasado no tiene por que seguir bloqueando un horario.
             */
            ResourceOccupancy::withoutGlobalScope('business')
                ->whereIn('appointment_item_id', $items->pluck('id'))
                ->delete();
        });

        $this->reporte->actualizado('Historial');
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
