<?php

namespace App\Services\Messaging;

use App\Ai\EsUnaPrueba;
use App\Ai\HoraLegible;
use App\Jobs\SendNewBookingEmailJob;
use App\Models\Appointment;
use App\Models\AppointmentItem;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Un correo a los dueños por cada cita que entra, diciendo POR DÓNDE entró.
 *
 * Lo pidió Alejandro el 25 de septiembre: saber al momento que alguien
 * agendó, y si fue por la página, por WhatsApp o desde el panel. El resumen
 * de la noche (DailyDigestService) cuenta el día; esto avisa cada una.
 * Van a los mismos destinatarios: los usuarios dueños del negocio.
 *
 * No sale para lo que no es una reserva de verdad: las citas de prueba del
 * bot (EsUnaPrueba) y lo que se hace con «No avisar a nadie» o trayendo
 * datos del sistema viejo (MessageDispatcher::silently).
 */
final class AvisoCitaNueva
{
    public const CANALES = [
        Appointment::SOURCE_ONLINE => 'Página web',
        Appointment::SOURCE_WHATSAPP_AGENT => 'WhatsApp (bot)',
        Appointment::SOURCE_ADMIN => 'Panel',
        Appointment::SOURCE_PHONE => 'Teléfono',
    ];

    /** Lo deja en cola: el correo no puede demorar la reserva. */
    public function avisar(Appointment $appointment, ?User $por = null): void
    {
        if (MessageDispatcher::isSilenced()) {
            return;
        }

        if (str_contains((string) $appointment->notes, EsUnaPrueba::SELLO)) {
            return;
        }

        try {
            SendNewBookingEmailJob::dispatch($appointment->id, $por?->name);
        } catch (\Throwable $e) {
            // La cita ya quedó: que falle el aviso no puede tumbar la reserva.
            report($e);
        }
    }

    /** @return array{0: string, 1: string} asunto y cuerpo */
    public static function componer(Appointment $appointment, ?string $por = null): array
    {
        $appointment->loadMissing('business', 'items.service', 'items.resource');
        $tz = $appointment->business->businessTimezone();
        $inicio = CarbonImmutable::parse($appointment->starts_at)->setTimezone($tz);
        $dia = ucfirst($inicio->locale('es')->isoFormat('dddd D [de] MMMM'));
        $hora = HoraLegible::de($appointment->starts_at, $tz);
        $cliente = trim((string) $appointment->client_name) ?: 'Sin nombre';

        $canal = self::CANALES[$appointment->source] ?? $appointment->source;
        if ($appointment->source === Appointment::SOURCE_ADMIN && $por) {
            $canal .= ' (la agendó '.$por.')';
        }

        $lineas = [
            'Entró una cita nueva en '.$appointment->business->name.'.',
            '',
            'Canal: '.$canal,
            'Clienta: '.$cliente.($appointment->client_phone ? ' · +'.ltrim($appointment->client_phone, '+') : ''),
            'Día: '.$dia.' a las '.$hora,
        ];

        foreach ($appointment->items as $item) {
            /** @var AppointmentItem $item */
            $lineas[] = 'Servicio: '.($item->service?->name ?? '—')
                .' con '.($item->resource?->name ?? 'quien esté libre')
                .($item->price !== null ? ' · $'.number_format((float) $item->price, 0, ',', '.') : '');
        }

        if (trim((string) $appointment->notes) !== '') {
            $lineas[] = 'Nota: '.trim((string) $appointment->notes);
        }

        $lineas[] = '';
        $lineas[] = 'La agenda: https://agenda.nexolu.co/agenda';

        $asunto = 'Nueva cita ('.(self::CANALES[$appointment->source] ?? $appointment->source).') · '
            .$cliente.' · '.$dia.' '.$hora;

        return [$asunto, implode("\n", $lineas)];
    }
}
