<?php

namespace App\Jobs;

use App\Models\Appointment;
use App\Services\Messaging\AvisoCitaNueva;
use App\Services\Messaging\DailyDigestService;
use App\Services\Messaging\NexoluCommsMail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * El correo de «entró una cita», fuera de la reserva: hablar con Connect
 * dentro del request dejaría a la clienta mirando la pantalla de reservar.
 */
class SendNewBookingEmailJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120];

    public function __construct(
        public readonly int $appointmentId,
        public readonly ?string $por = null,
    ) {}

    public function handle(NexoluCommsMail $mail, DailyDigestService $digest): void
    {
        $cita = Appointment::withoutGlobalScopes()->with('business')->find($this->appointmentId);

        if ($cita === null || $cita->business === null) {
            return;
        }

        $destinatarios = $digest->recipients($cita->business);

        if ($destinatarios === []) {
            return;
        }

        [$asunto, $texto] = AvisoCitaNueva::componer($cita, $this->por);

        $mail->send($destinatarios, $asunto, $texto, $cita->business_id, 'cita_nueva:'.$cita->id);
    }
}
