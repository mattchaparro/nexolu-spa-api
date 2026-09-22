<?php

namespace App\Services\Messaging;

use App\Models\Appointment;
use App\Models\Business;
use App\Models\Message;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * "Ya casi te toca retoque": el mensaje que trae de vuelta a la clienta.
 *
 * El semipermanente se ve crecido a las tres semanas y la clienta lo sabe,
 * pero agendar se le pasa. Es el mensaje que más citas devuelve de todos --
 * lo que Luxury hace hoy a mano en ManyChat -- y el que mejor justifica
 * tener la agenda: nadie más sabe QUÉ se hizo, CUÁNDO y CON QUIÉN.
 *
 * Las reglas, calcadas del recordatorio de cita porque nacen de lo mismo:
 *
 * 1. VENTANA, NO INSTANTE. Se busca a quien cumple los días de retoque HOY
 *    o los cumplió en los últimos días. Si el comando no corrió un día, la
 *    corrida siguiente los recupera en vez de perderlos para siempre.
 * 2. A QUIEN NO TIENE CITA. Quien ya volvió a agendar no necesita que le
 *    recuerden volver: recibirlo se lee como que el salón no sabe quién es.
 * 3. UNO POR CITA, garantizado por el índice único de `messages`
 *    (appointment_id, kind). Una restricción no se desincroniza.
 *
 * Sale como PLANTILLA: el retoque se recuerda semanas después de la última
 * conversación, y fuera de la ventana de 24h Meta descarta el texto libre.
 */
class RetouchReminderService
{
    /**
     * Cuántos días hacia atrás se rescata a quien no recibió el mensaje.
     *
     * Tres: si el cron estuvo caído un fin de semana, el lunes salen los de
     * viernes, sábado y domingo. Más atrás ya no es un recordatorio de
     * retoque, es un "hace mucho no vienes" -- otro mensaje, otra decisión.
     */
    private const DIAS_DE_GRACIA = 3;

    public function __construct(private readonly MessageDispatcher $dispatcher) {}

    /**
     * Deja listos los recordatorios de retoque de un negocio.
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
        $queued = 0;
        $skipped = 0;

        foreach ($this->due($business, $now) as $cita) {
            $servicio = $cita->items->first(fn ($i) => $i->service !== null)?->service;
            $phone = $cita->client_phone ?? $cita->client?->phone;

            $mensaje = $this->dispatcher->queue(
                $business,
                Message::KIND_RETOUCH,
                $phone,
                $this->texto($business, $cita, $servicio?->name ?? 'tu servicio'),
                $cita,
                $cita->client,
                MessageTemplate::retoque(
                    $this->nombreDe($cita),
                    $business->name,
                    $servicio?->name ?? 'tu servicio',
                ),
            );

            $mensaje === null ? $skipped++ : $queued++;
        }

        return ['queued' => $queued, 'skipped' => $skipped];
    }

    /**
     * A quién le toca retoque ahora.
     *
     * Su ÚLTIMA visita (no cualquiera: la última, para no escribirle por un
     * servicio que ya se rehizo), cumplida la frecuencia de ese servicio, y
     * sin cita próxima.
     *
     * @return Collection<int, Appointment>
     */
    public function due(Business $business, CarbonImmutable $now): Collection
    {
        $defecto = (int) config('spa.defaults.retouch_days', 20);
        $tz = $business->businessTimezone();

        // Nadie se retoca antes de una semana ni después de tres meses: ese
        // rango acota la búsqueda a algo que la base resuelve por índice.
        $desde = $now->subDays(120)->utc();

        $candidatas = Appointment::withoutGlobalScopes()
            ->where('business_id', $business->id)
            ->whereNotNull('client_id')
            ->where('starts_at', '<', $now->utc())
            ->where('starts_at', '>=', $desde)
            ->whereNotIn('status', [Appointment::STATUS_CANCELLED, Appointment::STATUS_NO_SHOW])
            /*
             * Quien se dio de baja no recibe NADA que el spa mande por su
             * cuenta. Es la misma llave que frena las difusiones: una sola,
             * para que darse de baja no haya que pedirlo una vez por cada
             * tipo de mensaje que se nos ocurra después.
             */
            ->whereHas('client', fn ($q) => $q->withoutGlobalScopes()->where('accepts_marketing', true))
            ->with(['items.service', 'items.resource', 'client'])
            ->orderByDesc('starts_at')
            ->get();

        $vistas = [];
        $conCita = $this->clientesConCitaProxima($business, $now);

        return $candidatas->filter(function (Appointment $cita) use (&$vistas, $conCita, $defecto, $now, $tz) {
            // Solo la última visita de cada clienta.
            if (isset($vistas[$cita->client_id])) {
                return false;
            }

            $vistas[$cita->client_id] = true;

            if (in_array($cita->client_id, $conCita, true)) {
                return false;
            }

            $servicio = $cita->items->first(fn ($i) => $i->service !== null)?->service;
            $dias = $servicio?->retouch_days ?? $defecto;

            // 0 = este servicio no se retoca (un retiro, una reparación).
            if ($servicio === null || (int) $dias <= 0) {
                return false;
            }

            $toca = CarbonImmutable::parse($cita->starts_at)->setTimezone($tz)->startOfDay()->addDays((int) $dias);
            $hoy = $now->startOfDay();

            return $toca->lte($hoy) && $toca->gt($hoy->subDays(self::DIAS_DE_GRACIA));
        })->values();
    }

    /** @return list<int> */
    private function clientesConCitaProxima(Business $business, CarbonImmutable $now): array
    {
        return Appointment::withoutGlobalScopes()
            ->where('business_id', $business->id)
            ->where('starts_at', '>=', $now->utc())
            ->whereIn('status', [Appointment::STATUS_PENDING, Appointment::STATUS_CONFIRMED])
            ->pluck('client_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->all();
    }

    /**
     * El texto para la bandeja y el modo manual (la plantilla es lo que sale).
     *
     * CORTO a propósito. El de ManyChat eran cinco párrafos -- "nos encanta
     * cuidar de ti", "no dejes pasar más tiempo sin consentirte" -- y lo que
     * la clienta necesita saber cabe en dos líneas: qué se hizo y que puede
     * agendar. Lo demás se lee como publicidad, y la publicidad se salta.
     */
    private function texto(Business $business, Appointment $cita, string $servicio): string
    {
        return sprintf(
            "*Se acerca tu retoque*\n\n¡Hola, %s! 👋 Tu última cita en %s fue de *%s* "
                .'y ya va siendo hora del retoque 💅',
            $this->nombreDe($cita),
            $business->name,
            $servicio,
        );
    }

    private function nombreDe(Appointment $cita): string
    {
        $nombre = trim((string) ($cita->client?->fullName() ?? $cita->client_name ?? ''));

        return $nombre !== '' ? explode(' ', $nombre)[0] : 'Hola';
    }
}
