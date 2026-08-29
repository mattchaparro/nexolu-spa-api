<?php

namespace App\Services\Messaging\Contracts;

/**
 * Costo real de mensajeria en un periodo, sin atarse a como se calcula. Hoy
 * suma la tabla local `whatsapp_usage_daily` (App\Services\WhatsApp\WhatsAppCostReporter);
 * cuando el envio se mueva a Nexolu Communications, el costo probablemente
 * se consulte a ese servicio (mismo patron que App\Services\AiPlatformUsageService
 * contra el IA Core) en vez de sumarse localmente - PlatformFinanceService no
 * deberia necesitar cambios.
 */
interface MessagingCostReporter
{
    /** Costo real en USD en el rango, o null si no se pudo determinar. */
    public function costUsdForPeriod(string $dateFrom, string $dateTo): ?float;
}
