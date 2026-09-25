<?php

namespace App\Services\Messaging;

use App\Models\Appointment;
use App\Models\Business;
use App\Models\Message;
use App\Models\User;
use App\Models\WhatsappConversation;
use Carbon\CarbonImmutable;

/**
 * El resumen del día para quien es dueño del negocio.
 *
 * Es lo contrario de una notificación: no interrumpe, cuenta. Existe porque
 * avisar por cada cita agendada es ruido -- en un día normal son decenas --
 * pero no saber nada tampoco sirve. Una vez al día, por correo, gratis.
 *
 * Lo urgente NO vive acá: una cancelación deja un cupo que alguien tiene que
 * llenar hoy, y eso sale al instante (ver `cancelaciones` en el mismo
 * comando). Lo de "hay clientas sin responder" lo manda Connect, que es
 * quien tiene la bandeja.
 */
class DailyDigestService
{
    public function __construct(private readonly NexoluCommsMail $mail) {}

    /** @return list<string> */
    public function recipients(Business $business): array
    {
        /*
         * A dónde quiere el negocio los avisos, si lo dijo.
         *
         * El usuario dueño de Luxury es admin@luxurynails.com.co --su
         * cuenta para entrar-- pero Alejandro lee mattchaparrof@gmail.com,
         * que ya es su usuario de plataforma y no puede repetirse. Los
         * avisos van a donde se leen, no a donde se inicia sesión.
         */
        $propios = collect((array) $business->schedulingSetting('notification_emails'))
            ->map(fn ($e) => trim((string) $e))
            ->filter(fn (string $e) => filter_var($e, FILTER_VALIDATE_EMAIL) !== false)
            ->values()
            ->all();

        if ($propios !== []) {
            return $propios;
        }

        return User::withoutGlobalScope('business')
            ->where('business_id', $business->id)
            ->where('is_active', true)
            ->where('is_owner', true)
            ->pluck('email')
            ->filter()
            ->values()
            ->all();
    }

    /**
     * El texto del resumen, o null si el día no tuvo nada que contar.
     *
     * Null y no un correo vacío: "hoy no pasó nada" repetido cada noche es
     * exactamente como se le enseña a alguien a ignorar un remitente.
     */
    public function compose(Business $business, ?CarbonImmutable $day = null): ?string
    {
        $tz = $business->businessTimezone();
        $day = ($day ?? CarbonImmutable::now($tz))->setTimezone($tz);
        $desde = $day->startOfDay();
        $hasta = $day->endOfDay();

        $citas = Appointment::withoutGlobalScope('business')
            ->where('business_id', $business->id)
            ->whereBetween('created_at', [$desde->utc(), $hasta->utc()])
            ->get();

        $canceladas = Appointment::withoutGlobalScope('business')
            ->where('business_id', $business->id)
            ->where('status', Appointment::STATUS_CANCELLED)
            ->whereBetween('updated_at', [$desde->utc(), $hasta->utc()])
            ->count();

        $agendadas = $citas->count();
        $porWhatsapp = $citas->where('source', Appointment::SOURCE_WHATSAPP_AGENT)->count();

        $mensajes = Message::withoutGlobalScope('business')
            ->where('business_id', $business->id)
            ->where('direction', Message::DIRECTION_IN)
            ->whereBetween('created_at', [$desde->utc(), $hasta->utc()])
            ->count();

        $sinResponder = WhatsappConversation::withoutGlobalScope('business')
            ->where('business_id', $business->id)
            ->whereNull('read_at')
            ->count();

        if ($agendadas === 0 && $canceladas === 0 && $mensajes === 0) {
            return null;
        }

        $lineas = [
            $day->translatedFormat('l j \d\e F').':',
            '',
            "• Citas agendadas: {$agendadas}".($porWhatsapp > 0 ? " ({$porWhatsapp} por WhatsApp)" : ''),
            "• Canceladas: {$canceladas}",
            "• Mensajes recibidos: {$mensajes}",
        ];

        if ($sinResponder > 0) {
            $lineas[] = "• Conversaciones sin leer: {$sinResponder}";
        }

        $lineas[] = '';
        $lineas[] = 'La conversación completa está en https://connect.nexolu.co/chat';

        return implode("\n", $lineas);
    }

    public function sendFor(Business $business, ?CarbonImmutable $day = null): bool
    {
        $texto = $this->compose($business, $day);
        $destinatarios = $this->recipients($business);

        if ($texto === null || $destinatarios === []) {
            return false;
        }

        return $this->mail->send(
            $destinatarios,
            'Resumen de hoy — '.$business->name,
            $texto,
            $business->id,
            'daily_digest:'.($day ?? CarbonImmutable::now($business->businessTimezone()))->format('Y-m-d'),
        );
    }
}
