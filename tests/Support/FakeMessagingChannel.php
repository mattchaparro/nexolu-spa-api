<?php

namespace Tests\Support;

use App\Services\Messaging\Contracts\MessagingChannel;

/**
 * Un canal de mensajería de mentira, en UN solo lugar.
 *
 * Antes cada prueba se armaba su clase anónima, y el contrato tiene seis
 * métodos: agregarle uno rompía cada copia con un fatal, y la firma de
 * `markAsReadWithTyping` ya se había desincronizado en una de ellas. Un doble
 * duplicado es deuda que cobra intereses cada vez que el contrato cambia.
 *
 * Guarda lo enviado en `$sent` para poder afirmar sobre ello, y se le puede
 * pedir que falle — que es la mitad de lo que hay que probar.
 */
class FakeMessagingChannel implements MessagingChannel
{
    /** @var list<array{to: string, body: string, type: string}> */
    public array $sent = [];

    public function __construct(
        private readonly bool $configured = true,
        /** Devuelve false en vez de enviar: el canal contesta que no. */
        private readonly bool $rejects = false,
        /** Lanza: el canal está caído. Distinto de rechazar. */
        private readonly ?string $throws = null,
    ) {}

    public static function caido(string $motivo = 'Timeout hablando con el canal'): self
    {
        return new self(throws: $motivo);
    }

    public static function queRechaza(): self
    {
        return new self(rejects: true);
    }

    public static function sinConfigurar(): self
    {
        return new self(configured: false);
    }

    public function isConfigured(): bool
    {
        return $this->configured;
    }

    public function sendText(string $to, string $body, ?int $businessId = null, string $type = 'generico'): bool
    {
        if ($this->throws !== null) {
            throw new \RuntimeException($this->throws);
        }

        if ($this->rejects) {
            return false;
        }

        $this->sent[] = ['to' => $to, 'body' => $body, 'type' => $type];

        return true;
    }

    public function sendTemplate(
        string $to,
        string $name,
        string $languageCode,
        array $components = [],
        ?int $businessId = null,
        string $type = 'generico',
    ): bool {
        return ! $this->rejects;
    }

    public function sendDocument(
        string $to,
        string $documentUrl,
        string $filename,
        string $caption = '',
        ?int $businessId = null,
        string $type = 'generico',
    ): bool {
        return ! $this->rejects;
    }

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
        return ! $this->rejects;
    }

    public function markAsReadWithTyping(string $to, string $messageId): bool
    {
        return true;
    }
}
