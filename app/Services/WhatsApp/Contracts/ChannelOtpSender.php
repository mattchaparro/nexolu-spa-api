<?php

namespace App\Services\WhatsApp\Contracts;

interface ChannelOtpSender
{
    /**
     * @param  string  $channel  'whatsapp', etc.
     * @param  string  $externalId  telefono destino, digitos con codigo de pais
     * @param  string  $code  el codigo de 6 digitos en claro
     * @param  int|null  $businessId  del usuario que pide el vinculo - solo para el log de comunicaciones (ver WhatsappLog)
     */
    public function send(string $channel, string $externalId, string $code, ?int $businessId = null): void;
}
