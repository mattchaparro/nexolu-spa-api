<?php

namespace App\Ai;

use Carbon\CarbonImmutable;

/**
 * La fecha como la dice la gente, resuelta por código.
 *
 * "el lunes", "mañana", "pasado mañana", "el próximo jueves". Calcular
 * eso parece trivial y el modelo lo hace mal: pidiendo "el lunes" buscó
 * el martes 22 y le ofreció a la clienta horas de otro día. Una hora
 * equivocada no es un detalle -- es alguien que llega al local cuando no
 * lo esperan.
 *
 * Por eso la cuenta la hace PHP, que sabe qué día es hoy y en qué zona
 * horaria vive el negocio, y al modelo solo le queda repetir lo que le
 * dijeron.
 */
final class FechaDicha
{
    private const DIAS = [
        'lunes' => CarbonImmutable::MONDAY,
        'martes' => CarbonImmutable::TUESDAY,
        'miercoles' => CarbonImmutable::WEDNESDAY,
        'jueves' => CarbonImmutable::THURSDAY,
        'viernes' => CarbonImmutable::FRIDAY,
        'sabado' => CarbonImmutable::SATURDAY,
        'domingo' => CarbonImmutable::SUNDAY,
    ];

    /**
     * @param  string  $texto  "2026-09-21", "mañana", "el lunes", "próximo jueves"
     * @return CarbonImmutable|null null = no se entendió; pregúntale a la clienta
     */
    public static function resolver(string $texto, string $tz, ?CarbonImmutable $hoy = null): ?CarbonImmutable
    {
        $hoy ??= CarbonImmutable::now($tz);
        $hoy = $hoy->setTimezone($tz)->startOfDay();

        $limpio = self::normalizar($texto);

        if ($limpio === '') {
            return null;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $limpio) === 1) {
            return CarbonImmutable::createFromFormat('!Y-m-d', $limpio, $tz);
        }

        foreach (['hoy' => 0, 'manana' => 1, 'pasado manana' => 2] as $palabra => $dias) {
            if ($limpio === $palabra || str_starts_with($limpio, $palabra.' ')) {
                return $hoy->addDays($dias);
            }
        }

        foreach (self::DIAS as $nombre => $numero) {
            if (! str_contains($limpio, $nombre)) {
                continue;
            }

            $siguiente = $hoy->next($numero);

            /*
             * "el próximo lunes" dicho un domingo es el de MAÑANA para
             * unos y el de dentro de ocho días para otros. Se toma el más
             * cercano: si la clienta quería el otro, lo dice, y perder un
             * mensaje es mejor que agendarla con una semana de diferencia.
             *
             * Excepción: "en ocho" o "de la otra semana" sí empujan.
             */
            if (str_contains($limpio, 'en ocho') || str_contains($limpio, 'otra semana')) {
                return $siguiente->addWeek();
            }

            return $siguiente;
        }

        return null;
    }

    private static function normalizar(string $texto): string
    {
        $sinTildes = strtr(mb_strtolower(trim($texto)), [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n',
        ]);

        return preg_replace('/\s+/', ' ', $sinTildes) ?? '';
    }
}
