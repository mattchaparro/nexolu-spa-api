<?php

namespace App\Support;

use App\Models\Business;

/**
 * Lo que el negocio dice de si mismo en su pagina publica.
 *
 * Campos fijos y no bloques libres, a proposito: el constructor de landings se
 * esta haciendo aparte y se va a reutilizar. Adivinar aca su esquema seria
 * construir algo que despues hay que migrar. Esto es el modo simple -- una
 * frase, un parrafo, y como escribirle -- que ademas es lo que un spa de
 * verdad necesita el primer dia.
 */
final class PublicProfile
{
    /** @var array<string, string> */
    private const FIELDS = [
        'headline' => 'Frase principal',
        'about' => 'Sobre el negocio',
        'instagram' => 'Instagram',
        'whatsapp' => 'WhatsApp',
        'maps_url' => 'Enlace de Google Maps',
    ];

    /** @return list<string> */
    public static function fields(): array
    {
        return array_keys(self::FIELDS);
    }

    /** @return array<string, string> */
    public static function labels(): array
    {
        return self::FIELDS;
    }

    /**
     * El perfil listo para pintar, con los vacios resueltos.
     *
     * Un negocio que no escribio nada igual tiene una pagina que se lee: el
     * titular cae a su nombre y el WhatsApp al telefono que ya tiene cargado.
     * Una pagina publica a medio llenar es peor que ninguna -- el cliente la
     * abre, ve huecos, y decide que el local no existe.
     *
     * @return array<string, string|null>
     */
    public static function resolve(Business $business): array
    {
        $stored = $business->public_profile ?? [];

        return [
            'headline' => self::clean($stored['headline'] ?? null) ?? $business->name,
            'about' => self::clean($stored['about'] ?? null),
            'instagram' => self::instagramUrl(self::clean($stored['instagram'] ?? null)),
            'whatsapp' => self::clean($stored['whatsapp'] ?? null) ?? $business->phone,
            'maps_url' => self::clean($stored['maps_url'] ?? null),
        ];
    }

    /**
     * Guarda solo los campos conocidos, recortados.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, string>
     */
    public static function sanitize(array $input): array
    {
        $result = [];

        foreach (self::fields() as $field) {
            $value = self::clean($input[$field] ?? null);

            if ($value !== null) {
                $result[$field] = $value;
            }
        }

        return $result;
    }

    private static function clean(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * Acepta `@nombre`, `nombre` o la URL completa, y devuelve siempre la URL.
     *
     * Quien llena esto escribe lo primero; pegar `@luxurynails` en un href
     * produce un enlace roto que nadie prueba hasta que un cliente lo toca.
     */
    private static function instagramUrl(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (str_starts_with($value, 'http://') || str_starts_with($value, 'https://')) {
            return $value;
        }

        return 'https://instagram.com/'.ltrim($value, '@/');
    }
}
