<?php

namespace App\Services\Messaging;

use App\Ai\HoraLegible;
use App\Models\Appointment;
use App\Models\Message;
use App\Models\Resource;
use App\Support\NombreDePila;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Avisarle por WhatsApp a quien atiende cuando le agendan o le cancelan.
 *
 * Hasta ahora el equipo se enteraba mirando la agenda, y eso funciona hasta
 * que no: quien trabaja por comision quiere saber que le agendaron sin tener
 * que abrir el panel, y una cancelacion que nadie vio es una hora que
 * alguien se queda esperando en el salon.
 *
 * TRES REGLAS:
 *
 * 1. SE ENCIENDE A PROPOSITO (`notify_team_whatsapp`, apagado por defecto).
 *    Prender esto solo, en el deploy, seria empezar a escribirle al equipo
 *    de cada negocio a nombre del salon sin que nadie lo pidiera.
 * 2. SIN NUMERO NO SE AVISA, y no es una falla: muchas manicuristas no
 *    tienen cuenta ni telefono cargado.
 * 3. UNO POR CITA, TIPO Y PERSONA, garantizado por el indice unico de
 *    `messages`. Una cita de manos y pies la atienden dos, y las dos se
 *    enteran; mover la cita de etapa dos veces no manda dos avisos.
 *
 * Como sale: PLANTILLA. Quien atiende recibe del numero del salon pero no le
 * escribe, asi que su ventana de 24h esta cerrada casi siempre.
 */
class TeamNotifier
{
    public function __construct(private readonly MessageDispatcher $dispatcher) {}

    /** Le agendaron. @return int cuantos avisos quedaron listos */
    public function booked(Appointment $appointment): int
    {
        return $this->avisar($appointment, Message::KIND_TEAM_BOOKED);
    }

    /** Le cancelaron: esa hora queda libre. @return int */
    public function cancelled(Appointment $appointment): int
    {
        return $this->avisar($appointment, Message::KIND_TEAM_CANCELLED);
    }

    /**
     * La movieron. Tres casos, y por eso no es un solo aviso:
     *
     * - La MISMA persona a otra hora: "te la movieron", con el antes y el
     *   después. Es el caso normal.
     * - Quien la PIERDE (la cita pasó a otra persona): esa hora le queda
     *   libre, que es exactamente lo que dice el aviso de cancelación.
     * - Quien la RECIBE: para ella es una cita nueva, y no tiene por qué
     *   enterarse de con quién estaba antes.
     *
     * @param  array{resources: list<int>, starts_at: CarbonImmutable}  $antes  cómo estaba ANTES de moverla
     * @return int cuántos avisos quedaron listos
     */
    public function rescheduled(Appointment $appointment, array $antes): int
    {
        $business = $appointment->business;

        if ($business === null || ! $business->schedulingSetting('notify_team_whatsapp')) {
            return 0;
        }

        $ahora = $this->quienesAtienden($appointment);
        $ahoraIds = $ahora->pluck('id')->all();
        $mismaHora = CarbonImmutable::parse($appointment->starts_at)->equalTo($antes['starts_at']);

        // No se movió nada: ni de hora ni de persona. Pasa cuando algo
        // "reagenda" a la misma hora -- avisar de eso es ruido puro.
        if ($mismaHora && array_diff($antes['resources'], $ahoraIds) === []
            && array_diff($ahoraIds, $antes['resources']) === []) {
            return 0;
        }

        $avisados = 0;

        foreach ($ahora as $resource) {
            $seQueda = in_array($resource->id, $antes['resources'], true);

            $avisados += $seQueda
                ? $this->mandarMudanza($appointment, $resource, $antes['starts_at'])
                : $this->mandarUno($appointment, $resource, Message::KIND_TEAM_BOOKED);
        }

        // Las que ya no la atienden: su hora vieja queda libre.
        foreach (array_diff($antes['resources'], $ahoraIds) as $id) {
            $resource = Resource::withoutGlobalScopes()->find($id);

            if ($resource !== null) {
                $avisados += $this->mandarUno(
                    $appointment,
                    $resource,
                    Message::KIND_TEAM_CANCELLED,
                    $antes['starts_at'],
                );
            }
        }

        return $avisados;
    }

    private function avisar(Appointment $appointment, string $kind): int
    {
        $business = $appointment->business;

        if ($business === null || ! $business->schedulingSetting('notify_team_whatsapp')) {
            return 0;
        }

        $avisados = 0;

        foreach ($this->quienesAtienden($appointment) as $resource) {
            $avisados += $this->mandarUno($appointment, $resource, $kind);
        }

        return $avisados;
    }

    /**
     * Un aviso de agendada o cancelada, a una persona.
     *
     * `$cuando` reemplaza la hora de la cita: al mover, quien la PIERDE tiene
     * que ver la hora que le queda libre -- la vieja --, no la nueva, que ya
     * no es suya.
     *
     * @return int 1 si quedó listo, 0 si no había a dónde o ya existía
     */
    private function mandarUno(
        Appointment $appointment,
        Resource $resource,
        string $kind,
        ?CarbonImmutable $cuando = null,
    ): int {
        $phone = $resource->notificationPhone();

        if ($phone === null) {
            return 0;
        }

        $datos = $this->datos($appointment, $resource, $cuando);

        $mensaje = $this->dispatcher->queue(
            $appointment->business,
            $kind,
            $phone,
            $this->texto($kind, $datos),
            $appointment,
            null,
            $kind === Message::KIND_TEAM_BOOKED
                ? MessageTemplate::equipoAgendada(...array_values($datos))
                : MessageTemplate::equipoCancelada(...array_values($datos)),
        );

        return $mensaje === null ? 0 : 1;
    }

