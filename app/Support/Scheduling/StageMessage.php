<?php

namespace App\Support\Scheduling;

use App\Models\Appointment;
use App\Models\AppointmentWorkflowStage;
use App\Services\ClientPortalService;
use Carbon\CarbonImmutable;

/**
 * Arma el texto de un aviso a partir de la plantilla que escribio el negocio.
 *
 * Sustitucion simple con marcadores `{cliente}`, `{fecha}`, `{hora}`,
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

        // Solo el primer nombre: "Hola Maria Fernanda Restrepo" no lo escribe
        // nadie por WhatsApp.
        $nombre = trim(explode(' ', (string) $appointment->client_name)[0] ?? '');

        return [
            'cliente' => $nombre,
            // Alias del nombre anterior del marcador. Una plantilla escrita
            // con `{clienta}` sigue funcionando en vez de mandar el marcador
            // crudo por WhatsApp, que es lo que veria el cliente.
            'clienta' => $nombre,
            'fecha' => $start?->translatedFormat('l j \d\e F') ?? '',
            'hora' => $start?->format('g:i a') ?? '',
            'servicio' => $item?->service?->name ?? '',
            'profesional' => $item?->resource?->name ?? '',
            'negocio' => $business?->name ?? '',

            /*
             * El enlace de la encuesta. Vacio si la cita todavia no tiene
             * token: se prefiere una frase sin enlace a un `{encuesta}` crudo
             * viajando por WhatsApp.
             */
            'encuesta' => $appointment->survey_token
                ? rtrim((string) config('app.frontend_url', ''), '/')
                    .'/encuesta/'.$appointment->survey_token
                : '',

            /*
             * El enlace a "mis citas": ver, mover la hora y cancelar.
             *
             * Es la UNICA via por la que ese token llega a alguien, y por eso
             * este marcador existe. Sin el, el portal seria una pantalla a la
             * que nadie puede entrar.
             *
             * Solo para citas con ficha de cliente: una reserva a nombre
             * suelto no tiene a quien pertenecer, y generarle token a una
             * ficha inexistente no significa nada.
             */
            'mis_citas' => $appointment->client_id !== null && $business !== null
                ? rtrim((string) config('app.frontend_url', ''), '/')
                    .'/mis-citas/'.$business->slug
                    .'/'.app(ClientPortalService::class)->tokenFor($appointment->client)
                : '',
        ];
    }

    /**
     * Los que se le ofrecen a quien escribe la plantilla.
     *
     * `clienta` funciona pero no se ofrece: es el nombre viejo del marcador y
     * no hay razon para que alguien lo escriba a partir de ahora.
     *
     * @return list<string>
     */
    public static function placeholders(): array
    {
        return [
            'cliente', 'fecha', 'hora', 'servicio', 'profesional', 'negocio',
            'encuesta', 'mis_citas',
        ];
    }

    /**
     * Que preguntar cuando el negocio no escribio nada.
     *
     * Corto y con el enlace al final: un mensaje largo en WhatsApp se lee a
     * medias y el enlace queda debajo del "ver mas".
     */
    public static function defaultSurveyTemplate(): string
    {
        return '¡Gracias por venir, {cliente}! ¿Nos cuentas cómo te fue? Son 30 segundos: {encuesta}';
    }

    /**
     * El recordatorio de la cita.
     *
     * Lleva el enlace de "mis citas" a proposito, y ahi esta la diferencia
     * entre un recordatorio que sirve y uno que no: si la persona no va a
     * poder, tiene que poder MOVERLA en ese momento, no acordarse de llamar
     * mañana. Un recordatorio sin salida solo consigue que la inasistencia
     * llegue avisada.
     *
     * Sin token de cliente el marcador queda vacio -- una cita a nombre suelto
     * no tiene ficha a la que pertenecer -- y el mensaje se lee igual.
     */
    public static function defaultReminderTemplate(): string
    {
        return 'Hola {cliente}, te recordamos tu cita en {negocio} '
            .'el {fecha} a las {hora} con {profesional}. '
            .'Si necesitas cambiarla: {mis_citas}';
    }

    /**
     * Que decir cuando el negocio no escribio nada.
     *
     * Un mensaje generico y correcto es mejor que no mandar: el negocio marco
     * la casilla porque quiere que le avisen al cliente.
     */
    private static function fallback(?AppointmentWorkflowStage $stage): string
    {
        return match ($stage?->maps_to_status) {
            Appointment::STATUS_CONFIRMED => 'Hola {cliente}, tu cita en {negocio} quedó confirmada para el {fecha} a las {hora}. ¡Te esperamos!',
            Appointment::STATUS_CANCELLED => 'Hola {cliente}, tu cita del {fecha} a las {hora} en {negocio} quedó cancelada.',
            Appointment::STATUS_COMPLETED => 'Gracias por venir, {cliente}. ¡Te esperamos pronto en {negocio}!',
            default => 'Hola {cliente}, tu cita en {negocio} del {fecha} a las {hora} se actualizó.',
        };
    }
}
