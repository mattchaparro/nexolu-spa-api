<?php

namespace App\Services\Messaging;

use App\Models\Appointment;
use App\Models\AppointmentItem;
use App\Models\Business;
use App\Models\ClientPhoto;
use App\Models\Message;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * "Terminaste. Registralo."
 *
 * EL PROBLEMA REAL: la cita empezo a las 2, el semipermanente dura noventa
 * minutos, y a las 3:30 la clienta se va. La manicurista sigue con la
 * siguiente y el servicio queda sin registrar hasta que alguien, en el cierre
 * del dia, intenta reconstruir de memoria quien atendio que. Eso no es un
 * olvido de una persona distraida: es lo que pasa cuando el sistema espera a
 * que alguien se acuerde.
 *
 * EL ANCLA ES `service_ends_at` DEL ULTIMO ITEM, y las dos mitades de esa
 * frase importan:
 *
 * - `service_ends_at` y no `ends_at`: el segundo incluye el buffer de
 *   limpieza, y avisar cuando termina el buffer es avisar cuando la
 *   profesional ya arranco con la siguiente clienta.
 *
 * - El ULTIMO item y no el primero -- al reves que NotifyStaffAction. Ahi el
 *   aviso es "tu cita cambio" y le toca a quien la abre; aca es "el trabajo
 *   quedo listo, fotografialo", y eso solo lo puede hacer quien todavia tiene
 *   a la clienta enfrente.
 *
 * VENTANA ABIERTA Y ACOTADA POR LOS DOS LADOS. Abierta hacia atras para que
 * una corrida perdida se recupere sola, como los recordatorios. Pero con
 * tope: un servicio que termino hace seis horas ya se cobro, o el dia se
 * acabo, y un aviso a las nueve de la noche sobre lo de las tres solo entrena
 * a ignorar los avisos.
 *
 * UNO POR CITA, garantizado por el indice unico de `messages` con su propio
 * `kind`. No hay bandera de "ya avise" que se pueda desincronizar.
 */
class ServiceDoneReminder
{
    public function __construct(private readonly MessageDispatcher $dispatcher) {}

    /**
     * @return array{queued: int, skipped: int}
     */
    public function run(Business $business, ?CarbonImmutable $now = null): array
    {
        $minutes = $business->serviceDoneReminderMinutes();

        // Apagado es el default: prender un aviso al equipo sin que nadie lo
        // pida es mandarle WhatsApp a las empleadas de un negocio a su nombre.
        if ($minutes <= 0 || ! $business->hasFeature('reminders')) {
            return ['queued' => 0, 'skipped' => 0];
        }

        $now ??= CarbonImmutable::now($business->businessTimezone());

        $queued = 0;
        $skipped = 0;

        foreach ($this->due($business, $now, $minutes) as $appointment) {
            $item = $this->lastItem($appointment);
            $phone = $item?->resource?->user?->phone;

            if ($phone === null) {
                /*
                 * Sin telefono no es un error: hay recursos que son una
                 * cabina, y profesionales cuyo usuario nunca lo registro. El
                 * pendiente igual les aparece en "Mi dia", que es la otra
                 * mitad de esta funcion.
                 */
                $skipped++;

                continue;
            }

            $message = $this->dispatcher->queue(
                $business,
                Message::KIND_SERVICE_DONE,
                $phone,
                $this->body($business, $appointment, $item),
                $appointment,
                null,
                MessageTemplate::servicioTerminado(
                    $item->resource?->name ?? '',
                    $item->service?->name ?? 'el servicio',
                    $this->hora($appointment, $item),
                    $this->pending($business, $item),
                ),
            );

            $message === null ? $skipped++ : $queued++;
        }

        return ['queued' => $queued, 'skipped' => $skipped];
    }

