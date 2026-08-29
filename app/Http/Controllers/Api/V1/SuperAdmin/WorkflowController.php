<?php

namespace App\Http\Controllers\Api\V1\SuperAdmin;

use App\Models\Appointment;
use App\Models\AppointmentWorkflow;
use App\Models\AppointmentWorkflowStage;
use App\Support\Scheduling\AppointmentStateMachine;
use App\Support\Scheduling\StageActionCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Flujos de etapas, a nivel plataforma.
 *
 * Igual que los medios de pago: el catalogo lo mantiene Nexolu y cada negocio
 * elige. Dejar que cada negocio escriba sus propias etapas suena mas flexible,
 * pero cada etapa apunta a un estado nucleo del que dependen la agenda, la caja
 * y la nomina -- y un negocio que se equivoca ahi descuadra su propia plata sin
 * saber por que.
 */
class WorkflowController
{
    public function index(): JsonResponse
    {
        return response()->json([
            'workflows' => AppointmentWorkflow::with('stages')
                ->withCount('businesses')
                ->orderByDesc('is_default')
                ->orderBy('name')
                ->get()
                ->map(fn (AppointmentWorkflow $w) => $this->present($w)),

            // Lo que se puede elegir al armar una etapa.
            'statuses' => array_map(fn (string $s) => [
                'value' => $s,
                'label' => AppointmentStateMachine::label($s),
                'terminal' => AppointmentStateMachine::isTerminal($s),
            ], Appointment::statuses()),

            'actions' => StageActionCatalog::all(),
            'placeholders' => \App\Support\Scheduling\StageMessage::placeholders(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:255'],
        ]);

        $workflow = AppointmentWorkflow::create($data + ['is_default' => false, 'is_active' => true]);

        return response()->json($this->present($workflow->fresh('stages')), 201);
    }

    public function update(Request $request, AppointmentWorkflow $workflow): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
            'is_default' => ['sometimes', 'boolean'],
        ]);

        DB::transaction(function () use ($workflow, $data) {
            // Uno solo puede ser el de arranque: dos "por defecto" harian que
            // el negocio nuevo caiga en cualquiera de los dos.
            if (! empty($data['is_default'])) {
                AppointmentWorkflow::whereKeyNot($workflow->id)->update(['is_default' => false]);
            }

            $workflow->update($data);
        });

        return response()->json($this->present($workflow->fresh('stages')));
    }

    /** Reemplaza las etapas del flujo, en bloque. */
    public function saveStages(Request $request, AppointmentWorkflow $workflow): JsonResponse
    {
        $data = $request->validate([
            'stages' => ['present', 'array', 'min:1'],
            'stages.*.key' => ['nullable', 'string', 'max:40'],
            'stages.*.label' => ['required', 'string', 'max:60'],
            'stages.*.color' => ['nullable', 'string', 'max:9'],
            'stages.*.maps_to_status' => ['required', Rule::in(Appointment::statuses())],
            'stages.*.is_initial' => ['nullable', 'boolean'],
            'stages.*.actions' => ['nullable', 'array'],
            'stages.*.actions.*.type' => ['required', Rule::in(StageActionCatalog::names())],
            'stages.*.actions.*.config' => ['nullable', 'array'],
        ]);

        $iniciales = collect($data['stages'])->where('is_initial', true)->count();

        if ($iniciales !== 1) {
            // Sin etapa inicial una cita nueva no cae en ninguna; con dos, cae
            // en la que el orden decida y nadie sabe cual.
            return response()->json([
                'message' => 'Marca exactamente una etapa como la de inicio.',
            ], 422);
        }

        DB::transaction(function () use ($workflow, $data) {
            $conservadas = [];

            foreach ($data['stages'] as $order => $row) {
                $key = $row['key'] ?: Str::slug($row['label'], '_');

                $stage = AppointmentWorkflowStage::updateOrCreate(
                    ['workflow_id' => $workflow->id, 'key' => $key],
                    [
                        'label' => $row['label'],
                        'color' => $row['color'] ?? '#64748b',
                        'sort_order' => $order,
                        'maps_to_status' => $row['maps_to_status'],
                        'is_initial' => (bool) ($row['is_initial'] ?? false),
                        'actions' => array_values($row['actions'] ?? []),
                    ],
                );

                $conservadas[] = $stage->id;
            }

            /*
             * Las que ya no vienen se borran, y las citas que apuntaban a
             * ellas quedan con `stage_id` nulo (nullOnDelete) pero conservan
             * su estado nucleo. Es a proposito: una cita cobrada sigue
             * cobrada aunque el negocio renombre sus etapas.
             */
            AppointmentWorkflowStage::where('workflow_id', $workflow->id)
                ->whereNotIn('id', $conservadas)
                ->delete();
        });

        return response()->json($this->present($workflow->fresh('stages')));
    }

    /** @return array<string, mixed> */
    private function present(AppointmentWorkflow $workflow): array
    {
        return [
            'id' => $workflow->id,
            'name' => $workflow->name,
            'description' => $workflow->description,
            'is_default' => (bool) $workflow->is_default,
            'is_active' => (bool) $workflow->is_active,
            'businesses_count' => $workflow->businesses_count ?? $workflow->businesses()->count(),
            'stages' => $workflow->stages->map(fn (AppointmentWorkflowStage $s) => [
                'id' => $s->id,
                'key' => $s->key,
                'label' => $s->label,
                'color' => $s->color,
                'sort_order' => $s->sort_order,
                'maps_to_status' => $s->maps_to_status,
                'status_label' => AppointmentStateMachine::label($s->maps_to_status),
                'is_initial' => (bool) $s->is_initial,
                'actions' => $s->actionList(),
            ])->values(),
        ];
    }
}
