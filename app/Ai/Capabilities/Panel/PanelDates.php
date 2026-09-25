<?php

namespace App\Ai\Capabilities\Panel;

use App\Ai\AiArgumentException;
use App\Ai\FechaDicha;
use Carbon\CarbonImmutable;

/**
 * Las fechas que dice quien administra: «hoy», «el viernes», «esta semana»,
 * o una fecha escrita. Las resuelve el MISMO FechaDicha del bot de WhatsApp:
 * el modelo no calcula fechas (pidiendo «el lunes» llegó a buscar el martes).
 */
trait PanelDates
{
    /** @throws AiArgumentException */
    protected function dia(?string $texto, string $tz): CarbonImmutable
    {
        if ($texto === null || trim($texto) === '') {
            return CarbonImmutable::now($tz)->startOfDay();
        }

        return FechaDicha::resolver($texto, $tz)
            ?? throw new AiArgumentException("No entendí la fecha «{$texto}». Pregunta qué día.");
    }

    /**
     * Un rango: «esta semana», «este mes», «la semana pasada», o desde/hasta.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    protected function rango(?string $periodo, ?string $desde, ?string $hasta, string $tz): array
    {
        $hoy = CarbonImmutable::now($tz)->startOfDay();
        $p = mb_strtolower(trim((string) $periodo));

        return match (true) {
            str_contains($p, 'semana pasada') => [$hoy->subWeek()->startOfWeek(), $hoy->subWeek()->endOfWeek()->startOfDay()],
            str_contains($p, 'mes pasado') => [$hoy->subMonthNoOverflow()->startOfMonth(), $hoy->subMonthNoOverflow()->endOfMonth()->startOfDay()],
            str_contains($p, 'semana') => [$hoy->startOfWeek(), $hoy],
            str_contains($p, 'mes') => [$hoy->startOfMonth(), $hoy],
            str_contains($p, 'ano') || str_contains($p, 'año') => [$hoy->startOfYear(), $hoy],
            str_contains($p, 'ayer') => [$hoy->subDay(), $hoy->subDay()],
            str_contains($p, 'hoy') => [$hoy, $hoy],
            default => [$this->dia($desde, $tz), $hasta ? $this->dia($hasta, $tz) : ($desde ? $this->dia($desde, $tz) : $hoy)],
        };
    }
}
