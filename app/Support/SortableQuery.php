<?php

namespace App\Support;

/**
 * Resuelve un par sort/direction de request contra un mapa fijo de columnas
 * permitidas - nunca hay que interpolar el nombre de columna que manda el
 * cliente directo en un orderBy() (SQL injection). Si `sort` no viene o no
 * esta en el mapa, devuelve null y el caller debe aplicar el orderBy por
 * defecto de siempre (compatibilidad hacia atras, ningun cliente viejo se
 * rompe por no mandar estos dos parametros nuevos).
 */
class SortableQuery
{
    /**
     * @param  array<string, string>  $allowed  Mapa clave publica (la que manda el frontend) => columna real de la tabla.
     * @return array{0: string, 1: string}|null [columna, direction] listo para orderBy(), o null.
     */
    public static function resolve(?string $sort, ?string $direction, array $allowed): ?array
    {
        if ($sort === null || $sort === '' || ! isset($allowed[$sort])) {
            return null;
        }

        $normalizedDirection = strtolower((string) $direction) === 'asc' ? 'asc' : 'desc';

        return [$allowed[$sort], $normalizedDirection];
    }
}
