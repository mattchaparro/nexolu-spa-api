<?php

namespace App\Ai;

use App\Models\Service;
use Illuminate\Support\Collection;

/**
 * Del español del local al nombre del catálogo.
 *
 * Nadie escribe "Manicure semipermanente". Escriben "las manitos", "los
 * pieses", "hacerme las uñas", "un retoque". El catálogo no tiene esas
 * palabras, así que el nombre no resolvía y el agente terminaba
 * preguntando «¿qué servicio quieres?» -- que le pide a la clienta que
 * adivine cómo lo llamamos nosotros. En las pruebas con mensajes reales
 * ahí es donde se cae la conversación: una señora que escribió tres
 * frases para pedir cita recibe un formulario.
 *
 * Acá no se adivina UN servicio: se reduce el catálogo a los que encajan
 * con lo que dijo. Si queda uno, ese es. Si quedan varios, el que decide
 * es ella -- pero eligiendo entre nombres de verdad, no inventando.
 *
 * Es una tabla de palabras y no una llamada más al modelo a propósito:
 * "manitos" quiere decir lo mismo hoy y mañana, y no hay por qué pagar
 * tokens ni arriesgar una alucinación para traducirla.
 */
final class ComoLoPide
{
    /**
     * Lo que dice la gente → lo que hay que buscar en el catálogo.
     *
     * Las llaves van normalizadas (sin tildes, en minúscula) porque así
     * llegan. Los valores son pedazos de nombre, no nombres completos:
     * cada local bautiza sus servicios distinto y "semipermanente" está
     * dentro de "Manicure semipermanente" y de "Semipermanente en pies".
     *
     * @var array<string, list<string>>
     */
    private const SINONIMOS = [
        'manito' => ['manicure', 'mano'],
        'manitos' => ['manicure', 'mano'],
        'manicura' => ['manicure', 'mano'],
        'mano' => ['manicure', 'mano'],
        'manos' => ['manicure', 'mano'],
        'pie' => ['pedicure', 'pie'],
        'pies' => ['pedicure', 'pie'],
        'pieses' => ['pedicure', 'pie'],
        'pedicura' => ['pedicure', 'pie'],
        'patitas' => ['pedicure', 'pie'],
        // "Las uñas" no dice cuál: puede ser cualquiera de los servicios
        // de uñas, y por eso casi siempre devuelve varios para que elija.
        'una' => ['manicure', 'semipermanente', 'acrilic', 'esculpid', 'una'],
        'unas' => ['manicure', 'semipermanente', 'acrilic', 'esculpid', 'una'],
        'uneros' => ['manicure', 'semipermanente', 'acrilic', 'esculpid', 'una'],
        'semi' => ['semipermanente'],
        'permanente' => ['semipermanente'],
        'retoque' => ['retoque', 'semipermanente', 'acrilic'],
        'acrilicas' => ['acrilic'],
        'esculpidas' => ['esculpid', 'acrilic'],
        'postizas' => ['acrilic', 'esculpid'],
        'cejas' => ['ceja'],
        'pestanas' => ['pestan'],
    ];

    /**
     * Los servicios del catálogo que encajan con lo que dijo.
     *
     * Vacío significa que no se entendió: el que llama decide qué hacer
     * con eso (normalmente, decir qué SÍ hay).
     *
     * @param  Collection<int, Service>  $catalogo
     * @return Collection<int, Service>
     */
    public static function candidatos(Collection $catalogo, string $dicho): Collection
    {
        $claves = self::queBusco($dicho);

        if ($claves === []) {
            return $catalogo->take(0);
        }

        return $catalogo->filter(function (Service $s) use ($claves) {
            $nombre = self::normalizar($s->name);

            foreach ($claves as $clave) {
                if (str_contains($nombre, $clave)) {
                    return true;
                }
            }

            return false;
        })->values();
    }

    /**
     * Los pedazos de nombre que hay que buscar, según lo que dijo.
     *
     * Se mira palabra por palabra porque casi nunca viene sola: "para
     * arreglarse las manitos" trae tres palabras de relleno y una que
     * importa.
     *
     * @return list<string>
     */
    private static function queBusco(string $dicho): array
    {
        $claves = [];

        foreach (preg_split('/[^a-z0-9]+/', self::normalizar($dicho)) ?: [] as $palabra) {
            foreach (self::SINONIMOS[$palabra] ?? [] as $clave) {
                $claves[$clave] = true;
            }
        }

        return array_keys($claves);
    }

    /** Sin tildes ni mayúsculas: la gente escribe como escribe. */
    private static function normalizar(string $texto): string
    {
        return strtr(
            mb_strtolower(trim($texto)),
            ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n'],
        );
    }
}
