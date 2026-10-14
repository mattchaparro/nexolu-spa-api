<?php

namespace App\Services\Messaging;

use App\Ai\HoraLegible;
use App\Models\Appointment;
use App\Models\Message;
use App\Support\Scheduling\ConfirmationMessage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Avisarle a la clienta que el salón le movió la cita.
 *
 * Mover una cita desde el panel --arrastrándola o con «Reagendar»-- avisaba al
 * equipo (TeamNotifier) y a la clienta no le llegaba nada: se enteraba al
 * llegar, o no se enteraba. Es el aviso que más importa de los dos.
 *
 * Solo cuando lo mueve el salón. Si la mueve ella --por el bot o por su
 * enlace-- ya lo sabe, y esos caminos no pasan por aquí.
 *
 * Dentro de las 24 h sale el texto con el antes y el ahora; fuera sale la
 * plantilla de confirmación (la única aprobada que dice «tu cita» con día y
 * hora), que lleva los datos NUEVOS. Cuál sale lo decide MessageDispatcher.
 *
 * NO cuelga de la cita, y es a propósito: una cita se puede mover varias veces
 * y el índice único de `messages` --uno por cita y tipo-- dejaría pasar solo el
 * primer aviso (mismo motivo que `equipo_movida`, ver TeamNotifier).
 */
final class RescheduleNotice
{
    public function __construct(private readonly MessageDispatcher $dispatcher) {}

    /**
     * Cómo está la cita ANTES de moverla: después del update los items ya
     * tienen la hora nueva y el «antes» sería mentira.
     *
     * @return array{starts_at: CarbonImmutable, people: list<int>, services: list<int>}
     */
    public static function snapshot(Appointment $appointment): array
    {
        $items = $appointment->loadMissing('items')->items;

        return [
            'starts_at' => CarbonImmutable::parse($appointment->starts_at),
            'people' => $items->pluck('resource_id')->filter()->unique()->sort()->values()->all(),
            'services' => $items->pluck('service_id')->filter()->unique()->sort()->values()->all(),
        ];
    }

    /**
     * @param  array{starts_at: CarbonImmutable, people: list<int>, services: list<int>}  $before
     */
    public function send(Appointment $appointment, array $before): ?Message
    {
        try {
            $appointment->unsetRelation('items');
            $appointment->loadMissing('business', 'client', 'items.service', 'items.resource');

            if (! $appointment->isActive()) {
                return null;
            }

            $now = self::snapshot($appointment);

            // «Reagendar» a la misma hora y con lo mismo no cambió nada.
            if ($now['starts_at']->equalTo($before['starts_at'])
                && $now['people'] === $before['people']
                && $now['services'] === $before['services']) {
                return null;
            }

            // Moverla a una hora que ya pasó es corregir un registro, no
            // avisarle a la clienta de una cita nueva.
            if ($now['starts_at']->isPast()) {
                return null;
            }

            $phone = $appointment->client_phone ?? $appointment->client?->phone;

            if (! $phone) {
                return null;
            }

            return $this->dispatcher->queue(
                $appointment->business,
                Message::KIND_RESCHEDULED,
                $phone,
                self::text($appointment, $before['starts_at']),
                null,
                $appointment->client,
                ConfirmationMessage::template($appointment),
            );
        } catch (Throwable $e) {
            // La cita YA se movió: que el aviso falle no puede devolver un
            // error a quien está en el panel (volvería a intentarlo).
            Log::warning('panel.reagendada.aviso_fallo', [
                'appointment_id' => $appointment->id,
                'error' => $e->getMessage(),
            ]);
            report($e);

            return null;
        }
    }

    public static function text(Appointment $appointment, CarbonImmutable $before): string
    {
        $d = ConfirmationMessage::values($appointment);
        $tz = $appointment->business?->businessTimezone() ?? config('spa.defaults.timezone');

        $lines = [
            'Tu cita se reagendó 🔁',
            '',
            '❌ Antes: '.self::moment($before, $tz),
            '✅ Ahora: *'.$d['fecha'].' a las '.$d['hora'].'*',
            '💅 Servicio: *'.$d['servicio'].'*',
        ];

        if ($d['profesional'] !== '') {
            $lines[] = '🙋‍♀️ Te atiende: *'.$d['profesional'].'*';
        }

        $lines[] = '';
        $lines[] = 'Si esa hora no te sirve, escríbenos por aquí y la acomodamos 😊';

        return implode("\n", $lines);
    }

    /** "Jueves 15 de octubre a las 3:00 pm" */
    private static function moment(CarbonImmutable $when, string $tz): string
    {
        $local = $when->setTimezone($tz);

        return ucfirst($local->locale('es')->isoFormat('dddd D [de] MMMM')).' a las '.HoraLegible::de($when, $tz);
    }
}
