<?php

namespace App\Services\Migration\Importadores;

use App\Models\Expense;
use App\Models\ExpenseType;
use App\Services\Migration\LegacyMap;

/**
 * Los gastos, para que la utilidad mensual cuadre.
 *
 * Las VENTAS no se migran aparte: salen solas del historial de citas, con su
 * fecha de cobro y lo que de verdad se pago. Los gastos si hacen falta, o el
 * reporte mostraria todo lo que entro y nada de lo que salio.
 *
 * DOS TIPOS DE GASTO NO SE TRAEN, y no es un olvido:
 *
 *   - SALARIOS (57 millones historicos, el 72% del gasto de 2026). Esa plata
 *     ya viaja aca dentro de cada cita migrada, en `commission_total`. El
 *     sistema nuevo calcula la nomina a partir de las comisiones de las
 *     atenciones, asi que importar ademas el gasto contaria el mismo pago
 *     dos veces y la utilidad de cada mes saldria unos 18 millones por
 *     debajo de la real.
 *
 *   - DESCUENTOS DE NOMINA. En el legacy son dos cosas mezcladas bajo el
 *     mismo tipo: deducciones al equipo, y los descuentos de fidelizacion
 *     que el sistema viejo anotaba como gasto "Retencion cliente". Los
 *     primeros los maneja el modulo de nomina; los segundos ya estan
 *     restados en el `total` de cada cita. Traerlos volveria a restar plata
 *     que nadie volvio a gastar.
 *
 * Queda Fijos, Insumos y Varios: el gasto que de verdad sale del bolsillo y
 * que ningun otro modulo del sistema nuevo conoce.
 */
class ImportaGastos extends Importador
{
    /**
     * Tipos del legacy que el sistema nuevo ya representa por su cuenta.
     *
     * Por id y no por nombre: alguien puede renombrar "Salarios" a "Nomina"
     * en el legacy manana, y la regla tiene que seguir valiendo.
     *
     * @var list<int>
     */
    private const YA_CONTADOS = [
        3,  // Salarios          -> vive en commission_total de cada cita
        5,  // Descuentos nomina -> vive en discount_amount y en el modulo de nomina
    ];

    public function nombre(): string
    {
        return 'Gastos';
    }

    public function correr(): void
    {
        $tipos = $this->tipos();

        $this->legacy('expenses')
            ->whereNull('deleted_at')
            ->whereNotIn('type_id', self::YA_CONTADOS)
            ->orderBy('id')
            ->chunk(300, function ($filas) use ($tipos) {
                foreach ($filas as $fila) {
                    $this->uno($fila, $tipos);
                }
            });

        $omitidos = $this->legacy('expenses')
            ->whereNull('deleted_at')
            ->whereIn('type_id', self::YA_CONTADOS)
            ->count();

        if ($omitidos > 0) {
            $this->reporte->aviso(
                'Gastos',
                "{$omitidos} gastos de salarios y descuentos de nomina NO se trajeron: esa plata ya "
                .'esta en las comisiones y los descuentos de las citas migradas.',
            );
        }
    }

    /** @param array<int, int> $tipos */
    private function uno(object $fila, array $tipos): void
    {
        $legacyId = (int) $fila->id;

        // Un gasto de marzo no cambia. Si ya vino, no se vuelve a mirar.
        if ($this->map->yaExiste('expense', $legacyId)) {
            $this->reporte->saltado('Gastos');

            return;
        }

        $tipo = $tipos[(int) $fila->type_id] ?? null;

        if ($tipo === null) {
            $this->reporte->aviso('Gastos', "El gasto {$legacyId} tiene un tipo que no se importo.");

            return;
        }

        $id = $this->crear(fn () => Expense::create([
            'business_id' => $this->business->id,
            'expense_type_id' => $tipo,
            /*
             * `date` es una fecha sin hora: no se convierte de zona. Pasarla
             * por la conversion a UTC la correria un dia hacia atras en todo
             * lo anotado antes de las 5 de la manana.
             */
            'date' => $fila->date,
            'description' => $fila->description ?: 'Sin descripcion',
            'value' => (float) $fila->value,
            'scope' => 'operacional',
        ])->id);

        $this->anotar('expense', $legacyId, $id);
        $this->reporte->creado('Gastos');
    }

    /**
     * Los tipos de gasto, creados si faltan.
     *
     * @return array<int, int> id legacy => id nuevo
     */
    private function tipos(): array
    {
        $mapa = [];

        $filas = $this->legacy('expense_types')
            ->whereNull('deleted_at')
            ->whereNotIn('id', self::YA_CONTADOS)
            ->orderBy('id')
            ->get();

        foreach ($filas as $orden => $fila) {
            $legacyId = (int) $fila->id;

            $existente = $this->map->idNuevo('expense_type', $legacyId);

            if ($existente !== null) {
                $mapa[$legacyId] = $existente;

                continue;
            }

            $porNombre = $this->simular ? null : ExpenseType::withoutGlobalScope('business')
                ->where('business_id', $this->business->id)
                ->whereRaw('LOWER(name) = ?', [mb_strtolower(trim($fila->name))])
                ->value('id');

            $id = $porNombre !== null
                ? (int) $porNombre
                : $this->crear(fn () => ExpenseType::create([
                    'business_id' => $this->business->id,
                    'name' => $fila->name,
                    'is_active' => true,
                    'sort_order' => $orden,
                ])->id);

            $this->anotar('expense_type', $legacyId, $id, LegacyMap::huella([$fila->name]));

            if ($porNombre === null) {
                $this->reporte->creado('Tipos de gasto');
            } else {
                $this->reporte->saltado('Tipos de gasto');
            }

            $mapa[$legacyId] = $id;
        }

        return $mapa;
    }

    private function anotar(string $entidad, int $legacyId, int $nuevoId, ?string $huella = null): void
    {
        $this->simular
            ? $this->map->anotarEnMemoria($entidad, $legacyId, $nuevoId, $huella)
            : $this->map->anotar($entidad, $legacyId, $nuevoId, $huella);
    }
}
