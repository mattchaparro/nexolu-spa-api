<?php

namespace App\Support\Scheduling;

use App\Ai\HoraLegible;
use App\Models\Appointment;
use App\Services\Messaging\MessageTemplate;
use App\Support\PublicProfile;
use Carbon\CarbonImmutable;

/**
 * La confirmacion de una cita, escrita UNA sola vez.
 *
 * La misma clienta puede recibirla por dos caminos --el bot, cuando agenda
 * ella; el panel, cuando la agenda el salon-- y por dos vias --texto libre
 * dentro de las 24 horas, plantilla fuera de ellas--. Cuatro combinaciones,
 * y si cada una arma su propio texto terminan diciendo cosas distintas de la
 * misma cita. Aca esta el contenido; el canal solo decide la forma.
 *
 * El formato es el que Luxury ya usa en ManyChat y sus clientas reconocen:
 * dia, hora, servicio, precio y quien atiende, cada cosa en su renglon.
 */
final class ConfirmationMessage
{
    /**
     * El texto completo, para la ventana abierta y para la bandeja.
     *
     * Lleva lo que una plantilla aprobada NO puede llevar: los renglones que
     * sobran desaparecen --no hay "Precio: $0" ni "Te atiende: "-- y al final
     * va el Instagram del negocio, que es distinto en cada uno.
     */
    public static function text(Appointment $appointment, bool $conInstagram = true): string
    {
        $d = self::values($appointment);

        $lineas = [
            '¡Tu cita quedó confirmada! ✅',
            '',
            '📅 Día: *'.$d['fecha'].'*',
            '⏰ Hora: *'.$d['hora'].'*',
            '💅 Servicio: *'.$d['servicio'].'*',
        ];

        if ($d['precio'] !== '') {
            $lineas[] = '💵 Precio: *'.$d['precio'].'*';
        }

        if ($d['profesional'] !== '') {
            $lineas[] = '🙋‍♀️ Te atiende: *'.$d['profesional'].'*';
        }

        $lineas[] = '';
        $lineas[] = 'Gracias por agendar en *'.$d['negocio'].'* 🌟';

        $instagram = $conInstagram ? self::instagram($appointment) : null;

        if (! empty($instagram)) {
            $lineas[] = 'Síguenos y entérate de nuestras promociones 👉 '.$instagram;
        }

        return implode("\n", $lineas);
    }

    /**
     * El Instagram del negocio, si lo tiene.
     *
     * El bot lo manda como BOTON debajo de la confirmacion (ver
     * CreateAppointmentCapability): pegado como texto era una URL larga al
     * final del mensaje. Donde no se puede poner boton --la bandeja, lo que
     * se manda a mano-- va en el texto.
     */
    public static function instagram(Appointment $appointment): ?string
    {
        $url = $appointment->business !== null
            ? (PublicProfile::resolve($appointment->business)['instagram'] ?? null)
            : null;

        return empty($url) ? null : (string) $url;
    }

    /**
     * La misma confirmacion como plantilla aprobada, para fuera de las 24h.
     *
     * Dice lo mismo, pero FIJO: Meta aprueba el texto una vez y despues solo
     * se rellenan las variables. Por eso los huecos se rellenan en vez de
     * caerse --un parametro vacio hace que Meta rechace el envio entero-- y
     * por eso el Instagram no va: la plantilla es de la WABA de Nexolu y la
     * comparten todos los negocios, asi que no puede llevar el de uno.
     */
    public static function template(Appointment $appointment): MessageTemplate
    {
        $d = self::values($appointment);

        return MessageTemplate::confirmacion(
            $d['fecha'],
            $d['hora'],
            $d['servicio'] !== '' ? $d['servicio'] : 'tu servicio',
            $d['precio'] !== '' ? $d['precio'] : 'Te lo confirmamos en el salón',
            $d['profesional'] !== '' ? $d['profesional'] : 'nuestro equipo',
            $d['negocio'],
        );
    }

    /** @return array<string, string> */
    private static function values(Appointment $appointment): array
    {
        $valores = StageMessage::values($appointment);
        $tz = $appointment->business?->businessTimezone() ?? config('spa.defaults.timezone');
        $inicio = $appointment->starts_at
            ? CarbonImmutable::parse($appointment->starts_at)->setTimezone($tz)
            : null;

        return [
            // "Lunes 22 de septiembre", con mayuscula: es un renglon, no
            // parte de una frase.
            'fecha' => $inicio !== null
                ? ucfirst($inicio->locale('es')->isoFormat('dddd D [de] MMMM'))
                : '',
            // Siempre 12 horas con minutos: "3:00 pm", nunca "15:00".
            'hora' => $inicio !== null ? HoraLegible::de($appointment->starts_at, $tz) : '',
            'servicio' => $valores['servicio'],
            'precio' => $valores['precio'],
            'profesional' => $valores['profesional'],
            'negocio' => $valores['negocio'],
        ];
    }
}
