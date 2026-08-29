<?php

namespace App\Support\Scheduling;

use App\Models\Appointment;

/**
 * Que transiciones de estado son legales para una cita.
 *
 * Sin base de datos y sin modelos: es una tabla y tres preguntas. Vive aparte
 * porque hoy las transiciones estan repartidas -- reservar pone `pending`,
 * cobrar pone `completed`, deshacer el cobro pone `confirmed`, cancelar pone
 * `cancelled` -- y nadie comprueba que el salto tenga sentido. Una cita
 * cancelada se puede marcar como completada, y eso ya es plata mal contada:
 * el cierre del dia la sumaria.
 *
 * IMPORTANTE: estos seis estados son el NUCLEO y no se configuran. La agenda
 * los usa para saber si el recurso sigue ocupado, la caja para saber que
 * cuenta, y la nomina para saber que se comisiona. Lo que cada negocio si
 * puede definir son sus ETAPAS -- como las llama, de que color, en que orden,
 * y que dispara cada una -- y cada etapa apunta a uno de estos seis. Dejar que
 * un negocio invente estados nucleo seria dejarlo romper el cuadre de caja
 * desde una pantalla de configuracion.
 */
final class AppointmentStateMachine
{
    /**
     * De donde se puede ir a donde.
     *
     * @return array<string, list<string>>
     */
    public static function transitions(): array
    {
        return [
            // Recien agendada. Puede confirmarse, arrancar directo (llego y
            // se atendio sin confirmar), cobrarse, cancelarse o no aparecer.
            Appointment::STATUS_PENDING => [
                Appointment::STATUS_CONFIRMED,
                Appointment::STATUS_IN_PROGRESS,
                Appointment::STATUS_COMPLETED,
                Appointment::STATUS_CANCELLED,
                Appointment::STATUS_NO_SHOW,
            ],

            Appointment::STATUS_CONFIRMED => [
                Appointment::STATUS_IN_PROGRESS,
                Appointment::STATUS_COMPLETED,
                Appointment::STATUS_CANCELLED,
                Appointment::STATUS_NO_SHOW,
            ],

            // Ya esta sentada en la silla. No puede volver a "sin confirmar"
            // ni marcarse como que no vino.
            Appointment::STATUS_IN_PROGRESS => [
                Appointment::STATUS_COMPLETED,
                Appointment::STATUS_CANCELLED,
            ],

            // Solo hacia atras, y solo a `confirmed`: es lo que hace deshacer
            // un cobro. Volver a `pending` perderia que la clienta si vino.
            Appointment::STATUS_COMPLETED => [
                Appointment::STATUS_CONFIRMED,
            ],

            /*
             * Cancelar libera la ocupacion del recurso, y para cuando alguien
             * quiera revertirlo el hueco puede estar tomado por otra clienta.
             * Reactivar en silencio pondria dos citas encima. Es terminal a
             * proposito: si se cancelo por error, se vuelve a agendar.
             */
            Appointment::STATUS_CANCELLED => [],

            // Marcar una inasistencia por error es comun -- llego tarde y
            // alguien ya la habia marcado. La ocupacion NUNCA se libero, asi
            // que el hueco sigue siendo suyo y volver es seguro.
            Appointment::STATUS_NO_SHOW => [
                Appointment::STATUS_CONFIRMED,
                Appointment::STATUS_IN_PROGRESS,
            ],
        ];
    }

    public static function canTransition(string $from, string $to): bool
    {
        // Quedarse donde uno esta siempre es legal: mover una cita a la etapa
        // en la que ya esta -- porque el negocio tiene dos etapas que apuntan
        // al mismo estado nucleo -- no es un error.
        if ($from === $to) {
            return true;
        }

        return in_array($to, self::transitions()[$from] ?? [], true);
    }

    /** @return list<string> */
    public static function allowedFrom(string $from): array
    {
        return self::transitions()[$from] ?? [];
    }

    public static function isTerminal(string $status): bool
    {
        return self::transitions()[$status] === [];
    }

    /**
     * Por que NO se puede, en palabras que sirvan en pantalla.
     *
     * Devuelve null cuando si se puede. Un mensaje generico ("transicion
     * invalida") obliga a quien lo lee a ir a buscar la tabla.
     */
    public static function reasonToRefuse(string $from, string $to): ?string
    {
        if (self::canTransition($from, $to)) {
            return null;
        }

        if ($from === Appointment::STATUS_CANCELLED) {
            return 'Esta cita está cancelada y su horario quedó libre. Si la clienta vuelve, agéndala de nuevo.';
        }

        if ($from === Appointment::STATUS_COMPLETED) {
            return 'Esta cita ya se cobró. Para corregirla, deshaz el cobro primero.';
        }

        if ($from === Appointment::STATUS_IN_PROGRESS && $to === Appointment::STATUS_NO_SHOW) {
            return 'No puedes marcar inasistencia: el servicio ya empezó.';
        }

        return 'Esa cita no puede pasar de "'.self::label($from).'" a "'.self::label($to).'".';
    }

    public static function label(string $status): string
    {
        return match ($status) {
            Appointment::STATUS_PENDING => 'Sin confirmar',
            Appointment::STATUS_CONFIRMED => 'Confirmada',
            Appointment::STATUS_IN_PROGRESS => 'En curso',
            Appointment::STATUS_COMPLETED => 'Completada',
            Appointment::STATUS_CANCELLED => 'Cancelada',
            Appointment::STATUS_NO_SHOW => 'No asistió',
            default => $status,
        };
    }
}
