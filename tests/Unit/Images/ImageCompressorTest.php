<?php

namespace Tests\Unit\Images;

use App\Support\ImageCompressor;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\TestCase;

/**
 * Achicar lo que entra.
 *
 * Una foto de celular son 3 a 5 MB a 4000 píxeles de ancho, y nadie ve esos
 * píxeles: la ficha la muestra a 144, el carrusel a 96, e Instagram reescala a
 * 1080 igual. Servirla entera desde un droplet de un core —que además sostiene
 * el POS— es mandar cuarenta veces los bytes que hacen falta.
 *
 * LO QUE MÁS SE DEFIENDE ACÁ es que la compresión NUNCA rompa una subida. Es
 * una optimización: perder la foto del trabajo de alguien porque una librería
 * no estaba instalada sería un intercambio pésimo.
 */
class ImageCompressorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('Sin GD no hay nada que comprimir.');
        }
    }

    public function test_una_foto_de_celular_baja_al_borde_largo(): void
    {
        $foto = $this->jpeg(4032, 3024);

        $salida = ImageCompressor::compress($foto);

        $this->assertNotNull($salida);
        [$ancho, $alto] = getimagesize($salida);

        $this->assertSame(ImageCompressor::MAX_EDGE, $ancho);
        // La proporción se conserva: 4032x3024 es 4:3, y 1600 de ancho son
        // 1200 de alto. Deformar la foto de un trabajo es peor que no tocarla.
        $this->assertSame(1200, $alto);

        @unlink($salida);
    }

    public function test_una_foto_vertical_se_achica_por_su_lado_largo(): void
    {
        $salida = ImageCompressor::compress($this->jpeg(3024, 4032));

        [$ancho, $alto] = getimagesize($salida);

        $this->assertSame(ImageCompressor::MAX_EDGE, $alto);
        $this->assertSame(1200, $ancho);

        @unlink($salida);
    }

    public function test_pesa_bastante_menos(): void
    {
        $foto = $this->jpeg(4032, 3024);
        $antes = $foto->getSize();

        $salida = ImageCompressor::compress($foto);

        $this->assertLessThan($antes / 2, filesize($salida));

        @unlink($salida);
    }

    public function test_una_imagen_ya_chica_no_se_agranda(): void
    {
        // Se recodifica igual —ahí es donde se va el EXIF— pero no se escala
        // hacia arriba: agrandar una foto sólo inventa píxeles y peso.
        $salida = ImageCompressor::compress($this->jpeg(800, 600));

        if ($salida === null) {
            // Válido: si recomprimir no la mejoró, se guarda el original.
            $this->assertTrue(true);

            return;
        }

        [$ancho, $alto] = getimagesize($salida);

        $this->assertSame(800, $ancho);
        $this->assertSame(600, $alto);

        @unlink($salida);
    }

    public function test_el_png_con_transparencia_no_sale_con_fondo_negro(): void
    {
        /*
         * `imagecreatetruecolor` nace con fondo negro opaco. Sin conservar el
         * canal alfa a mano, el logo de un negocio sale con un rectángulo
         * negro — y eso se descubre en la página pública, no acá.
         */
        $salida = ImageCompressor::compress($this->pngTransparente(2000, 2000));

        $this->assertNotNull($salida);

        $imagen = imagecreatefrompng($salida);
        $esquina = imagecolorat($imagen, 0, 0);
        $alfa = ($esquina >> 24) & 0x7F;

        // 127 es completamente transparente.
        $this->assertSame(127, $alfa);

        imagedestroy($imagen);
        @unlink($salida);
    }

    public function test_no_convierte_de_formato(): void
    {
        // Un PNG sigue siendo PNG. Convertirlo a JPEG aplanaría cualquier
        // transparencia, y además Instagram sólo acepta JPEG y PNG: cambiar a
        // WebP rompería la publicación.
        $salida = ImageCompressor::compress($this->pngTransparente(2000, 2000));

        $this->assertSame(IMAGETYPE_PNG, getimagesize($salida)[2]);

        @unlink($salida);
    }

    public function test_un_comprobante_se_guarda_mas_grande_para_poder_leerlo(): void
    {
        /*
         * Un pantallazo de la app del banco a 1600 de alto deja el ancho en
         * 739, y ahí los números quedan al límite de legibles. Un comprobante
         * que no se puede leer no es evidencia de nada, y el cierre del día
         * vuelve a cuadrar contra lo que alguien dijo que entró.
         */
        $pantallazo = $this->jpeg(1170, 2532);

        $normal = ImageCompressor::compress($pantallazo);
        $documento = ImageCompressor::compress($pantallazo, ImageCompressor::MAX_EDGE_DOCUMENT);

        $this->assertSame(ImageCompressor::MAX_EDGE, getimagesize($normal)[1]);
        $this->assertSame(ImageCompressor::MAX_EDGE_DOCUMENT, getimagesize($documento)[1]);

        @unlink($normal);
        @unlink($documento);
    }

    public function test_lo_que_no_es_imagen_se_guarda_tal_cual(): void
    {
        // Null significa "guarda el original", no "falló".
        $archivo = UploadedFile::fake()->create('contrato.pdf', 10, 'application/pdf');

        $this->assertNull(ImageCompressor::compress($archivo));
    }

    public function test_una_imagen_enorme_no_se_intenta_y_no_revienta(): void
    {
        /*
         * GD descomprime a memoria: 4 bytes por píxel. Una imagen de 48 MP
         * —el modo de un iPhone reciente— son 192 MB antes de tocar nada, y
         * con el `memory_limit` por defecto eso es un OOM que se lleva la
         * request entera. Por encima del tope se guarda el original.
         */
        $salida = ImageCompressor::compress($this->jpegFalsoEnorme());

        $this->assertNull($salida);
    }

    /*
    |--------------------------------------------------------------------------
    | Ayudas
    |--------------------------------------------------------------------------
    */

    /** Un JPEG con algo de ruido: uno de color plano comprime a nada y no prueba nada. */
    private function jpeg(int $ancho, int $alto): UploadedFile
    {
        $imagen = imagecreatetruecolor($ancho, $alto);

        for ($i = 0; $i < 4000; $i++) {
            imagefilledellipse(
                $imagen,
                random_int(0, $ancho),
                random_int(0, $alto),
                random_int(10, 160),
                random_int(10, 160),
                imagecolorallocate($imagen, random_int(0, 255), random_int(0, 255), random_int(0, 255)),
            );
        }

        $ruta = tempnam(sys_get_temp_dir(), 'nxtest').'.jpg';
        imagejpeg($imagen, $ruta, 100);
        imagedestroy($imagen);

        return new UploadedFile($ruta, 'foto.jpg', 'image/jpeg', null, true);
    }

    private function pngTransparente(int $ancho, int $alto): UploadedFile
    {
        $imagen = imagecreatetruecolor($ancho, $alto);
        imagealphablending($imagen, false);
        imagesavealpha($imagen, true);
        imagefill($imagen, 0, 0, imagecolorallocatealpha($imagen, 0, 0, 0, 127));

        // Algo opaco en el centro, para que no sea un PNG entero vacío.
        imagefilledellipse($imagen, $ancho / 2, $alto / 2, $ancho / 2, $alto / 2, imagecolorallocate($imagen, 90, 60, 200));

        $ruta = tempnam(sys_get_temp_dir(), 'nxtest').'.png';
        imagepng($imagen, $ruta);
        imagedestroy($imagen);

        return new UploadedFile($ruta, 'logo.png', 'image/png', null, true);
    }

    /**
     * Un JPEG que DICE ser enorme sin serlo.
     *
     * Generar 48 MP de verdad para probar el tope gastaría los 192 MB que la
     * guarda existe para no gastar. Se falsifica el encabezado: es lo que
     * `getimagesize` lee, y es lo único que la guarda mira.
     */
    private function jpegFalsoEnorme(): UploadedFile
    {
        $imagen = imagecreatetruecolor(64, 64);
        $ruta = tempnam(sys_get_temp_dir(), 'nxtest').'.jpg';
        imagejpeg($imagen, $ruta, 90);
        imagedestroy($imagen);

        $bytes = file_get_contents($ruta);

        // SOF0: FF C0, largo, precision, alto (2 bytes), ancho (2 bytes).
        $sof = strpos($bytes, "\xFF\xC0");
        $bytes = substr_replace($bytes, pack('nn', 8000, 8000), $sof + 5, 4);

        file_put_contents($ruta, $bytes);

        return new UploadedFile($ruta, 'enorme.jpg', 'image/jpeg', null, true);
    }
}
