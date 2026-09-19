<?php

namespace App\Support;

/**
 * Un nombre de servicio que quepa en una fila de WhatsApp.
 *
 * Meta corta los titulos en 24 caracteres, y cortar a lo bruto deja
 * cosas como «Press-On + Semipermanent» o «Recubrimiento Acrigel + »:
 * la primera parece un error de ortografia y la segunda parece que el
 * mensaje se rompio a la mitad. En una lista que la clienta va a TOCAR
 * para elegir, eso es exactamente lo que la hace dudar.
 *
 * Asi que se corta un caracter antes, se limpian los separadores que
 * queden colgando y se cierra con puntos suspensivos, que es la senal
 * de "hay mas" que todo el mundo entiende. «Recubrimiento Acrigel + »
 * queda «Recubrimiento Acrigel…», que se lee como un nombre.
 */
final class TituloCorto
{
    /** Tope de Meta para el titulo de una fila de lista. */
    private const MAXIMO = 24;

    public static function de(string $nombre, int $maximo = self::MAXIMO): string
    {
        $nombre = trim($nombre);

        if (mb_strlen($nombre) <= $maximo) {
            return $nombre;
        }

        // Un caracter menos: los puntos suspensivos ocupan uno.
        $corte = rtrim(mb_substr($nombre, 0, $maximo - 1), " \t+-/·,;:&");

        // Si de tanto limpiar no queda nada (un nombre que empieza con
        // separadores), vale mas el corte crudo que una fila vacia.
        if ($corte === '') {
            $corte = mb_substr($nombre, 0, $maximo - 1);
        }

        return $corte.'…';
    }
}
