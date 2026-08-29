<?php

namespace App\Support;

use App\Models\SystemConfig;

/**
 * Configuracion global editable en caliente (superadmin), sin redeploy: hoy
 * respalda el precio de los planes y la promo de descuento - ver
 * SubscriptionPricingService. Cada clave tiene su propio default en el call
 * site, asi que una fila ausente en `system_configs` nunca es un error.
 */
class SystemConfigStore
{
    public static function get(string $key, ?string $default = null): ?string
    {
        return SystemConfig::query()->where('key', $key)->value('value') ?? $default;
    }

    /**
     * @param  array<string, mixed>  $items
     */
    public static function putMany(array $items): void
    {
        foreach ($items as $key => $value) {
            SystemConfig::query()->updateOrCreate(
                ['key' => $key],
                ['value' => $value === null ? null : (string) $value]
            );
        }
    }

    /**
     * @param  array<mixed>  $default
     * @return array<mixed>
     */
    public static function getJson(string $key, array $default = []): array
    {
        $raw = self::get($key);
        if (! is_string($raw) || $raw === '') {
            return $default;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : $default;
    }
}
