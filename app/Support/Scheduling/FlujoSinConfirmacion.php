<?php

namespace App\Support\Scheduling;

use App\Models\Appointment;
use App\Models\AppointmentWorkflow;
use App\Models\AppointmentWorkflowStage;

/**
 * El flujo de un local que no confirma: se agenda y se atiende.
 *
 * Es el de Luxury Nails, dicho por su dueño:
 *
 *     Agendado -> En curso -> Completado
 *     Agendado -> Cancelado
 *     Agendado -> No asistió
 *
 * La diferencia con el estándar es que NO tiene "Confirmada". No es un olvido:
 * en ese local nadie llama a confirmar, así que una etapa que nunca se usa sólo
 * agrega un botón que confunde. Cuando se creó este flujo no había en toda la
 * base una sola cita en ese estado -- 3.330 completadas, 8 agendadas, 2
 * canceladas, ninguna confirmada.
 *
 * SIN ACCIONES AUTOMÁTICAS. Mientras comms no esté conectado, un aviso al
 * cancelar sólo dejaría un mensaje pendiente que nadie mandó. Se encienden
 * desde el panel el día que haya WhatsApp.
 *
 * No es `is_default`: los negocios nuevos siguen naciendo con el estándar. Éste
 * se elige a mano en el panel de plataforma.
 */
final class FlujoSinConfirmacion
{
    public const NAME = 'Spa sin confirmación';

    /**
     * @return list<array<string, mixed>>
     */
    public static function stages(): array
    {
        return [
            [
                'key' => 'agendada',
                'label' => 'Agendado',
                'color' => '#94a3b8',
                'maps_to_status' => Appointment::STATUS_PENDING,
                'is_initial' => true,
            ],
            [
                'key' => 'en_curso',
                'label' => 'En curso',
                'color' => '#0f766e',
                'maps_to_status' => Appointment::STATUS_IN_PROGRESS,
                'is_initial' => false,
            ],
            /*
             * "Completado" a secas, y no "Lista y cobrada" como en el flujo
             * estándar: esta etapa NO cobra. Ponerle un nombre que promete un
             * cobro que no ocurre es como se termina con un servicio atendido,
             * marcado como listo, y nunca cobrado.
             */
            [
                'key' => 'completada',
                'label' => 'Completado',
                'color' => '#059669',
                'maps_to_status' => Appointment::STATUS_COMPLETED,
                'is_initial' => false,
            ],
            [
                'key' => 'cancelada',
                'label' => 'Cancelado',
                'color' => '#b3261e',
                'maps_to_status' => Appointment::STATUS_CANCELLED,
                'is_initial' => false,
            ],
            [
                'key' => 'no_asistio',
                'label' => 'No asistió',
                'color' => '#a16207',
                'maps_to_status' => Appointment::STATUS_NO_SHOW,
                'is_initial' => false,
            ],
        ];
    }

    /**
     * Lo crea si no existe y deja sus etapas al día.
     *
     * Igual que el estándar, NO pisa las acciones de una etapa que ya existe:
     * correr esto otra vez no puede borrar lo que alguien ajustó desde el
     * panel.
     */
    public static function sync(): AppointmentWorkflow
    {
        $workflow = AppointmentWorkflow::firstOrCreate(
            ['name' => self::NAME],
            [
                'description' => 'Agendado, en curso, completado. Sin paso de confirmación.',
                'is_default' => false,
                'is_active' => true,
            ],
        );

        foreach (self::stages() as $order => $stage) {
            $existing = AppointmentWorkflowStage::firstOrNew([
                'workflow_id' => $workflow->id,
                'key' => $stage['key'],
            ]);

            $existing->fill([
                'label' => $stage['label'],
                'color' => $stage['color'],
                'sort_order' => $order,
                'maps_to_status' => $stage['maps_to_status'],
                'is_initial' => $stage['is_initial'],
            ]);

            if (! $existing->exists) {
                $existing->actions = [];
            }

            $existing->save();
        }

        return $workflow->fresh(['stages']);
    }
}
