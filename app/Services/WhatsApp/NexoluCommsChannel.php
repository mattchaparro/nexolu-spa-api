<?php

namespace App\Services\WhatsApp;

use App\Services\Messaging\Contracts\MessagingChannel;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Implementacion de MessagingChannel que envia via Nexolu Communications
 * (servicio Python aparte, repo nexolu-comms-api) en vez de hablarle
 * directo a WhatsApp Cloud API - ver WhatsAppCloudClient, la implementacion
 * de hoy. Cual de las dos esta activa lo decide
 * config('services.comms_core.driver') en AppServiceProvider; nada mas
 * (jobs, comandos) deberia necesitar saber cual es.
 *
 * A diferencia de WhatsAppCloudClient, este cliente NO registra uso local
 * (whatsapp_usage_daily): Comms ya lo audita del lado suyo - ver
 * NexoluCommsCostReporter, que consulta ese gasto en vez de sumarlo aca.
 */
class NexoluCommsChannel implements MessagingChannel
{
    private const STATUS_SENT = 'sent';

    public function isConfigured(): bool
    {
        return ! empty(config('services.comms_core.api_key')) && ! empty(config('services.comms_core.base_url'));
    }

    public function sendText(string $to, string $body, ?int $businessId = null, string $type = 'generico'): bool
    {
        return $this->send($to, ['text' => $body]);
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
        return $this->send($to, [
            'whatsapp_template' => [
                'name' => $name,
                'language' => $languageCode,
                'components' => $components,
            ],
        ]);
    }

    /**
     * Documento por link - misma limitacion de ventana de 24h que
     * sendText(), ver MessagingChannel::sendDocument(). Payload asumido
     * (`document: {url, filename, caption}`) siguiendo el mismo patron que
     * `text`/`whatsapp_template`/`whatsapp_flow` de arriba - ajustar si
     * Nexolu Communications (repo aparte) termina exponiendolo distinto.
     */
    public function sendDocument(
        string $to,
        string $documentUrl,
        string $filename,
        string $caption = '',
        ?int $businessId = null,
        string $type = 'generico',
    ): bool {
        return $this->send($to, [
            'document' => [
                'url' => $documentUrl,
                'filename' => $filename,
                'caption' => $caption,
            ],
        ]);
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
        return $this->send($to, [
            'text' => $bodyText,
            'whatsapp_flow' => [
                'flow_id' => $flowId,
                'screen' => $screen,
                'cta' => $cta,
                'flow_token' => $flowToken,
                'data' => $data,
            ],
        ]);
    }

    public function markAsReadWithTyping(string $to, string $messageId): bool
    {
        if (! $this->isConfigured()) {
            return false;
        }

        try {
            $response = $this->client()->post('/v1/whatsapp/read-receipt', [
                'to' => $to,
                'message_id' => $messageId,
            ]);
        } catch (ConnectionException $e) {
            $this->logSafe('warning', 'Nexolu Communications: error de red en read-receipt', ['to' => $to, 'error' => $e->getMessage()]);

            return false;
        }

        return $response->successful() && $response->json('status') === self::STATUS_SENT;
    }

    /**
     * @param  array<string, mixed>  $extra  campos propios de este envio (text, whatsapp_template, whatsapp_flow)
     */
    private function send(string $to, array $extra): bool
    {
        if (! $this->isConfigured()) {
            $this->logSafe('warning', 'Nexolu Communications: intento de envio sin credenciales', ['to' => $to]);

            return false;
        }

        try {
            $response = $this->client()->post('/v1/notifications/send', array_merge([
                'channels' => ['whatsapp'],
                'to' => ['whatsapp' => $to],
            ], $extra));
        } catch (ConnectionException $e) {
            $this->logSafe('error', 'Nexolu Communications: error de red al enviar', ['to' => $to, 'error' => $e->getMessage()]);

            return false;
        }

        if ($response->failed()) {
            $this->logSafe('warning', 'Nexolu Communications: envio rechazado', [
                'to' => $to, 'status' => $response->status(), 'body' => $response->body(),
            ]);

            return false;
        }

        $result = collect($response->json('results'))->firstWhere('channel', 'whatsapp');
        $sent = ($result['status'] ?? null) === self::STATUS_SENT;

        if (! $sent) {
            $this->logSafe('warning', 'Nexolu Communications: canal whatsapp no envio', [
                'to' => $to, 'status' => $result['status'] ?? null, 'error' => $result['error'] ?? null,
            ]);
        }

        return $sent;
    }

    private function client(): PendingRequest
    {
        return Http::withToken((string) config('services.comms_core.api_key'))
            ->timeout(15)
            ->baseUrl(rtrim((string) config('services.comms_core.base_url'), '/'));
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function logSafe(string $level, string $message, array $context): void
    {
        try {
            Log::log($level, $message, $context);
        } catch (\Throwable) {
            // El logging roto nunca debe quitarle al llamante el resultado real del envio.
        }
    }
}
