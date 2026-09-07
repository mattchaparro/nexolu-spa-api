<?php

namespace App\Services\Migration;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * El cerrojo que impide escribirle a la base de la app vieja.
 *
 * La app legacy de Luxury sigue atendiendo clientas mientras dura la
 * migracion. Un UPDATE accidental desde el importador no danaria un respaldo:
 * danaria el local, en vivo, un sabado.
 *
 * El cerrojo de verdad es el GRANT de MySQL (usuario con SELECT y nada mas).
 * Este es el segundo, y esta aca porque el primero depende de que alguien lo
 * haya configurado bien en un servidor, y esto depende de que el codigo este
 * en el repositorio.
 *
 * Se engancha ANTES de ejecutar, no en el `listen()` de siempre: ese avisa
 * cuando la consulta ya corrio, que para esto no sirve de nada.
 */
class LecturaSolamente
{
    /** Lo unico que puede empezar una consulta contra el legacy. */
    private const PERMITIDO = ['select', 'show', 'describe', 'desc', 'explain', '('];

    public static function proteger(string $conexion = 'legacy'): void
    {
        DB::connection($conexion)->beforeExecuting(function (string $query) use ($conexion) {
            $inicio = strtolower(ltrim($query));

            foreach (self::PERMITIDO as $verbo) {
                if (str_starts_with($inicio, $verbo)) {
                    return;
                }
            }

            throw new RuntimeException(
                "La conexión `{$conexion}` es de solo lectura y se intentó ejecutar: "
                .mb_substr(trim($query), 0, 120)
            );
        });
    }
}