    /**
     * «Te movieron la cita», con el antes y el después.
     *
     * NO se cuelga de la cita, y es a propósito: una cita se puede mover dos
     * veces, y el índice único de `messages` --uno por cita, tipo y
     * destinatario-- dejaría pasar solo el primer aviso. La segunda mudanza
     * se descartaría en silencio y ella se aparecería a la hora vieja.
     *
     * El evento acá es la MUDANZA, no la cita. El costo de no colgarlo es que
     * el mensaje no queda enlazado a la cita en la bandeja; entre eso y no
     * avisar, se prefiere avisar.
     */
    private function mandarMudanza(
        Appointment $appointment,
        Resource $resource,
        CarbonImmutable $antes,
    ): int {
        $phone = $resource->notificationPhone();

        if ($phone === null) {
            return 0;
        }

        $tz = $appointment->business?->businessTimezone() ?? config('spa.defaults.timezone');
        $datos = $this->datos($appointment, $resource);

        $variables = [
            'profesional' => $datos['profesional'],
            'cliente' => $datos['cliente'],
            'servicio' => $datos['servicio'],
            'antes' => $this->momento($antes, $tz),
            'ahora' => $this->momento(CarbonImmutable::parse($appointment->starts_at), $tz),
        ];

        $mensaje = $this->dispatcher->queue(
            $appointment->business,
            Message::KIND_TEAM_MOVED,
            $phone,
            sprintf(
                "Hola, %s: te movieron una cita.\n\n🙋‍♀️ Clienta: *%s*\n💅 Servicio: *%s*\n\n❌ Antes: %s\n✅ Ahora: *%s*",
                ...array_values($variables),
            ),
            // Sin cita: ver el comentario de arriba.
            null,
            $appointment->client,
            MessageTemplate::equipoMovida(...array_values($variables)),
        );

        return $mensaje === null ? 0 : 1;
    }

    /** "Jueves 17 de septiembre a las 3:00 pm" */
    private function momento(CarbonImmutable $cuando, string $tz): string
    {
        $local = $cuando->setTimezone($tz);

        return ucfirst($local->locale('es')->isoFormat('dddd D [de] MMMM'))
            .' a las '.HoraLegible::de($cuando, $tz);
    }

    /**
     * Cada persona UNA vez, aunque atienda dos servicios de la misma cita.
     *
     * @return Collection<int, resource>
     */
    private function quienesAtienden(Appointment $appointment): Collection
    {
        return $appointment->loadMissing('items.resource')->items
            ->map(fn ($item) => $item->resource)
            ->filter(fn (?Resource $r) => $r !== null && $r->is_active)
            ->unique('id')
            ->values();
    }

    /**
     * Las variables, en el ORDEN que espera la plantilla.
     *
     * @return array{profesional: string, cliente: string, servicio: string, fecha: string, hora: string}
     */
    private function datos(Appointment $appointment, Resource $resource, ?CarbonImmutable $cuando = null): array
    {
        $business = $appointment->business;
        $tz = $business?->businessTimezone() ?? config('spa.defaults.timezone');
        $inicio = ($cuando ?? CarbonImmutable::parse($appointment->starts_at))->setTimezone($tz);

        // Solo lo que ELLA atiende, no la cita entera: si hace las manos y
        // otra los pies, su aviso dice manos.
        $suyos = $appointment->items
            ->filter(fn ($item) => $item->resource_id === $resource->id)
            ->map(fn ($item) => $item->service?->name)
            ->filter()
            ->unique();

        return [
            'profesional' => NombreDePila::deSaludo($resource->name) ?? $resource->name,
            'cliente' => trim((string) ($appointment->client?->fullName() ?? $appointment->client_name ?? '')) ?: 'Una clienta',
            'servicio' => $suyos->isNotEmpty() ? $suyos->implode(' y ') : 'un servicio',
            'fecha' => ucfirst($inicio->locale('es')->isoFormat('dddd D [de] MMMM')),
            'hora' => HoraLegible::de($cuando ?? $appointment->starts_at, $tz),
        ];
    }

    /** @param array{profesional: string, cliente: string, servicio: string, fecha: string, hora: string} $d */
    private function texto(string $kind, array $d): string
    {
        if ($kind === Message::KIND_TEAM_BOOKED) {
            return sprintf(
                "¡Hola, %s! 💅 Te agendaron una cita.\n\n🙋‍♀️ Clienta: *%s*\n💅 Servicio: *%s*\n📅 %s\n⏰ %s",
                $d['profesional'], $d['cliente'], $d['servicio'], $d['fecha'], $d['hora'],
            );
        }

        return sprintf(
            "Hola, %s: se canceló una cita y esa hora te queda libre.\n\n🙋‍♀️ Clienta: *%s*\n💅 Servicio: *%s*\n📅 %s\n⏰ %s",
            $d['profesional'], $d['cliente'], $d['servicio'], $d['fecha'], $d['hora'],
        );
    }
}
