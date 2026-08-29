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
