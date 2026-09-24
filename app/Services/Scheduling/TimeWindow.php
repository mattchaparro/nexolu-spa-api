<?php

namespace App\Services\Scheduling;

use Carbon\CarbonImmutable;

/**
 * Un intervalo semiabierto [start, end). Semiabierto a proposito: una cita que
 * termina 10:00 y otra que empieza 10:00 no se solapan.
 */
final class TimeWindow
{
    public function __construct(
        public readonly CarbonImmutable $start,
        public readonly CarbonImmutable $end,
    ) {}

    public function overlaps(self $other): bool
    {
        return $this->start < $other->end && $other->start < $this->end;
    }

    public function contains(self $other): bool
    {
        return $other->start >= $this->start && $other->end <= $this->end;
    }

    public function durationMinutes(): int
    {
        return (int) $this->start->diffInMinutes($this->end);
    }

    /**
     * Resta otro intervalo, devolviendo los pedazos que sobreviven. Un corte
     * en la mitad produce dos ventanas; uno que cubre todo, ninguna.
     *
     * @return list<self>
     */
    public function subtract(self $cut): array
    {
        if (! $this->overlaps($cut)) {
            return [$this];
        }

        $pieces = [];

        if ($cut->start > $this->start) {
            $pieces[] = new self($this->start, $cut->start);
        }

        if ($cut->end < $this->end) {
            $pieces[] = new self($cut->end, $this->end);
        }

        return $pieces;
    }

    /**
     * Junta las que se pisan o se tocan en una sola.
     *
     * Hace falta porque el horario de alguien puede venir partido en varias
     * filas que se solapan -- Alejandra tenía el viernes cargado tres veces:
     * 09:00-17:00, 09:00-15:00 y 15:00-17:00 --, y sin juntarlas cada una se
     * recorta por su lado. El almuerzo restado a tres ventanas encimadas
     * deja pedazos que no son la jornada de nadie, y la tarde se perdía.
     *
     * Se juntan también las que solo se TOCAN (09:00-15:00 con 15:00-17:00):
     * para quien trabaja son un mismo turno seguido, y tratarlas aparte
     * impide ofrecer un servicio que cruce las tres de la tarde.
     *
     * @param  list<self>  $windows
     * @return list<self>
     */
    public static function mergeAll(array $windows): array
    {
        if ($windows === []) {
            return [];
        }

        usort($windows, fn (self $a, self $b) => $a->start <=> $b->start);

        $merged = [array_shift($windows)];

        foreach ($windows as $window) {
            $ultima = $merged[count($merged) - 1];

            if ($window->start > $ultima->end) {
                $merged[] = $window;

                continue;
            }

            $merged[count($merged) - 1] = new self(
                $ultima->start,
                $window->end > $ultima->end ? $window->end : $ultima->end,
            );
        }

        return $merged;
    }

    /**
     * @param  list<self>  $windows
     * @param  list<self>  $cuts
     * @return list<self>
     */
    public static function subtractAll(array $windows, array $cuts): array
    {
        foreach ($cuts as $cut) {
            $next = [];
            foreach ($windows as $window) {
                foreach ($window->subtract($cut) as $piece) {
                    $next[] = $piece;
                }
            }
            $windows = $next;
        }

        return $windows;
    }
}
