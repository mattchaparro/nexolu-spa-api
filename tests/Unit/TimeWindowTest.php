<?php

namespace Tests\Unit;

use App\Services\Scheduling\TimeWindow;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class TimeWindowTest extends TestCase
{
    private function window(string $start, string $end): TimeWindow
    {
        return new TimeWindow(
            CarbonImmutable::parse("2026-09-16 {$start}", 'UTC'),
            CarbonImmutable::parse("2026-09-16 {$end}", 'UTC'),
        );
    }

    public function test_dos_intervalos_adyacentes_no_se_solapan(): void
    {
        // Semiabierto [start, end): una cita que termina 10:00 y otra que
        // empieza 10:00 conviven. Es la base de toda la logica de agenda.
        $this->assertFalse(
            $this->window('09:00', '10:00')->overlaps($this->window('10:00', '11:00')),
        );
    }

    public function test_un_solape_de_un_minuto_cuenta_como_solape(): void
    {
        $this->assertTrue(
            $this->window('09:00', '10:00')->overlaps($this->window('09:59', '11:00')),
        );
    }

    public function test_restar_por_el_medio_produce_dos_ventanas(): void
    {
        $pieces = $this->window('09:00', '13:00')->subtract($this->window('11:00', '12:00'));

        $this->assertCount(2, $pieces);
        $this->assertSame('09:00', $pieces[0]->start->format('H:i'));
        $this->assertSame('11:00', $pieces[0]->end->format('H:i'));
        $this->assertSame('12:00', $pieces[1]->start->format('H:i'));
        $this->assertSame('13:00', $pieces[1]->end->format('H:i'));
    }

    public function test_restar_algo_que_cubre_todo_no_deja_nada(): void
    {
        $this->assertSame([], $this->window('09:00', '13:00')->subtract($this->window('08:00', '14:00')));
    }

    public function test_restar_algo_disjunto_deja_la_ventana_intacta(): void
    {
        $pieces = $this->window('09:00', '13:00')->subtract($this->window('14:00', '15:00'));

        $this->assertCount(1, $pieces);
        $this->assertSame('09:00', $pieces[0]->start->format('H:i'));
    }

    public function test_subtract_all_aplica_varios_cortes_en_cadena(): void
    {
        $pieces = TimeWindow::subtractAll(
            [$this->window('09:00', '18:00')],
            [$this->window('11:00', '12:00'), $this->window('14:00', '15:00')],
        );

        $this->assertCount(3, $pieces);
        $this->assertSame('09:00', $pieces[0]->start->format('H:i'));
        $this->assertSame('12:00', $pieces[1]->start->format('H:i'));
        $this->assertSame('15:00', $pieces[2]->start->format('H:i'));
    }
}
