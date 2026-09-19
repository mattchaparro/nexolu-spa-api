<?php

namespace App\Ai;

use Carbon\CarbonInterface;

/**
 * La hora como la dice una persona, no como la guarda una base de datos.
 *
 * El agente escribía "tengo a las 10:00, 11:00, 12:00, 13:00…" y eso no es
 * como habla nadie en Colombia: se dice "10 de la mañana" o "1 de la tarde".
 * Una lista en formato 24h obliga a la clienta a traducir mentalmente cada
 * hora, y lo que se buscaba era que agendar fuera fácil.
 *
 * Las herramientas devuelven las dos: `hora` para MOSTRAR y `hora_24` para
 * volver a llamarlas (crear_cita pide H:i). Que el modelo no tenga que
 * convertir nada es justo lo que evita que invente una hora que no existe.
 */
final class HoraLegible
{
    public static function de(CarbonInterface $momento, string $tz): string
    {
        return self::deTexto($momento->setTimezone($tz)->format('H:i'));
    }

    /** @param string $hora24 en formato H:i */
    public static function deTexto(string $hora24): string
    {
        [$h, $m] = array_map('intval', explode(':', $hora24));

        $doce = $h % 12;
        $doce = $doce === 0 ? 12 : $doce;
        $minutos = $m === 0 ? '' : ':'.str_pad((string) $m, 2, '0', STR_PAD_LEFT);

        return $doce.$minutos.' '.($h < 12 ? 'am' : 'pm');
    }
}
