<?php

namespace App\Support\Scheduling;

/**
 * Que puede disparar una etapa al entrar.
 *
 * Lista cerrada. Un negocio elige de aca; no escribe codigo ni webhooks. Es la
 * diferencia entre una automatizacion que se puede explicar en una pantalla y
 * un motor de reglas que nadie vuelve a entender en seis meses.
 *
 * Cada accion declara si es CRITICA. Las que no lo son se ejecutan en el mejor
 * esfuerzo: si el WhatsApp no sale, la cita igual queda marcada como confirmada
 * -- negarse a mover una cita porque un mensaje fallo dejaria el mostrador
 * atascado por algo que no depende de nadie ahi. Las criticas mueven plata, y
 * ahi si: si fallan, la transicion entera se deshace.
 */
final class StageActionCatalog
{
    /** Mensaje al cliente por su canal (hoy WhatsApp). */
    public const NOTIFY_CLIENT = 'notify_client';

    /** Aviso a la profesional que atiende. */
    public const NOTIFY_STAFF = 'notify_staff';

    /** Cobrar lo que quede pendiente al entrar a la etapa. */
    public const MARK_PAID = 'mark_paid';

    /** Liberar el horario para que otro cliente lo pueda tomar. */
    public const RELEASE_SLOT = 'release_slot';

    /** Anotar la inasistencia en la ficha del cliente. */
    public const APPLY_NO_SHOW_PENALTY = 'apply_no_show_penalty';

    /** Mandarle al cliente el enlace de la encuesta. */
    public const SEND_SURVEY = 'send_survey';

    /*
     * NO estan en el catalogo, a proposito, hasta que exista con que
     * ejecutarlas: pedir anticipo (falta el enlace de pago de Wompi) y sumar
     * un sello (falta el modelo de fidelizacion). Ofrecer una casilla que el
     * negocio marca y que no hace nada es peor que no ofrecerla: la marca, se
     * confia, y se entera cuando el anticipo nunca llego.
     */

    /**
     * @var array<string, array{label:string, help:string, critical:bool, feature:?string, config:list<string>}>
     */
    private const ACTIONS = [
        self::NOTIFY_CLIENT => [
            'label' => 'Avisarle al cliente',
            'help' => 'Le llega un mensaje cuando la cita entra a esta etapa.',
            'critical' => false,
            'feature' => 'reminders',
            'config' => ['template'],
        ],
        self::NOTIFY_STAFF => [
            'label' => 'Avisarle a quien atiende',
            'help' => 'Le avisa a quien atiende que su cita cambió.',
            'critical' => false,
            'feature' => 'reminders',
            'config' => ['template'],
        ],
        self::MARK_PAID => [
            'label' => 'Cobrar lo pendiente',
            'help' => 'Mover una cita a esta etapa la deja cobrada. Para el negocio que cobra al terminar, sin pasar por caja.',
            'critical' => true,
            'feature' => null,
            'config' => ['payment_method_id'],
        ],
        self::RELEASE_SLOT => [
            'label' => 'Liberar el horario',
            'help' => 'Devuelve el espacio a la agenda para que otro cliente lo pueda tomar.',
            'critical' => true,
            'feature' => null,
            'config' => [],
        ],
        self::APPLY_NO_SHOW_PENALTY => [
            'label' => 'Anotar la inasistencia',
            'help' => 'Queda en la ficha del cliente. A las N veces, el negocio decide si le sigue agendando.',
            'critical' => false,
            'feature' => 'no_show_penalties',
            'config' => [],
        ],
        self::SEND_SURVEY => [
            'label' => 'Preguntarle cómo le fue',
            'help' => 'Le manda el enlace de la encuesta. Las notas y los comentarios quedan por '
                .'persona y se ven al liquidar. No se manda en garantías.',
            'critical' => false,
            'feature' => null,
            'config' => ['template'],
        ],
    ];

    /** @return list<string> */
    public static function names(): array
    {
        return array_keys(self::ACTIONS);
    }

    public static function isCritical(string $type): bool
    {
        return self::ACTIONS[$type]['critical'] ?? false;
    }

    public static function featureFor(string $type): ?string
    {
        return self::ACTIONS[$type]['feature'] ?? null;
    }

    public static function label(string $type): string
    {
        return self::ACTIONS[$type]['label'] ?? $type;
    }

    /** @return list<array<string, mixed>> */
    public static function all(): array
    {
        $result = [];

        foreach (self::ACTIONS as $type => $meta) {
            $result[] = ['type' => $type] + $meta;
        }

        return $result;
    }
}
