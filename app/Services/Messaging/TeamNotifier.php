<?php

namespace App\Services\Messaging;

use App\Ai\HoraLegible;
use App\Models\Appointment;
use App\Models\Message;
use App\Models\Resource;
use App\Support\NombreDePila;
use Carbon\CarbonImmutable;

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

    private function avisar(Appointment $appointment, string $kind): int
    {
        $business = $appointment->business;

        if ($business === null || ! $business->schedulingSetting('notify_team_whatsapp')) {
            return 0;
        }

        $avisados = 0;

        foreach ($this->quienesAtienden($appointment) as $resource) {
            $phone = $resource->notificationPhone();

            if ($phone === null) {
                continue;
            }

            $datos = $this->datos($appointment, $resource);

            $mensaje = $this->dispatcher->queue(
                $business,
                $kind,
                $phone,
                $this->texto($kind, $datos),
                $appointment,
                null,
                $kind === Message::KIND_TEAM_BOOKED
                    ? MessageTemplate::equipoAgendada(...array_values($datos))
                    : MessageTemplate::equipoCancelada(...array_values($datos)),
            );

            if ($mensaje !== null) {
                $avisados++;
            }
        }

        return $avisados;
    }

    /**
     * Cada persona UNA vez, aunque atienda dos servicios de la misma cita.
     *
     * @return \Illuminate\Support\Collection<int, Resource>
     */
    private function quienesAtienden(Appointment $appointment): \Illuminate\Support\Collection
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
    private function datos(Appointment $appointment, Resource $resource): array
    {
        $business = $appointment->business;
        $tz = $business?->businessTimezone() ?? config('spa.defaults.timezone');
        $inicio = CarbonImmutable::parse($appointment->starts_at)->setTimezone($tz);

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
            'hora' => HoraLegible::de($appointment->starts_at, $tz),
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
