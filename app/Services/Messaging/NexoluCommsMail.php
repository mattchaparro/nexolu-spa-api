<?php

namespace App\Services\Messaging;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Correo del negocio, por Nexolu Communications.
 *
 * Separado de `NexoluCommsChannel` (que es el contrato de MENSAJERÍA con la
 * clienta) porque esto es otra cosa: avisos internos para el equipo. Un
 * resumen diario no es un mensaje a una clienta y no debe pasar por la
 * bandeja, ni por la ventana de 24h, ni contar como conversación.
 *
 * Fail-soft como todo lo que sale hacia Connect: un aviso que no salió se
 * loguea, nunca tumba el comando que lo pidió.
 */
class NexoluCommsMail
{
    public function isConfigured(): bool
    {
        return (bool) config('services.comms_core.api_key')
            && (bool) config('services.comms_core.base_url');
    }

    /**
     * @param  list<string>  $to
     */
    public function send(array $to, string $subject, string $text, ?int $businessId = null, ?string $reference = null): bool
    {
        $destinatarios = array_values(array_filter(array_map('trim', $to)));

        if ($destinatarios === [] || ! $this->isConfigured()) {
            return false;
        }

        $ok = true;

        foreach ($destinatarios as $email) {
            try {
                $response = Http::withToken(config('services.comms_core.api_key'))
                    ->baseUrl(rtrim((string) config('services.comms_core.base_url'), '/'))
                    ->timeout(15)
                    ->post('/v1/notifications/send', array_filter([
                        'channels' => ['email'],
                        'to' => ['email' => $email],
                        'subject' => $subject,
                        'text' => $text,
                        'business_id' => $businessId === null ? null : (string) $businessId,
                        'reference' => $reference,
                    ], fn ($v) => $v !== null));
            } catch (\Throwable $e) {
                Log::warning('comms_mail: sin conexión con Connect', ['error' => $e->getMessage()]);

                return false;
            }

            $enviado = $response->successful()
                && collect($response->json('results') ?? [])
                    ->contains(fn (array $r) => ($r['status'] ?? null) === 'sent');

            if (! $enviado) {
                Log::warning('comms_mail: Connect no entregó el correo', [
                    'email' => $email,
                    'status' => $response->status(),
                ]);
                $ok = false;
            }
        }

        return $ok;
    }
}
