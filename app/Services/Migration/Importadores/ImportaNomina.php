<?php

namespace App\Services\Migration\Importadores;

use App\Models\AppointmentItem;
use App\Models\PayrollSettlement;
use App\Models\PayrollSettlementItem;
use App\Support\Payroll\PayrollMode;
use Illuminate\Support\Facades\DB;

/**
 * Los pagos que el negocio YA le hizo a su equipo.
 *
 * Corre DESPUES del historial, porque cada liquidacion tiene que decir que
 * servicios pago, y esos servicios son las citas migradas.
 *
 * SIN ESTE PASO LA PANTALLA DE NOMINA MIENTE, y de la peor forma: dice que
 * nunca se le ha pagado a nadie y que se deben decenas de millones. El
 * sistema nuevo arranca el periodo de cada persona donde termino su ultima
 * liquidacion, y sin liquidaciones arranca desde que existe la ficha -- o
 * sea, desde 2024.
 *
 * NO BASTA CON CREAR LA LIQUIDACION. Las comisiones que se pagan son las de
 * los servicios que todavia no entraron en ninguna (ver
 * `PayrollService::chargedItems`), asi que una liquidacion sin sus lineas
 * dejaria esos mismos servicios listos para cobrarse otra vez. Por eso cada
 * una se lleva las lineas de su ventana, y cada linea se asigna a UNA sola:
 * en el sistema viejo los periodos a veces se pisan, y sin ese cuidado un
 * servicio quedaria pagado dos veces.
 */
class ImportaNomina extends Importador
{
    public function nombre(): string
    {
        return 'Nomina';
    }

    public function correr(): void
    {
        /*
         * Por fecha de corte ascendente: las lineas se asignan a la PRIMERA
         * liquidacion que las cubre, y "primera" tiene que significar la mas
         * antigua para que el reparto sea el mismo corrida tras corrida.
         */
        $filas = $this->legacy('payrolls')
            ->whereNull('deleted_at')
            ->orderBy('period_finished')
            ->orderBy('id')
            ->get();

        foreach ($filas as $fila) {
            $this->una($fila);
        }
    }

    private function una(object $fila): void
    {
        $legacyId = (int) $fila->id;

        // Un pago hecho no cambia.
        if ($this->map->yaExiste('payroll', $legacyId)) {
            $this->reporte->saltado('Nomina');

            return;
        }

        $recurso = $this->map->idNuevo('resource', $fila->employee_id);

        if ($recurso === null) {
            $this->reporte->aviso(
                'Nomina',
                "El pago {$legacyId} es de una empleada que no se importo.",
            );

            return;
        }

        $desde = $this->fecha($fila->period_started);
        $hasta = $this->fecha($fila->period_finished);

        if ($desde === null || $hasta === null) {
            $this->reporte->aviso('Nomina', "El pago {$legacyId} no tiene periodo utilizable.");

            return;
        }

        if ($this->simular) {
            $this->reporte->creado('Nomina');

            return;
        }

        $id = DB::transaction(function () use ($fila, $recurso, $desde, $hasta) {
            $lineas = $this->lineasDe($recurso, $desde, $hasta);

            $liquidacion = PayrollSettlement::create([
                'business_id' => $this->business->id,
                'resource_id' => $recurso,
                'period_start' => $desde,
                'period_end' => $hasta,
                /*
                 * Todas a comision: es como trabaja este local, y es lo unico
                 * que el sistema viejo sabe registrar -- no tiene sueldo base.
                 */
                'mode' => PayrollMode::COMMISSION,
                'base_amount' => 0,
                'base_period' => null,
                'services_count' => $lineas->count(),
                'charged_total' => round((float) $lineas->sum(fn ($i) => (float) ($i->final_price ?? 0)), 2),
                'commission_total' => round((float) $fila->total_commission, 2),
                'base_total' => 0,
                'bonus_total' => 0,
                'deduction_total' => round((float) $fila->total_discounts, 2),
                /*
                 * Lo que de verdad se le entrego, tal cual lo guardo el
                 * sistema viejo. NO se recalcula: si alla se pago 500.000, la
                 * historia dice 500.000 aunque hoy la cuenta diera otra cosa.
                 */
                'net_total' => round((float) $fila->paid_value, 2),
                'paid_at' => $this->utc($fila->date.' 12:00:00') ?? $this->utc($fila->created_at),
                'notes' => 'Importado del sistema anterior.',
            ]);

            foreach ($lineas as $linea) {
                PayrollSettlementItem::create([
                    'business_id' => $this->business->id,
                    'settlement_id' => $liquidacion->id,
                    'appointment_item_id' => $linea->id,
                    'charged_at' => $linea->checked_out_at,
                    'service_name' => $linea->service_name ?? 'Servicio',
                    'client_name' => $linea->client_name,
                    'charged' => (float) ($linea->final_price ?? 0),
                    'commission_rate' => $linea->commission_rate === null
                        ? null
                        : (float) $linea->commission_rate,
                    'commission_amount' => (float) ($linea->commission_amount ?? 0),
                ]);
            }

            return $liquidacion->id;
        });

        $this->map->anotar('payroll', $legacyId, $id);
        $this->reporte->creado('Nomina');
    }

    /**
     * Los servicios que esta liquidacion pago.
     *
     * Los cobrados en su ventana que NO esten ya en otra liquidacion. Ese
     * filtro es lo que impide pagar dos veces cuando los periodos del sistema
     * viejo se pisan -- y se pisan.
     *
     * @return \Illuminate\Support\Collection<int, object>
     */
    private function lineasDe(int $recurso, string $desde, string $hasta): \Illuminate\Support\Collection
    {
        $tz = $this->business->businessTimezone();

        $inicio = \Carbon\CarbonImmutable::parse($desde, $tz)->startOfDay()->utc();
        $fin = \Carbon\CarbonImmutable::parse($hasta, $tz)->endOfDay()->utc();

        return AppointmentItem::withoutGlobalScope('business')
            ->join('appointments', 'appointments.id', '=', 'appointment_items.appointment_id')
            ->leftJoin('services', 'services.id', '=', 'appointment_items.service_id')
            ->where('appointment_items.business_id', $this->business->id)
            ->where('appointment_items.resource_id', $recurso)
            ->whereNotNull('appointments.checked_out_at')
            ->whereBetween('appointments.checked_out_at', [$inicio, $fin])
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))
                ->from('payroll_settlement_items as psi')
                ->whereColumn('psi.appointment_item_id', 'appointment_items.id'))
            ->get([
                'appointment_items.id',
                'appointment_items.final_price',
                'appointment_items.commission_rate',
                'appointment_items.commission_amount',
                'appointments.checked_out_at',
                'appointments.client_name',
                'services.name as service_name',
            ]);
    }

    private function fecha(mixed $valor): ?string
    {
        $texto = trim((string) $valor);

        return $texto === '' || str_starts_with($texto, '0000') ? null : substr($texto, 0, 10);
    }
}
