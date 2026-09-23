<?php

namespace App\Support\Scheduling;

use App\Models\Appointment;
use App\Services\Loyalty\LoyaltyService;
use App\Services\Messaging\MessageTemplate;
use Carbon\CarbonImmutable;

/**
 * "Gracias por tu visita": el mensaje de cuando la manicurista termina.
 *
 * Es el que Luxury manda hoy desde ManyChat y sus clientas reconocen -- lo
 * trajo Alejandro en un screenshot. Cierra la visita con tres cosas, en este
 * orden: el gracias, COMO VA SU TARJETA de sellos, y la invitacion a
 * calificar.
 *
 * La tarjeta es la parte que hace volver: "te faltan 3 sellos" es una razon
 * concreta para agendar otra vez, y es informacion que la clienta no tiene
 * de otra forma -- en el mostrador nadie se la dice.
 */
final class ThankYouMessage
{
    /** Lo que se ofrece al final: tocar, no escribir. */
    public const MY_CARD = 'Mi tarjeta';

    public const RATE = 'Calificar servicio';

    /**
     * El texto completo, para la ventana abierta y para la bandeja.
     *
     * Los renglones de la tarjeta solo aparecen si el negocio TIENE un
     * programa activo: "¡Ya tienes 0 de 0 sellos!" es peor que no decir nada.
     */
    public static function text(Appointment $appointment): string
    {
        $d = self::values($appointment);

        $lineas = [
            '*Gracias por tu visita*',
            '',
            '👋 ¡Hola'.($d['cliente'] !== '' ? ', '.$d['cliente'] : '').'!',
            'Gracias por visitarnos 💅',
            '',
            '🧾 Servicio: *'.$d['servicio'].'*',
            '📅 Fecha: *'.$d['fecha'].'*',
        ];

        if ($d['required'] > 0) {
            $lineas[] = '';
            $lineas[] = '*Info de tu tarjeta*';
            $lineas[] = sprintf('🎯 ¡Ya tienes *%d de %d* sellos!', $d['stamps'], $d['required']);

            if ($d['premio'] !== '') {
                $lineas[] = '🎁 Próximo: *'.$d['premio'].'*';
            }
        }

        return implode("\n", $lineas);
    }

    /**
     * La misma, como plantilla aprobada, para fuera de las 24 horas.
     *
     * Null cuando el negocio no tiene programa de sellos: la plantilla los
     * nombra en renglones fijos, y sin programa diria un numero que no
     * significa nada. Ese negocio recibe solo el texto -- que dentro de la
     * ventana llega igual.
     */
    public static function template(Appointment $appointment): ?MessageTemplate
    {
        $d = self::values($appointment);

        if ($d['required'] < 1) {
            return null;
        }

        return MessageTemplate::gracias(
            $d['cliente'] !== '' ? $d['cliente'] : 'hola',
            $d['servicio'] !== '' ? $d['servicio'] : 'tu servicio',
            $d['fecha'],
            (string) $d['stamps'],
            (string) $d['required'],
            $d['premio'] !== '' ? $d['premio'] : 'tu próxima recompensa',
        );
    }

    /**
     * @return array{cliente: string, servicio: string, fecha: string, stamps: int, required: int, premio: string}
     */
    private static function values(Appointment $appointment): array
    {
        $valores = StageMessage::values($appointment);
        $tz = $appointment->business?->businessTimezone() ?? config('spa.defaults.timezone');
        $inicio = $appointment->starts_at
            ? CarbonImmutable::parse($appointment->starts_at)->setTimezone($tz)
            : null;

        $tarjeta = $appointment->client !== null
            ? app(LoyaltyService::class)->cardFor($appointment->client)
            : null;

        return [
            'cliente' => $valores['cliente'],
            'servicio' => $valores['servicio'],
            'fecha' => $inicio !== null
                ? ucfirst($inicio->locale('es')->isoFormat('dddd D [de] MMMM'))
                : '',
            'stamps' => (int) ($tarjeta['stamps'] ?? 0),
            'required' => (int) ($tarjeta['required'] ?? 0),
            'premio' => (string) ($tarjeta['program']['reward_label'] ?? ''),
        ];
    }
}
