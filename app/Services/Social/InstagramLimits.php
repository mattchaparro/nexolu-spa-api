<?php

namespace App\Services\Social;

use App\Models\SocialPost;
use App\Models\SocialPostImage;
use App\Support\ImageStorage;

/**
 * Lo que Instagram rechaza, comprobado ANTES de intentar.
 *
 * No es duplicar la validacion de Meta por gusto. Cuando Meta rechaza, lo que
 * vuelve es un `error_subcode` y una frase en ingles que no le dice nada a
 * quien maneja un spa -- y para entonces ya se creo el contenedor, ya se
 * consumio cupo del limite diario, y la publicacion quedo en un estado a
 * medias. Comprobar antes convierte todo eso en una frase en espanol que dice
 * QUE HAY QUE HACER.
 *
 * Es tambien el unico sitio donde estos numeros estan escritos, y cada uno
 * lleva de donde sale. Un `4/5` suelto en medio de un servicio es un numero
 * magico que nadie se atreve a tocar en seis meses.
 */
final class InstagramLimits
{
    /** Hasta 10 en un carrusel. */
    public const MAX_IMAGES = SocialPostImage::MAX_PER_POST;

    /** El texto, contando los hashtags. */
    public const MAX_CAPTION = 2200;

    /** Hasta 30 etiquetas; con 31 rechaza la publicacion entera. */
    public const MAX_HASHTAGS = 30;

    /**
     * La proporcion permitida, de la mas vertical a la mas horizontal.
     *
     * 4:5 = 0.8 (vertical) y 1.91:1 = 1.91 (horizontal). Fuera de ese rango
     * Meta rechaza, y es un caso REAL en este producto: una mano fotografiada
     * de arriba abajo, o el pantallazo de un celular, se pasan de largo.
     */
    public const MIN_RATIO = 0.8;

    public const MAX_RATIO = 1.91;

    /**
     * Por que esta publicacion no puede salir, o null si puede.
     *
     * Devuelve UN motivo, el primero: una lista de cinco problemas es una
     * pantalla que nadie lee. Se arregla uno, se vuelve a intentar.
     */
    public static function rejects(SocialPost $post): ?string
    {
        $images = $post->images;

        if ($images->isEmpty()) {
            return 'No tiene ninguna imagen.';
        }

        if ($images->count() > self::MAX_IMAGES) {
            return 'Instagram admite hasta '.self::MAX_IMAGES.' imágenes por publicación.';
        }

        $caption = trim((string) $post->caption);

        if ($caption === '') {
            return 'No tiene texto.';
        }

        // El texto y los hashtags viajan JUNTOS a Instagram, asi que el tope
        // se cuenta sobre la suma -- no sobre el texto solo.
        if (mb_strlen(self::fullCaption($post)) > self::MAX_CAPTION) {
            return 'El texto con los hashtags pasa de '.self::MAX_CAPTION.' caracteres.';
        }

        if (count($post->hashtags ?? []) > self::MAX_HASHTAGS) {
            return 'Instagram admite hasta '.self::MAX_HASHTAGS.' hashtags.';
        }

        foreach ($images as $index => $image) {
            if (($motivo = self::rejectsImage($image, $index)) !== null) {
                return $motivo;
            }
        }

        return null;
    }

    /**
     * El texto tal como llega a Instagram: el copy y abajo las etiquetas.
     *
     * Se arma aca y no en el publicador porque tambien lo necesita la
     * validacion del largo, y dos formas de armarlo son dos largos distintos.
     */
    public static function fullCaption(SocialPost $post): string
    {
        $hashtags = implode(' ', $post->hashtags ?? []);

        return trim(trim((string) $post->caption)."\n\n".$hashtags);
    }

    private static function rejectsImage(SocialPostImage $image, int $index): ?string
    {
        $numero = $index + 1;

        $path = $image->image_path ?: $image->clientPhoto?->image_path;

        if ($path === null) {
            return 'A la imagen '.$numero.' ya no se llega: la foto se borró de la ficha.';
        }

        /*
         * Se miden los pixeles LEYENDO EL ARCHIVO, no confiando en lo que se
         * guardo al subirlo: entre medio pudo pasar el compresor, o alguien
         * pudo reemplazar el archivo. Es el mismo archivo que Meta va a
         * descargar, asi que es el que hay que medir.
         */
        /*
         * La URL tiene que ser ABSOLUTA. Meta va a buscar la imagen a nuestro
         * servidor: una ruta relativa -- que es lo que devuelve `Storage::url`
         * cuando `APP_URL` esta mal puesta -- no la puede descargar nadie.
         *
         * Es un fallo de configuracion que sin esto llega como un error
         * criptico de Meta sobre un "media download failure", y manda a buscar
         * el problema en la foto en vez de en el `.env`.
         */
        $url = ImageStorage::url($path);

        if ($url === null || ! str_starts_with($url, 'http')) {
            return 'La imagen '.$numero.' no tiene una dirección pública. Revisa APP_URL en el servidor.';
        }

        $size = ImageStorage::dimensions($path);

        if ($size === null) {
            // No se pudo medir. Se deja pasar: rechazar una publicacion buena
            // porque no pudimos abrir un archivo es peor que dejar que Meta
            // opine. Ella la mide igual.
            return null;
        }

        [$ancho, $alto] = $size;
        $ratio = $ancho / max(1, $alto);

        if ($ratio < self::MIN_RATIO) {
            return 'La imagen '.$numero.' es demasiado alargada para Instagram. '
                .'Recórtala más cuadrada (como mucho 4:5).';
        }

        if ($ratio > self::MAX_RATIO) {
            return 'La imagen '.$numero.' es demasiado ancha para Instagram. '
                .'Recórtala más cuadrada (como mucho 1.91:1).';
        }

        return null;
    }
}
