<?php

namespace App\Support\Scheduling;

use App\Models\Appointment;
use App\Models\AppointmentWorkflowStage;
use Carbon\CarbonImmutable;

/**
 * Arma el texto de un aviso a partir de la plantilla que escribio el negocio.
 *
 * Sustitucion simple con marcadores `{clienta}`, `{fecha}`, `{hora}`,
 * `{servicio}`, `{profesional}`, `{negocio}`. No es un motor de plantillas a
 * proposito: quien lo escribe es el dueño de un spa desde un textarea, y un
 * lenguaje con condicionales y bucles ahi solo produce mensajes rotos que
 * nadie sabe depurar.
 *
 * Un marcador que no existe se deja tal cual, en vez de borrarse. Si alguien
 * escribio `{telefono}` esperando algo, ver `{telefono}` en el mensaje de
 * prueba se lo dice; ver un hueco, no.
 */
final class StageMessage
{
    public static function render(
        string $template,
        Appointment $appointment,
        ?AppointmentWorkflowStage $stage = null,
    ): string {
        $template = trim($template);

        if ($template === '') {
            $template = self::fallback($stage);
        }

        foreach (self::values($appointment) as $key => $value) {
            $template = str_replace('{'.$key.'}', $value, $template);
        }

        return $template;
    }

    /** @return array<string, string> */
    public static function values(Appointment $appointment): array
    {
        $business = $appointment->business;
        $tz = $business?->businessTimezone() ?? config('spa.defaults.timezone');
        $start = $appointment->starts_at
            ? CarbonImmutable::parse($appointment->starts_at)->setTimezone($tz)
            : null;

        $item = $appointment->items->first();

        return [
            // Solo el primer nombre: "Hola Maria Fernanda Restrepo" no lo
            // escribe nadie por WhatsApp.
            'clienta' => trim(explode(' ', (string) $appointment->client_name)[0] ?? ''),
            'fecha' => $start?->translatedFormat('l j \d\e F') ?? '',
            'hora' => $start?->format('g:i a') ?? '',
            'servicio' => $item?->service?->name ?? '',
            'profesional' => $item?->resource?->name ?? '',
            'negocio' => $business?->name ?? '',
        ];
    }

    /** @return list<string> */
    public static function placeholders(): array
    {
        return ['clienta', 'fecha', 'hora', 'servicio', 'profesional', 'negocio'];
    }

    /**
     * Que decir cuando el negocio no escribio nada.
     *
     * Un mensaje generico y correcto es mejor que no mandar: el negocio marco
     * la casilla porque quiere que le avisen a la clienta.
     */
    private static function fallback(?AppointmentWorkflowStage $stage): string
    {
        return match ($stage?->maps_to_status) {
            Appointment::STATUS_CONFIRMED => 'Hola {clienta}, tu cita en {negocio} quedó confirmada para el {fecha} a las {hora}. ¡Te esperamos!',
            Appointment::STATUS_CANCELLED => 'Hola {clienta}, tu cita del {fecha} a las {hora} en {negocio} quedó cancelada.',
            Appointment::STATUS_COMPLETED => 'Gracias por venir, {clienta}. ¡Te esperamos pronto en {negocio}!',
            default => 'Hola {clienta}, tu cita en {negocio} del {fecha} a las {hora} se actualizó.',
        };
    }
}
