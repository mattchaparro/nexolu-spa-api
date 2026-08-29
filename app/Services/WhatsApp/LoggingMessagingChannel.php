<?php

namespace App\Services\WhatsApp;

use App\Models\WhatsappLog;
use App\Services\Messaging\Contracts\MessagingChannel;
use Illuminate\Support\Facades\Log;

/**
 * Decorator sobre la implementacion real de MessagingChannel (WhatsAppCloudClient
 * o NexoluCommsChannel, segun MESSAGING_DRIVER) que registra en `whatsapp_logs`
 * cada intento de envio - exito o fallo, igual que App\Listeners\LogSentEmail
 * hace con `email_logs`. Es el binding real de MessagingChannel en
 * AppServiceProvider: nada mas necesita saber que existe.
 *
 * markAsReadWithTyping() no se loguea a proposito: un acuse de lectura no es
 * un mensaje enviado (mismo criterio que WhatsAppCloudClient::recordUsage()).
 */
class LoggingMessagingChannel implements MessagingChannel
{
    public function __construct(private MessagingChannel $inner) {}

    public function isConfigured(): bool
    {
        return $this->inner->isConfigured();
    }

    public function sendText(string $to, string $body, ?int $businessId = null, string $type = 'generico'): bool
    {
        return $this->logged($businessId, $type, $to, fn () => $this->inner->sendText($to, $body));
    }

    /**
     * @param  list<array<string, mixed>>  $components
     */
    public function sendTemplate(
        string $to,
        string $name,
        string $languageCode,
        array $components = [],
        ?int $businessId = null,
        string $type = 'generico',
    ): bool {
        return $this->logged($businessId, $type, $to, fn () => $this->inner->sendTemplate($to, $name, $languageCode, $components));
    }

    public function sendDocument(
        string $to,
        string $documentUrl,
        string $filename,
        string $caption = '',
        ?int $businessId = null,
        string $type = 'generico',
    ): bool {
        return $this->logged($businessId, $type, $to, fn () => $this->inner->sendDocument($to, $documentUrl, $filename, $caption));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function sendFlow(
        string $to,
        string $flowId,
        string $screen,
        string $bodyText,
        string $cta,
        array $data,
        string $flowToken,
        ?int $businessId = null,
        string $type = 'generico',
    ): bool {
        return $this->logged(
            $businessId,
            $type,
            $to,
            fn () => $this->inner->sendFlow($to, $flowId, $screen, $bodyText, $cta, $data, $flowToken)
        );
    }

    public function markAsReadWithTyping(string $to, string $messageId): bool
    {
        return $this->inner->markAsReadWithTyping($to, $messageId);
    }

    private function logged(?int $businessId, string $type, string $to, \Closure $send): bool
    {
        $sent = $send();

        try {
            WhatsappLog::create([
                'business_id' => $businessId,
                'type' => $type,
                'to_phone' => $to,
                'status' => $sent ? WhatsappLog::STATUS_SENT : WhatsappLog::STATUS_FAILED,
            ]);
        } catch (\Throwable $e) {
            // Nunca dejar que un fallo al loguear tumbe el envio real (mismo
            // criterio que LogSentEmail).
            Log::warning('whatsapp_log.write_failed', ['error' => $e->getMessage()]);
        }

        return $sent;
    }
}
