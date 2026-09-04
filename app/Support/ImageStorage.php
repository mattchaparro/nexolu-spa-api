<?php

namespace App\Support;

use Illuminate\Http\File;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Guarda y borra las imagenes del producto.
 *
 * Un solo punto que sabe en que disco viven. En produccion es Spaces; en local
 * cae al disco publico del proyecto si no hay credenciales, para que nadie
 * necesite una cuenta de nube solo para levantar el entorno.
 */
class ImageStorage
{
    /** Extensiones permitidas. Nada de SVG: admite scripts embebidos. */
    public const ALLOWED = ['jpg', 'jpeg', 'png', 'webp'];

    public const MAX_KB = 4096;

    public static function disk(): string
    {
        return config('filesystems.disks.spaces.key') ? 'spaces' : 'public';
    }

    /**
     * Guarda una imagen bajo una carpeta propia del negocio, ya comprimida.
     *
     * El prefijo por negocio importa: aunque el bucket sea compartido, dos
     * negocios nunca escriben en la misma ruta, y borrar uno no puede tocar
     * los archivos de otro.
     *
     * LA COMPRESION VA ACA y no en cada controlador porque este es el UNICO
     * punto por el que entra una imagen al sistema. Un camino que guardara por
     * su cuenta seria una foto de cuatro megas sirviendose desde el droplet, y
     * -- peor -- con las coordenadas GPS del celular todavia adentro.
     */
    public static function store(
        UploadedFile $file,
        int $businessId,
        string $folder,
        // Lo que se LEE -- un comprobante de transferencia -- se guarda mas
        // grande que lo que se mira. Ver ImageCompressor::MAX_EDGE_DOCUMENT.
        int $maxEdge = ImageCompressor::MAX_EDGE,
    ): string {
        $name = Str::uuid()->toString().'.'.strtolower($file->getClientOriginalExtension());
        $path = "negocios/{$businessId}/{$folder}/{$name}";

        $comprimida = ImageCompressor::compress($file, $maxEdge);

        Storage::disk(self::disk())->putFileAs(
            dirname($path),
            $comprimida === null ? $file : new File($comprimida),
            basename($path),
            'public',
        );

        // El temporal se borra siempre, salga bien o mal el guardado: son
        // megas por foto en el disco de un droplet que tiene poco.
        if ($comprimida !== null) {
            @unlink($comprimida);
        }

        return $path;
    }

    public static function delete(?string $path): void
    {
        if ($path === null || $path === '') {
            return;
        }

        // Un borrado fallido no debe tumbar la operacion que lo pidio: dejar
        // un archivo huerfano es preferible a impedir que alguien edite un
        // servicio porque el almacenamiento esta caido.
        try {
            Storage::disk(self::disk())->delete($path);
        } catch (\Throwable $e) {
            logger()->warning('No se pudo borrar la imagen', ['path' => $path, 'error' => $e->getMessage()]);
        }
    }

    /**
     * El ancho y el alto de algo ya guardado, o null si no se pudo medir.
     *
     * Existe para comprobar contra los limites de Instagram, que rechaza por
     * proporcion. Se mide el ARCHIVO GUARDADO y no lo que se supo al subirlo:
     * entre medio paso el compresor, que pudo rotarlo por su EXIF -- y una
     * foto rotada tiene la proporcion al reves.
     *
     * En el disco local se lee la ruta y no el contenido: medir diez imagenes
     * de un carrusel no deberia costar leerlas enteras. Contra un bucket no
     * hay ruta, y ahi si toca traer los bytes.
     *
     * @return array{0: int, 1: int}|null
     */
    public static function dimensions(?string $path): ?array
    {
        if ($path === null || $path === '') {
            return null;
        }

        $disk = Storage::disk(self::disk());

        try {
            if (self::disk() === 'public') {
                $info = @getimagesize($disk->path($path));
            } else {
                $bytes = $disk->get($path);
                $info = $bytes === null ? false : @getimagesizefromstring($bytes);
            }
        } catch (\Throwable $e) {
            logger()->warning('No se pudo medir la imagen', ['path' => $path, 'error' => $e->getMessage()]);

            return null;
        }

        return $info === false ? null : [(int) $info[0], (int) $info[1]];
    }

    public static function url(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        return Storage::disk(self::disk())->url($path);
    }

    /** Reglas de validacion, para no repetirlas en cada Request. */
    public static function rules(bool $required = false): array
    {
        return array_filter([
            $required ? 'required' : 'nullable',
            'image',
            'mimes:'.implode(',', self::ALLOWED),
            'max:'.self::MAX_KB,
        ]);
    }
}
