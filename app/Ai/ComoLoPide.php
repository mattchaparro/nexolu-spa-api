<?php

namespace App\Ai;

use App\Models\Service;
use Illuminate\Support\Collection;

/**
 * Del español del local al catálogo.
 *
 * Nadie escribe "Recubrimiento Acrigel + Semipermanente". Escriben "las
 * manitos", "los pieses", "hacerme las uñas", "un retoque". El catálogo
 * no tiene esas palabras, así que el nombre no resolvía y el agente
 * terminaba preguntando «¿qué servicio quieres?» -- que le pide a la
 * clienta que adivine cómo lo llamamos nosotros. En las pruebas con
 * mensajes reales ahí es donde se cae la conversación: una señora que
 * escribió tres frases para pedir cita recibe un formulario.
 *
 * La traducción se apoya en las CATEGORÍAS y no en los nombres, porque
 * es ahí donde el local ya dijo qué es qué: "Manicure" agrupa
 * Semipermanente, Tradicional, Capping y veinte más -- ninguno tiene la
 * palabra "manicure" adentro. Buscar "manicure" dentro de los nombres no
 * encontraba nada. Buscarlo en la categoría encuentra los veintitrés, y
 * SOLO esos: quien pide manos no puede terminar viendo pestañas.
 *
 * Cuando además dice qué tipo ("un retoque de manos") se cruzan las dos
 * cosas y quedan los tres retoques de esa categoría, que es justo lo que
 * pidió.
 *
 * Es una tabla de palabras y no otra llamada al modelo a propósito:
 * "manitos" quiere decir lo mismo hoy y mañana, y no hay por qué pagar
 * tokens ni arriesgar una alucinación para traducirla.
 */
final class ComoLoPide
{
    /**
     * Palabras que dicen SIN DUDA de qué parte del cuerpo hablan.
     *
     * El valor se busca dentro del nombre de la categoría, no completo:
     * cada local bautiza sus grupos distinto ("Manicure", "Manos y uñas")
     * y lo que se repite es la raíz.
     *
     * @var array<string, string>
     */
    private const CATEGORIA_SEGURA = [
        'manicure' => 'manicur',
        'manicura' => 'manicur',
        'mano' => 'manicur',
        'manos' => 'manicur',
        'manito' => 'manicur',
        'manitos' => 'manicur',
        'maos' => 'manicur',
        'pedicure' => 'pedicur',
        'pedicura' => 'pedicur',
        'pie' => 'pedicur',
        'pies' => 'pedicur',
        'pieses' => 'pedicur',
        'patitas' => 'pedicur',
        'pestana' => 'pestan',
        'pestanas' => 'pestan',
        'pestanina' => 'pestan',
        'ceja' => 'ceja',
        'cejas' => 'ceja',
        'peinado' => 'peinad',
        'cepillado' => 'peinad',
        'combo' => 'combo',
    ];

    /**
     * Palabras que sugieren una categoría pero no mandan.
     *
     * "Las uñas" son casi siempre las de las manos, pero si en la misma
     * frase dijo "de los pies", manda lo que dijo. Por eso estas solo se
     * usan cuando ninguna palabra segura apareció.
     *
     * @var array<string, string>
     */
    private const CATEGORIA_PROBABLE = [
        'una' => 'manicur',
        'unas' => 'manicur',
        'esmalte' => 'manicur',
        'esmaltado' => 'manicur',
    ];

    /**
     * Qué tipo, dentro de la categoría. Esto SÍ vive en el nombre.
     *
     * @var array<string, list<string>>
     */
    private const EN_EL_NOMBRE = [
        'semi' => ['semi'],
        'semipermanente' => ['semi'],
        'permanente' => ['semi'],
        'tradicional' => ['tradicional'],
        'acrilico' => ['acrilic'],
        'acrilicas' => ['acrilic'],
        'acrilicos' => ['acrilic'],
        'esculpidas' => ['acrilic', 'extension'],
        'postizas' => ['acrilic', 'press-on'],
        'retoque' => ['retoque'],
        'retiro' => ['retiro'],
        'quitar' => ['retiro'],
        'rubber' => ['rubber'],
        'capping' => ['capping'],
        'acrigel' => ['acrigel'],
        'poligel' => ['poligel', 'acrigel'],
        'lifting' => ['lifting'],
        // "Para hombre" acota igual que un tipo: en este catalogo los de
        // hombre llevan la palabra en el nombre.
        'hombre' => ['hombre'],
        'hombres' => ['hombre'],
        'caballero' => ['hombre'],
        'caballeros' => ['hombre'],
        'henna' => ['henna'],
        'reparacion' => ['reparacion'],
        'restauracion' => ['restauracion'],
    ];

    /**
     * Palabras que, dichas solas, nombran UN servicio y no una familia.
     *
     * "Manos en semi" no pide cualquiera de los diez que llevan "semi" en
     * el nombre -- Retiro Semipermanente, Extensión Acrigel + Semi... --
     * pide el que se llama Semipermanente. Lo usa `elMasProbable`.
     *
     * @var array<string, string>
     */
    private const EXACTO = [
        'semi' => 'semipermanente',
        'semipermanente' => 'semipermanente',
        'permanente' => 'semipermanente',
        'tradicional' => 'tradicional',
    ];

