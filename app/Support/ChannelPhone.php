<?php

namespace App\Support;

/**
 * Normaliza un numero de telefono al formato que usa WhatsApp Cloud API:
 * digitos con codigo de pais, sin '+' (ej. 573001234567).
 */
class ChannelPhone
{
    private const COLOMBIA_CC = '57';

    public static function normalize(string $raw): ?string
    {
        $digits = preg_replace('/\D/', '', $raw) ?? '';

        // Movil colombiano local (3XXXXXXXXX) -> antepone el indicativo. El
        // producto es Colombia-only, ver CLAUDE.md.
        if (strlen($digits) === 10 && str_starts_with($digits, '3')) {
            $digits = self::COLOMBIA_CC.$digits;
        }

        if (strlen($digits) < 11 || strlen($digits) > 15) {
            return null;
        }

        return $digits;
    }
}
