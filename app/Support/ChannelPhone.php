<?php

namespace App\Support;

/**
 * Normaliza un telefono al formato que usa WhatsApp Cloud API: digitos con
 * indicativo de pais y sin '+' (ej. 573001234567).
 *
 * El pais es un PARAMETRO, no una constante. Blue Souls concatenaba "57" a
 * mano en ocho lugares distintos, y eso es lo que cierra la puerta a operar
 * fuera de Colombia sin tocar codigo. Aca el indicativo sale de
 * `businesses.country_code`, que cada negocio define.
 */
class ChannelPhone
{
    /**
     * Indicativo y longitud del numero nacional, por pais.
     *
     * Se agregan paises aca y en ningun otro lado. La longitud nacional es
     * lo que permite distinguir un numero local de uno que ya trae
     * indicativo: sin ella habria que adivinar.
     *
     * @var array<string, array{cc: string, national_length: int}>
     */
    private const COUNTRIES = [
        'CO' => ['cc' => '57', 'national_length' => 10],
        'MX' => ['cc' => '52', 'national_length' => 10],
        'PE' => ['cc' => '51', 'national_length' => 9],
        'EC' => ['cc' => '593', 'national_length' => 9],
        'CL' => ['cc' => '56', 'national_length' => 9],
        'AR' => ['cc' => '54', 'national_length' => 10],
        'US' => ['cc' => '1', 'national_length' => 10],
    ];

    /**
     * @param  string  $countryCode  ISO 3166-1 alfa-2, normalmente
     *                               `businesses.country_code`.
     */
    public static function normalize(string $raw, string $countryCode = 'CO'): ?string
    {
        $digits = preg_replace('/\D/', '', $raw) ?? '';

        if ($digits === '') {
            return null;
        }

        $country = self::COUNTRIES[strtoupper($countryCode)] ?? self::COUNTRIES['CO'];

        // Numero nacional: se le antepone el indicativo. Si ya viene con el
        // indicativo incluido, se deja como esta -- reconocerlo por longitud
        // evita el error clasico de anteponerlo dos veces.
        if (strlen($digits) === $country['national_length']) {
            $digits = $country['cc'].$digits;
        }

        // Rango de E.164: entre 8 y 15 digitos incluyendo indicativo.
        if (strlen($digits) < 8 || strlen($digits) > 15) {
            return null;
        }

        return $digits;
    }

    /** @return list<string> */
    public static function supportedCountries(): array
    {
        return array_keys(self::COUNTRIES);
    }
}
