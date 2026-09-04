<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;

/**
 * Achica y recomprime lo que entra, antes de guardarlo.
 *
 * POR QUE IMPORTA MAS DE LO QUE PARECE. Una foto de celular son entre 3 y 5 MB
 * a 4000 pixeles de ancho. Nadie ve esos pixeles: la ficha la muestra a 144, el
 * carrusel a 96, e Instagram reescala a 1080 de todas formas. Servir el
 * original es mandar cuarenta veces los bytes que hacen falta -- desde un
 * droplet de un core que ademas sostiene el POS en produccion.
 *
 * Y CON META ES PEOR: al publicar, Meta DESCARGA la imagen desde nuestro
 * servidor. Un carrusel de diez fotos sin comprimir son cuarenta megas que ese
 * droplet tiene que servir mientras atiende a las clientas del mostrador.
 *
 * DE PASO SE VAN LOS METADATOS, y eso no es un efecto secundario menor: una
 * foto de celular trae las coordenadas GPS de donde se tomo. Publicar la foto
 * de las unas de una clienta con la ubicacion exacta incrustada es un dato que
 * ella nunca dio. Al recodificar, GD no copia el EXIF: desaparece solo.
 *
 * NUNCA HACE FALLAR UNA SUBIDA. Si GD no esta, si la imagen es rara, si algo
 * revienta -- se guarda el original y ya. Perder la foto del trabajo de alguien
 * porque una libreria no estaba instalada seria un intercambio pesimo.
 */
final class ImageCompressor
{
    /**
     * El borde largo, en pixeles.
     *
     * 1600 y no 1080: Instagram publica a 1080 pero la ficha de la clienta es
     * lo que la profesional mira para reproducir un trabajo, y ahi si se hace
     * zoom. 1600 deja margen para eso y sigue siendo una decima parte del
     * peso original.
     */
    public const MAX_EDGE = 1600;

    /**
     * El borde largo para lo que se LEE, no para lo que se mira.
     *
     * Un comprobante de transferencia es un pantallazo de la app del banco, y
     * lo unico que importa de el es el texto. Un celular actual entrega
     * 1170x2532: bajarlo a 1600 de alto deja el ancho en 739, y ahi los
     * numeros de un banco quedan al limite de legibles. Un comprobante que no
     * se puede leer no es evidencia de nada, y el cierre del dia vuelve a
     * cuadrar contra lo que alguien dijo.
     *
     * Pesa mas, y esta bien: son unos pocos por dia, no un carrusel.
     */
    public const MAX_EDGE_DOCUMENT = 2400;

    /** Calidad JPEG. A 82 la diferencia no se ve y el archivo cae ~70%. */
    public const JPEG_QUALITY = 82;

    /**
     * Tope de pixeles que se acepta decodificar.
     *
     * GD descomprime a memoria: cada pixel son 4 bytes, asi que una imagen de
     * 16 MP ocupa 64 MB antes de tocar nada. Con el `memory_limit` de 128M por
     * defecto, pasar de ahi es un OOM que se lleva la request entera.
     *
     * No es una limitacion teorica: el modo de 48 MP de un iPhone produce
     * justo eso. Por encima del tope no se comprime -- se guarda el original,
     * que igual esta acotado a 4 MB por la validacion.
     */
    private const MAX_PIXELS = 16_000_000;

    /**
     * Lo que se sabe recodificar.
     *
     * No se convierte de formato a proposito: un logo con transparencia
     * aplanado a JPEG sale con fondo negro, y eso se descubre en la pagina
     * publica. Ademas Instagram solo acepta JPEG y PNG -- pasar todo a WebP
     * romperia la publicacion.
     *
     * @var list<string>
     */
    private const SUPPORTED_MIMES = ['image/jpeg', 'image/png', 'image/webp'];

    /**
     * Devuelve la ruta de un temporal ya comprimido, o null si no se pudo o no
     * valia la pena.
     *
     * Null NO es un error: significa "guarda el original".
     */
    public static function compress(UploadedFile $file, int $maxEdge = self::MAX_EDGE): ?string
    {
        if (! function_exists('imagecreatefromstring')) {
            // Sin GD no hay nada que hacer, y no es motivo para rechazar una
            // subida. Ver el Dockerfile: en produccion la extension se instala.
            return null;
        }

        $origen = $file->getRealPath();

        if ($origen === false || ! is_readable($origen)) {
            return null;
        }

        /*
         * Se mira el tipo REAL antes de tocar nada. No es sólo por eficiencia:
         * `getimagesize` sobre un PDF emite un aviso de PHP, y un aviso por
         * cada comprobante de transferencia que alguien sube es ruido en el
         * log justo donde después hay que buscar un problema de verdad.
         */
        if (! in_array($file->getMimeType(), self::SUPPORTED_MIMES, true)) {
            return null;
        }

        try {
            return self::process($origen, $file->getSize() ?: PHP_INT_MAX, $maxEdge);
        } catch (\Throwable $e) {
            logger()->warning('No se pudo comprimir la imagen', ['error' => $e->getMessage()]);

            return null;
        }
    }