    /**
     * Las citas cuyo servicio ya termino y siguen sin registrar.
     *
     * @return Collection<int, Appointment>
     */
    public function due(Business $business, CarbonImmutable $now, int $minutes): Collection
    {
        $hasta = $now->subMinutes($minutes);
        $desde = $now->subMinutes($minutes + (int) $business->schedulingSetting('service_done_reminder_max_age_min'));

        return Appointment::withoutGlobalScopes()
            ->where('business_id', $business->id)
            /*
             * Cobrada es registrada: `checked_out_at` ES el estado de "ya
             * quedo en el sistema". Un campo aparte que dijera lo mismo se
             * desincronizaria el dia que alguien cobre por otro camino.
             */
            ->whereNull('checked_out_at')
            /*
             * `pending` entra a proposito, aunque nadie haya confirmado la
             * cita. Si la hora paso y sigue asi, o la clienta vino y hay que
             * registrarla, o no vino y hay que marcarlo -- las dos son cosas
             * que alguien tiene que hacer, y ninguna se hace sola.
             */
            ->whereIn('status', [
                Appointment::STATUS_PENDING,
                Appointment::STATUS_CONFIRMED,
                Appointment::STATUS_IN_PROGRESS,
                Appointment::STATUS_COMPLETED,
            ])
            /*
             * El maximo y no cualquier item: en una cita encadenada -- manos
             * con Maria, pies con Luisa -- avisar cuando termina el primero
             * seria interrumpir un trabajo que sigue en curso.
             */
            ->whereRaw(
                '(select max(ai.service_ends_at) from appointment_items ai'
                .' where ai.appointment_id = appointments.id) between ? and ?',
                [$desde->utc(), $hasta->utc()],
            )
            /*
             * Y sin aviso previo. El indice unico ya lo impide; filtrarlo aca
             * evita intentar -- y atrapar -- una excepcion por cada cita ya
             * avisada en cada corrida.
             */
            ->whereDoesntHave('messages', fn ($q) => $q->where('kind', Message::KIND_SERVICE_DONE))
            ->with(['items.service', 'items.resource.user', 'client', 'business'])
            ->get();
    }

    /** Quien todavia tiene a la clienta enfrente. */
    public function lastItem(Appointment $appointment): ?AppointmentItem
    {
        return $appointment->items->sortByDesc('service_ends_at')->first();
    }

    /**
     * Lo que falta, en palabras.
     *
     * Se calcula por servicio y no por negocio: un semipermanente transparente
     * no produce nada que valga la pena fotografiar, y pedir foto de todo
     * entrena a la gente a subir cualquier cosa para poder cobrar.
     */
    public function pending(Business $business, AppointmentItem $item): string
    {
        return $this->needsPhoto($business, $item)
            ? 'Regístralo y sube la foto del trabajo.'
            : 'Regístralo en el sistema.';
    }

    /** Si a este servicio le falta la foto y el negocio la pide. */
    public function needsPhoto(Business $business, AppointmentItem $item): bool
    {
        if (! $business->asksForServicePhoto() || ! ($item->service?->requires_photo ?? false)) {
            return false;
        }

        return ! ClientPhoto::withoutGlobalScopes()
            ->where('appointment_item_id', $item->id)
            ->exists();
    }

    /**
     * El texto que se lee en la bandeja de salida y que una persona copia en
     * modo manual. Convive con la plantilla por la misma razon que en los
     * recordatorios: de un texto ya armado no se sacan de vuelta las
     * variables sin adivinar.
     */
    private function body(Business $business, Appointment $appointment, AppointmentItem $item): string
    {
        return sprintf(
            'Hola %s, terminaste %s de %s a las %s. %s',
            $item->resource?->name ?? '',
            $item->service?->name ?? 'el servicio',
            $appointment->client_name ?? $appointment->client?->name ?? 'tu clienta',
            $this->hora($appointment, $item),
            $this->pending($business, $item),
        );
    }

    private function hora(Appointment $appointment, AppointmentItem $item): string
    {
        return $item->service_ends_at
            ?->setTimezone($appointment->business->businessTimezone())
            ->format('g:i a') ?? '';
    }
}
