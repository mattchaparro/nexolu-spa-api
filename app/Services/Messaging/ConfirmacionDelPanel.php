<?php

namespace App\Services\Messaging;

use App\Ai\AiCaller;
use App\Ai\InfoPostCita;
use App\Models\Appointment;
use App\Models\Message;
use App\Support\Scheduling\ConfirmationMessage;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * La confirmación de una cita agendada desde el panel.
 *
 * Nunca existió. El bot manda la suya cuando la clienta agenda sola, pero
 * lo que se agendaba en el panel salía mudo: la confirmación colgaba de
 * pasar la cita a la etapa «Confirmada», y crear una cita no la mueve de
 * etapa -- la deja en la inicial SIN correr sus acciones. En el flujo sin
 * confirmación, que es el de Luxury, esa etapa ni existe. Alejandro agendó
 * un turno desde la aplicación nueva y a la clienta no le llegó nada.
 *
 * Es la MISMA confirmación que manda el bot (ConfirmationMessage): el
 * texto sale dentro de la ventana de 24 horas, la plantilla fuera, y cuál
 * de los dos lo decide MessageDispatcher, que es el único que sabe si la
 * ventana está abierta.
 */
final class ConfirmacionDelPanel
{
    public function __construct(private readonly MessageDispatcher $dispatcher) {}

    public function enviar(Appointment $appointment): ?Message
    {
        /*
         * Solo si el flujo del negocio NO tiene etapa de confirmación.
         *
         * En el flujo estándar agendar deja la cita tentativa y el salón la
         * confirma después; la confirmación sale al pasar por esa etapa. Si
         * además saliera al crear, esa clienta recibiría dos. Es el negocio
         * sin esa etapa --el de Luxury: agendado, completado, cancelado-- el
         * que se quedaba sin ninguna.
         */
        if ($this->confirmaEnOtraEtapa($appointment)) {
            return null;
        }

        $phone = $appointment->client_phone ?? $appointment->client?->phone;

        if (! $phone) {
            // Se agendó por teléfono o en el mostrador y nadie anotó el
            // número. No es una falla: no hay a quién escribirle.
            return null;
        }

        try {
            /*
             * El Instagram como BOTÓN «Seguir en Instagram», igual que en la
             * confirmación del bot. Pegado al texto era una URL larga que
             * nadie tocaba (Alejandro lo vio en la reserva web de Aleja).
             */
            $instagram = ConfirmationMessage::instagram($appointment);

            $message = $this->dispatcher->queue(
                $appointment->business,
                Message::KIND_CONFIRMATION,
                $phone,
                ConfirmationMessage::text($appointment, conInstagram: $instagram === null),
                $appointment,
                $appointment->client,
                ConfirmationMessage::template($appointment),
                link: $instagram === null ? null : ['url' => $instagram, 'title' => 'Seguir en Instagram'],
            );
        } catch (Throwable $e) {
            /*
             * La cita YA quedó agendada. Que el aviso falle no puede
             * convertir eso en un error para quien está en el panel: vería
             * «no se pudo agendar», lo intentaría otra vez y la clienta
             * quedaría con dos citas.
             */
            Log::warning('panel.confirmacion.fallo', [
                'appointment_id' => $appointment->id,
                'error' => $e->getMessage(),
            ]);
            report($e);

            return null;
        }

        if ($message?->status === Message::STATUS_SENT) {
            $this->ofrecerInfo($appointment, $phone);
        }

        return $message;
    }

    /**
     * Garantías, recomendaciones y cancelaciones, detrás de la confirmación.
     *
     * Lo mismo que recibe quien agenda con el bot, y lo que ya conocía de
     * ManyChat. Solo con la ventana abierta: fuera de ella la confirmación
     * sale como plantilla y esos botones ya viajan adentro, así que un
     * segundo mensaje sobraría (y Meta no lo entregaría).
     *
     * Mejor esfuerzo y en silencio: la cita ya quedó y ella ya lo sabe.
     */
    private function ofrecerInfo(Appointment $appointment, string $phone): void
    {
        $business = $appointment->business;

        if (! $this->dispatcher->windowIsOpenFor($business, $phone)) {
            return;
        }

        try {
            app(InfoPostCita::class)->ofrecer(
                AiCaller::customer($business, $phone, $appointment->client, 'whatsapp'),
                $phone,
            );
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function confirmaEnOtraEtapa(Appointment $appointment): bool
    {
        $flujo = $appointment->business?->appointmentWorkflow;

        return $flujo !== null
            && $flujo->stages()->where('maps_to_status', Appointment::STATUS_CONFIRMED)->exists();
    }
}
