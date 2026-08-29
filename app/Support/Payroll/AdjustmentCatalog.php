<?php

namespace App\Support\Payroll;

use App\Models\PayrollAdjustment;

/**
 * Por que se le descuenta o se le suma algo a una profesional.
 *
 * Lista cerrada y no texto libre, para que al final del mes se pueda responder
 * "cuanto llevamos en anticipos" sin leer descripciones una por una. Las
 * categorias salen de lo que de verdad pasa en el local: la que pide plata
 * antes de la quincena, la que se lleva insumos, la que rompio algo.
 */
final class AdjustmentCatalog
{
    /** @var array<string, array{kind: string, label: string, help: string}> */
    private const CATEGORIES = [
        'anticipo' => [
            'kind' => PayrollAdjustment::KIND_DEDUCTION,
            'label' => 'Anticipo',
            'help' => 'Plata que se le entregó antes de la liquidación.',
        ],
        'insumos' => [
            'kind' => PayrollAdjustment::KIND_DEDUCTION,
            'label' => 'Insumos',
            'help' => 'Material que se llevó o que el negocio le compró.',
        ],
        'dano' => [
            'kind' => PayrollAdjustment::KIND_DEDUCTION,
            'label' => 'Daño o pérdida',
            'help' => 'Equipo roto o extraviado que se acordó descontar.',
        ],
        'inasistencia' => [
            'kind' => PayrollAdjustment::KIND_DEDUCTION,
            'label' => 'Inasistencia',
            'help' => 'Día no trabajado que se descuenta de la base.',
        ],
        'otro_descuento' => [
            'kind' => PayrollAdjustment::KIND_DEDUCTION,
            'label' => 'Otro descuento',
            'help' => 'Cualquier otro acuerdo. Explica en la descripción.',
        ],
        'bono' => [
            'kind' => PayrollAdjustment::KIND_BONUS,
            'label' => 'Bono',
            'help' => 'Premio por metas, por cubrir turnos, o lo que se acuerde.',
        ],
        'propina' => [
            'kind' => PayrollAdjustment::KIND_BONUS,
            'label' => 'Propina',
            'help' => 'Propina que quedó en la caja y se le entrega con la liquidación.',
        ],
        'reintegro' => [
            'kind' => PayrollAdjustment::KIND_BONUS,
            'label' => 'Reintegro',
            'help' => 'Gasto que ella puso de su bolsillo y el negocio devuelve.',
        ],
    ];

    /** @return list<string> */
    public static function names(): array
    {
        return array_keys(self::CATEGORIES);
    }

    public static function kindOf(string $category): ?string
    {
        return self::CATEGORIES[$category]['kind'] ?? null;
    }

    public static function label(string $category): string
    {
        return self::CATEGORIES[$category]['label'] ?? $category;
    }

    /** @return list<array{name:string, kind:string, label:string, help:string}> */
    public static function all(): array
    {
        $result = [];

        foreach (self::CATEGORIES as $name => $meta) {
            $result[] = ['name' => $name] + $meta;
        }

        return $result;
    }
}