    /**
     * Los servicios del catálogo que encajan con lo que dijo.
     *
     * Vacío significa que no se entendió: el que llama decide qué hacer
     * con eso (normalmente, decir qué SÍ hay). El catálogo que entra ya
     * viene filtrado -- activos y visibles en línea -- así que lo que el
     * local escondió no puede salir de acá.
     *
     * @param  Collection<int, Service>  $catalogo
     * @return Collection<int, Service>
     */
    public static function candidatos(Collection $catalogo, string $dicho): Collection
    {
        $palabras = self::palabras($dicho);

        $categoria = self::queCategoria($palabras);
        $enElNombre = self::queTipo($palabras);

        if ($categoria === null && $enElNombre === []) {
            return $catalogo->take(0);
        }

        $porCategoria = $categoria === null
            ? $catalogo
            : $catalogo->filter(
                fn (Service $s) => str_contains(self::normalizar((string) $s->category?->name), $categoria)
            );

        if ($enElNombre === []) {
            return $porCategoria->values();
        }

        /*
         * Si dijo VARIOS tipos ("semi con rubber"), primero los que tienen
         * TODOS: "Semi + Rubber" y nada mas. Con "cualquiera de los dos"
         * salian los diez que llevan "semi" en el nombre y la clienta
         * recibia una lista para elegir lo que ya habia dicho con todas
         * las letras. Si ninguno los tiene todos, vale cualquiera.
         */
        $conTodos = $porCategoria->filter(function (Service $s) use ($enElNombre) {
            $nombre = self::normalizar($s->name);

            foreach ($enElNombre as $pedazo) {
                if (! str_contains($nombre, $pedazo)) {
                    return false;
                }
            }

            return true;
        });

        $porNombre = $conTodos->isNotEmpty()
            ? $conTodos
            : $porCategoria->filter(function (Service $s) use ($enElNombre) {
                $nombre = self::normalizar($s->name);

                foreach ($enElNombre as $pedazo) {
                    if (str_contains($nombre, $pedazo)) {
                        return true;
                    }
                }

                return false;
            });

        /*
         * Si el tipo no existe dentro de la categoría ("un retoque de
         * pestañas" donde no hay retoques), se ofrece la categoría
         * entera antes que nada: es mejor que elija entre lo que hay a
         * que le digan que no hay nada.
         */
        return ($porNombre->isEmpty() ? $porCategoria : $porNombre)->values();
    }

    /**
     * El que más probablemente quiso, cuando no se le puede preguntar.
     *
     * Solo para cuando pide VARIOS servicios a la vez ("manos y pies en
     * semi"): una lista de WhatsApp pregunta una cosa, no dos, y mostrarle
     * la de manos se tragaba los pies -- pasó con Alejandro. Con UN solo
     * servicio se sigue mostrando la lista: elegir entre Semi y Semi +
     * Rubber es de ella, no nuestro.
     *
     * Primero el que se llama exactamente como lo dijo ("semi" en manos es
     * Semipermanente, no Retiro Semipermanente); si no hay, el más pedido,
     * que es el primero porque el catálogo ya llega ordenado así.
     *
     * @param  Collection<int, Service>  $candidatos
     */
    public static function elMasProbable(Collection $candidatos, string $dicho): ?Service
    {
        foreach (self::palabras($dicho) as $palabra) {
            $exacto = self::EXACTO[$palabra] ?? null;

            if ($exacto === null) {
                continue;
            }

            $uno = $candidatos->filter(fn (Service $s) => self::normalizar($s->name) === $exacto);

            if ($uno->count() === 1) {
                return $uno->first();
            }
        }

        return $candidatos->first();
    }

    /**
     * La categoría de la que está hablando, si la dijo.
     *
     * @param  list<string>  $palabras
     */
    private static function queCategoria(array $palabras): ?string
    {
        foreach ($palabras as $palabra) {
            if (isset(self::CATEGORIA_SEGURA[$palabra])) {
                return self::CATEGORIA_SEGURA[$palabra];
            }
        }

        foreach ($palabras as $palabra) {
            if (isset(self::CATEGORIA_PROBABLE[$palabra])) {
                return self::CATEGORIA_PROBABLE[$palabra];
            }
        }

        return null;
    }

    /**
     * Los pedazos de nombre que acotan dentro de la categoría.
     *
     * @param  list<string>  $palabras
     * @return list<string>
     */
    private static function queTipo(array $palabras): array
    {
        $pedazos = [];

        foreach ($palabras as $palabra) {
            foreach (self::EN_EL_NOMBRE[$palabra] ?? [] as $pedazo) {
                $pedazos[$pedazo] = true;
            }
        }

        return array_keys($pedazos);
    }

    /**
     * Palabra por palabra, porque casi nunca viene sola: "para arreglarse
     * las manitos" trae tres de relleno y una que importa.
     *
     * @return list<string>
     */
    private static function palabras(string $dicho): array
    {
        return array_values(array_filter(
            preg_split('/[^a-z0-9]+/', self::normalizar($dicho)) ?: [],
            fn (string $p) => $p !== '',
        ));
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
