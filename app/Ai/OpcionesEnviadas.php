<?php

namespace App\Ai;

use Illuminate\Support\Facades\Cache;

/**
 * Marca de "a esta persona ya le mandé las opciones en este turno".
 *
 * Existe porque hay herramientas que RESPONDEN por su cuenta (mandan los
 * botones con las horas libres), y el modelo después escribe igual su
 * texto. La clienta recibiría la lista y, debajo, las mismas horas otra
 * vez en palabras.
 *
 * El job lee esta marca al terminar y se calla si está puesta. Vive en
 * caché y no en la conversación porque es de UN turno: dura lo que dura
 * pensar la respuesta.
 */
final class OpcionesEnviadas
{
    private const TTL_SEGUNDOS = 180;

    public static function marcar(string $phone): void
    {
        Cache::put(self::clave($phone), true, self::TTL_SEGUNDOS);
    }

    /** Consulta Y limpia: la marca vale para este turno, no para el siguiente. */
    public static function consumir(string $phone): bool
    {
        return (bool) Cache::pull(self::clave($phone), false);
    }

    private static function clave(string $phone): string
    {
        return 'ia:opciones-enviadas:'.$phone;
    }
}