    private static function process(string $origen, int $tamanoOriginal, int $maxEdge): ?string
    {
        $info = @getimagesize($origen);

        if ($info === false) {
            return null;
        }

        [$ancho, $alto] = $info;
        $tipo = $info[2];

        if ($ancho * $alto > self::MAX_PIXELS) {
            return null;
        }

        $imagen = match ($tipo) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($origen),
            IMAGETYPE_PNG => @imagecreatefrompng($origen),
            IMAGETYPE_WEBP => @imagecreatefromwebp($origen),
            default => null,
        };

        if ($imagen === false || $imagen === null) {
            return null;
        }

        try {
            $imagen = self::enderezar($imagen, $origen, $tipo);
            $imagen = self::redimensionar($imagen, $tipo, $maxEdge);

            $destino = tempnam(sys_get_temp_dir(), 'nxcompress');

            if ($destino === false) {
                return null;
            }

            $ok = match ($tipo) {
                IMAGETYPE_JPEG => imagejpeg($imagen, $destino, self::JPEG_QUALITY),
                IMAGETYPE_PNG => imagepng($imagen, $destino, 8),
                IMAGETYPE_WEBP => imagewebp($imagen, $destino, self::JPEG_QUALITY),
                default => false,
            };

            if (! $ok) {
                @unlink($destino);

                return null;
            }

            /*
             * Si al recomprimir quedo MAS pesada, se descarta.
             *
             * Pasa de verdad: una imagen ya optimizada, o un PNG plano de
             * pocos colores que GD reescribe peor. Quedarse con el resultado
             * "porque pasamos por el compresor" seria empeorar el archivo por
             * seguir un proceso.
             */
            if (filesize($destino) >= $tamanoOriginal) {
                @unlink($destino);

                return null;
            }

            return $destino;
        } finally {
            imagedestroy($imagen);
        }
    }

    /**
     * Deja la foto derecha.
     *
     * El celular no rota los pixeles: escribe "esta girada" en el EXIF y deja
     * que el visor lo aplique. GD NO lo aplica, asi que sin esto una foto
     * vertical se guarda acostada -- y como al recodificar el EXIF se pierde,
     * queda acostada PARA SIEMPRE, sin forma de recuperar el dato.
     *
     * @param  \GdImage  $imagen
     * @return \GdImage
     */
    private static function enderezar($imagen, string $origen, int $tipo)
    {
        if ($tipo !== IMAGETYPE_JPEG || ! function_exists('exif_read_data')) {
            return $imagen;
        }

        $exif = @exif_read_data($origen);
        $orientacion = (int) ($exif['Orientation'] ?? 1);

        $grados = match ($orientacion) {
            3 => 180,
            6 => -90,
            8 => 90,
            default => 0,
        };

        if ($grados === 0) {
            return $imagen;
        }

        $rotada = imagerotate($imagen, $grados, 0);

        if ($rotada === false) {
            return $imagen;
        }

        imagedestroy($imagen);

        return $rotada;
    }

    /**
     * @param  \GdImage  $imagen
     * @return \GdImage
     */
    private static function redimensionar($imagen, int $tipo, int $maxEdge)
    {
        // Se releen del recurso: despues de rotar, el ancho y el alto pueden
        // haberse intercambiado respecto de lo que dijo `getimagesize`.
        $ancho = imagesx($imagen);
        $alto = imagesy($imagen);
        $largo = max($ancho, $alto);

        if ($largo <= $maxEdge) {
            // Ya es chica. Igual se recodifica: es donde se va el EXIF y donde
            // un JPEG guardado a calidad 100 baja a la mitad.
            return $imagen;
        }

        $escala = $maxEdge / $largo;
        $nuevoAncho = max(1, (int) round($ancho * $escala));
        $nuevoAlto = max(1, (int) round($alto * $escala));

        $destino = imagecreatetruecolor($nuevoAncho, $nuevoAlto);

        /*
         * La transparencia se conserva a mano. `imagecreatetruecolor` nace con
         * fondo negro opaco, asi que sin esto el PNG de un logo sale con un
         * rectangulo negro donde antes no habia nada.
         */
        if ($tipo === IMAGETYPE_PNG || $tipo === IMAGETYPE_WEBP) {
            imagealphablending($destino, false);
            imagesavealpha($destino, true);
            imagefill($destino, 0, 0, imagecolorallocatealpha($destino, 0, 0, 0, 127));
        }

        imagecopyresampled($destino, $imagen, 0, 0, 0, 0, $nuevoAncho, $nuevoAlto, $ancho, $alto);
        imagedestroy($imagen);

        return $destino;
    }
}
