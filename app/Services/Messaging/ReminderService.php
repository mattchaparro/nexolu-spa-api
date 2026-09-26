<?php

namespace App\Services\Messaging;

use App\Models\Appointment;
use App\Models\Business;
use App\Models\Message;
use App\Support\Scheduling\StageMessage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * El recordatorio de la cita.
 *
 * Es la funcion que mas se pide y la que mas se hace mal. AgendaPro dice bajar
 * las inasistencias un 70% con esto; lo que decide si sirve o no es CUANDO se
 * manda y A QUE citas.
 *
 * TRES REGLAS, y las tres nacen de casos reales:
 *
 * 1. VENTANA ABIERTA, NO INSTANTE EXACTO. Se buscan las citas que arrancan
 *    dentro de las proximas N horas, no las que arrancan exactamente en N. Si
 *    el comando no corrio -- el servidor estaba caido, alguien lo desactivo --
 *    la siguiente corrida las recupera. Preguntar por un instante exacto
 *    significa que un minuto de caida son las citas de ese minuto sin avisar,
 *    y nadie se entera nunca.
 *
 * 2. NO SE RECUERDA LO QUE SE AGENDO TARDE. Si alguien reservo hace dos horas
 *    para manana, no necesita que le recuerden algo que acaba de decidir. La
 *    regla es literal: si la cita se creo DESPUES del momento en que tocaba
 *    recordarla, no hay recordatorio. Sin esto, cada reserva de ultima hora
 *    dispara un mensaje redundante que lee como spam.
 *
 * 3. UNO POR CITA, garantizado por el indice unico de `messages`, no por una
 *    bandera. Es la leccion de `gamification:recalculate` en Blue Souls: los
 *    contadores se desincronizan y hay que escribir un comando para
 *    repararlos. Una restriccion no se desincroniza.
 */
class ReminderService
{
    public function __construct(private readonly MessageDispatcher $dispatcher) {}

    /**
     * Deja listos los recordatorios de un negocio.
     *
     * @return array{queued: int, skipped: int}
     */
    public function run(Business $business, ?CarbonImmutable $now = null): array
    {
        if (! $business->hasFeature('reminders')) {
            return ['queued' => 0, 'skipped' => 0];
        }

        $tz = $business->businessTimezone();
        $now ??= CarbonImmutable::now($tz);
        $hours = (int) $business->schedulingSetting('reminder_hours_before');
        $soon = (int) $business->schedulingSetting('reminder_soon_hours_before');

        $queued = 0;
        $skipped = 0;

        /*
         * Dos pasadas: el del día anterior y el de unas horas antes. El del
         * día anterior deja fuera las citas que ya están dentro de la ventana
         * corta: si la corrida se atrasó, le toca solo el de ahora y no los
         * dos juntos.
         */
        $pasadas = array_filter([
            [Message::KIND_REMINDER, $hours, $soon > 0 && $soon < $hours ? $soon : 0],
            [Message::KIND_REMINDER_SOON, $soon, 0],
        ], fn (array $p) => $p[1] > 0);

        foreach ($pasadas as [$kind, $horas, $noDentroDe]) {
            foreach ($this->due($business, $now, $horas, $kind, $noDentroDe) as $appointment) {
                $this->queueOne($business, $appointment, $kind, $tz) ? $queued++ : $skipped++;
            }
        }

        return ['queued' => $queued, 'skipped' => $skipped];
    }

    /** Encola UN recordatorio; false si no salió (sin teléfono o ya avisada). */
    private function queueOne(Business $business, Appointment $appointment, string $kind, string $tz): bool
    {
        $phone = $appointment->client_phone ?? $appointment->client?->phone;

        $message = $this->dispatcher->queue(
            $business,
            $kind,
            $phone,
            StageMessage::render($this->template($business), $appointment),
            $appointment,
            null,
            /*
             * Ademas del texto, la PLANTILLA. Un recordatorio lo inicia
             * el negocio horas despues de cualquier conversacion, asi que
             * fuera de la ventana de 24h Meta rechaza el texto libre. El
             * texto sigue existiendo para el modo manual y la bandeja.
             */
            MessageTemplate::recordatorio(
                $appointment->client?->fullName() ?? $appointment->client_name ?? 'Hola',
                $business->name,
                $appointment->starts_at?->setTimezone($tz)->locale('es')->isoFormat('dddd D [de] MMMM') ?? '',
                $appointment->starts_at?->setTimezone($tz)->format('g:i a') ?? '',
            ),
        );

        return $message !== null;
    }

    /**
     * La plantilla del negocio, o la de fabrica.
     *
     * Se resuelve ACA y no dejando que `StageMessage::render` caiga sola: su
     * respaldo es el de las acciones de etapa -- "tu cita se actualizo" -- que
     * en un recordatorio no dice nada y encima suena a que algo cambio.
     */
    private function template(Business $business): string
    {
        $propia = trim((string) ($business->schedulingSetting('reminder_template') ?? ''));

        return $propia !== '' ? $propia : StageMessage::defaultReminderTemplate();
    }

    /**
     * Las citas a las que les toca recordatorio ahora.
     *
     * @return Collection<int, Appointment>
     */
    public function due(
        Business $business,
        CarbonImmutable $now,
        int $hours,
        string $kind = Message::KIND_REMINDER,
        // Las que empiezan antes de esto le tocan al recordatorio corto.
        int $notWithinHours = 0,
    ): Collection {
        // El momento a partir del cual una cita entra en la ventana.
        $limite = $now->addHours($hours);

        return Appointment::withoutGlobalScopes()
            ->where('business_id', $business->id)
            /*
             * Entre AHORA y el limite. Lo que ya empezo no se recuerda -- el
             * cliente esta en la silla o no vino -- y lo que esta mas alla del
             * limite le toca en una corrida futura.
             */
            // Con ventana corta, su borde es de ella: a las 3 h en punto le
            // toca el corto, no los dos.
            ->where('starts_at', $notWithinHours > 0 ? '>' : '>=', $now->addHours($notWithinHours)->utc())
            ->where('starts_at', '<=', $limite->utc())
            ->whereIn('status', [
                Appointment::STATUS_PENDING,
                Appointment::STATUS_CONFIRMED,
            ])
            /*
             * Agendada ANTES del momento en que tocaba recordarla.
             *
             * `starts_at - hours` es ese momento. Una cita creada despues no
             * necesita recordatorio: la persona acaba de decidirlo. Sin esta
             * linea, cada reserva de ultima hora dispara un mensaje redundante
             * que lee como spam.
             */
            ->whereRaw('appointments.created_at <= DATE_SUB(appointments.starts_at, INTERVAL ? HOUR)', [$hours])
            /*
             * Y sin recordatorio previo. El indice unico ya lo impide, pero
             * filtrarlo aca evita intentar -- y por lo tanto atrapar -- una
             * excepcion por cada cita ya avisada en cada corrida.
             */
            ->whereDoesntHave('messages', fn ($q) => $q->where('kind', $kind))
            ->with(['items.service', 'items.resource', 'client', 'business'])
            ->orderBy('starts_at')
            ->get();
    }
}
