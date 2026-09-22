<?php

namespace App\Ai;

/**
 * ¿Este nombre parece el de una persona, o es el perfil de WhatsApp?
 *
 * El nombre de la ficha muchas veces viene del perfil de WhatsApp, y ahí
 * la gente se pone «.», «🦋 Princesa 🦋», «Nails•Spa», su negocio o su
 * carro. Saludarla con eso queda ridículo, y peor: el bot cree que ya
 * sabe el nombre y no lo pregunta, así que la cita queda a nombre de un
 * emoji.
 *
 * La regla es conservadora: ante la duda, el nombre vale. Solo se marca
 * raro lo que claramente no es un nombre — vacío, sin letras, de una
 * letra, con dígitos o con símbolos que ningún nombre trae. "Aleja" y
 * "Ana" pasan; «.» y «🦋» no.
 */
final class NombreRaro
{
    public static function es(?string $nombre): bool
    {
        $nombre = trim((string) $nombre);

        if ($nombre === '' || mb_strlen($nombre) < 2) {
            return true;
        }

        // Sin al menos dos letras seguidas no hay nombre.
        if (! preg_match('/\p{L}\p{L}/u', $nombre)) {
            return true;
        }

        // Dígitos, emojis o símbolos: eso no lo trae un nombre de persona.
        // Se permiten letras, espacios, apóstrofos, puntos y guiones
        // ("D'Alessandro", "Ana-María", "J. Pablo").
        if (preg_match("/[^\p{L}\p{M}\s'.\-]/u", $nombre)) {
            return true;
        }

        return false;
    }
}
