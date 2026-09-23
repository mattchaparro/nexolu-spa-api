<?php

namespace App\Support\Scheduling;

use App\Models\Appointment;
use App\Models\AppointmentWorkflow;
use App\Models\AppointmentWorkflowStage;

/**
 * El flujo con el que arranca un negocio nuevo.
 *
 * Es el de un spa de uñas real, no un ejemplo: agendada -> confirmada -> en la
 * silla -> lista y cobrada, con los dos desenlaces malos aparte. Un negocio que
 * no quiera automatizar nada lo usa igual, porque tambien es como se llaman las
 * cosas en el mostrador.
 *
 * Las acciones vienen APAGADAS salvo la de "cancelada", que solo avisa. Sembrar
 * un negocio con mensajes automaticos encendidos es mandarle WhatsApp a
 * clientes de alguien que nunca lo pidio.
 */
final class DefaultWorkflow
{
    public const NAME = 'Flujo estándar de spa';

    /**
     * Las etapas, y por qué NO está «En la silla».
     *
     * La tenía, y Alejandro la quitó con una frase que vale más que cualquier
     * diagrama: «la manicurista simplemente carga el servicio cuando lo
     * terminó; eso de en la silla y que se paró es mucha vaina». Un estado que
     * nadie mueve no informa nada y sí ensucia el tablero: o se marca tarde,
     * o se marca nunca, y en los dos casos miente.
     *
     * Quedan las cuatro que alguien de verdad mueve: agendada, confirmada
     * --que es lo que responde la clienta al recordatorio--, lista y cobrada,
     * cancelada, y la inasistencia.
     *
     * El estado núcleo `in_progress` sigue existiendo en la máquina de
     * estados: un negocio que sí lo use puede armarse su etapa. Lo que cambia
     * es lo que trae el flujo estándar.
     *
     * @return list<array<string, mixed>>
     */
    public static function stages(): array
    {
        return [
            [
                'key' => 'agendada',
                'label' => 'Agendada',
                'color' => '#94a3b8',
                'maps_to_status' => Appointment::STATUS_PENDING,
                'is_initial' => true,
                'actions' => [],
            ],
            [
                'key' => 'confirmada',
                'label' => 'Confirmada',
                'color' => '#4f46e5',
                'maps_to_status' => Appointment::STATUS_CONFIRMED,
                'is_initial' => false,
                // La mas util de todas, y la razon por la que existe este
                // modulo: confirmar deja de ser llamar una por una.
                'actions' => [[
                    'type' => StageActionCatalog::NOTIFY_CLIENT,
                    /*
                     * Sin texto propio a proposito: vacio significa "la
                     * confirmacion de siempre" (ver StageMessage::render), la
                     * misma que recibe quien agenda por el bot, con el precio,
                     * quien la atiende y el Instagram del negocio. El que
                     * quiera otra la escribe y la suya manda.
                     */
                    'config' => ['template' => ''],
                ]],
            ],
            [
                'key' => 'lista',
                'label' => 'Lista y cobrada',
                'color' => '#059669',
                'maps_to_status' => Appointment::STATUS_COMPLETED,
                'is_initial' => false,
                // Sin `mark_paid` por defecto: cobrar tiene que ser un acto
                // deliberado. El negocio que quiera cobrar al marcar "lista"
                // lo enciende sabiendo lo que hace.
                //
                // El aviso SÍ: es el "Gracias por tu visita" con el estado de
                // su tarjeta de sellos y la invitación a calificar -- el
                // mensaje que más hace volver, y el único momento en que la
                // clienta sabe cuántos sellos lleva. Texto vacío = el nuestro
                // (ver StageMessage::render).
                'actions' => [[
                    'type' => StageActionCatalog::NOTIFY_CLIENT,
                    'config' => ['template' => ''],
                ]],
            ],
            [
                'key' => 'cancelada',
                'label' => 'Cancelada',
                'color' => '#b3261e',
                'maps_to_status' => Appointment::STATUS_CANCELLED,
                'is_initial' => false,
                'actions' => [[
                    'type' => StageActionCatalog::NOTIFY_CLIENT,
                    'config' => [
                        'template' => 'Hola {cliente}, tu cita del {fecha} a las {hora} en {negocio} quedó cancelada. Escríbenos y la reagendamos.',
                    ],
                ]],
            ],
            [
                'key' => 'no_asistio',
                'label' => 'No asistió',
                'color' => '#a16207',
                'maps_to_status' => Appointment::STATUS_NO_SHOW,
                'is_initial' => false,
                // Se anota en la ficha, no se cobra nada: cobrar por no venir
                // es una decision comercial que se toma mirando a la persona.
                'actions' => [['type' => StageActionCatalog::APPLY_NO_SHOW_PENALTY, 'config' => []]],
            ],
        ];
    }

    /** Crea o actualiza el flujo por defecto. Idempotente. */
    public static function sync(): AppointmentWorkflow
    {
        $workflow = AppointmentWorkflow::firstOrCreate(
            ['name' => self::NAME],
            [
                'description' => 'Agendada, confirmada, en la silla, lista. Con los dos desenlaces malos aparte.',
                'is_default' => true,
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

            // Las acciones SOLO al crear. Correr el comando otra vez no puede
            // pisar las plantillas que alguien ya ajusto -- ese es el tipo de
            // sorpresa que hace que un negocio deje de confiar en la funcion.
            if (! $existing->exists) {
                $existing->actions = $stage['actions'];
            }

            $existing->save();
        }

        return $workflow->fresh('stages');
    }
}
