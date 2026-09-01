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
        'google_review_url' => 'Enlace para dejar reseña en Google',
        'show_staff_ratings' => 'Mostrar la puntuación de cada persona',
    ];

    /**
     * Los que NO son texto. Se guardan y se leen como booleanos.
     *
     * @var list<string>
     */
    private const FLAGS = ['show_staff_ratings'];

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
     * @return array<string, string|bool|null>
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
            /*
             * A donde mandar a quien quiera dejar resena en Google.
             *
             * Se le ofrece a TODO el que responde la encuesta, no solo a quien
             * calificó bien. Filtrar por nota se llama "review gating" y las
             * politicas de Google lo prohiben expresamente: pedir resenas solo
             * a los contentos puede costarle al negocio la ficha entera. Las
             * notas bajas sirven para llamar a esa persona, no para esconderla.
             */
            'google_review_url' => self::clean($stored['google_review_url'] ?? null),

            /*
             * Si la puntuacion de cada persona sale en la pagina.
             *
             * APAGADO por defecto, y no por timidez: publicar la nota de
             * alguien es una decision sobre una persona real, no una
             * preferencia de diseño. Una manicurista con 4.1 al lado de una
             * con 4.9 en la vitrina del local es una conversacion que el dueño
             * tiene que querer tener. Se enciende en un clic desde Mi pagina.
             */
            'show_staff_ratings' => (bool) ($stored['show_staff_ratings'] ?? false),
        ];
    }

    /**
     * Guarda solo los campos conocidos, recortados.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, string|bool>
     */
    public static function sanitize(array $input): array
    {
        $result = [];

        foreach (self::fields() as $field) {
            if (in_array($field, self::FLAGS, true)) {
                /*
                 * Un booleano se guarda SIEMPRE, tambien en false.
                 *
                 * Los de texto se omiten cuando vienen vacios -- asi el
                 * titular puede caer al nombre del negocio -- pero con un
                 * interruptor eso significaria que apagarlo no lo apaga: al
                 * releer, el ausente vuelve a caer al default.
                 */
                $result[$field] = filter_var(
                    $input[$field] ?? false,
                    FILTER_VALIDATE_BOOLEAN,
                );

                continue;
            }

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
