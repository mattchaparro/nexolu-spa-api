<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una etapa: como el negocio llama a un estado, y que dispara al entrar.
 *
 * `maps_to_status` es la bisagra. El negocio elige el nombre y el color; el
 * nucleo -- ocupacion, caja, nomina -- sigue leyendo el estado.
 */
class AppointmentWorkflowStage extends Model
{
    protected $fillable = [
        'workflow_id', 'key', 'label', 'color', 'sort_order',
        'maps_to_status', 'is_initial', 'actions',
    ];

    protected function casts(): array
    {
        return ['is_initial' => 'boolean', 'actions' => 'array'];
    }

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(AppointmentWorkflow::class, 'workflow_id');
    }

    /** @return list<array{type:string, config:array<string,mixed>}> */
    public function actionList(): array
    {
        return array_map(
            fn (array $action) => [
                'type' => $action['type'],
                'config' => $action['config'] ?? [],
            ],
            array_filter($this->actions ?? [], fn ($a) => is_array($a) && isset($a['type'])),
        );
    }

    public function hasAction(string $type): bool
    {
        return collect($this->actionList())->contains('type', $type);
    }
}
