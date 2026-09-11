<?php

namespace App\Console\Commands;

use App\Models\Business;
use App\Services\Expenses\GeneradorDeGastosFijos;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class GenerarGastosFijos extends Command
{
    protected $signature = 'gastos:fijos {--mes= : El mes a generar, YYYY-MM. Por defecto, el actual.}';

    protected $description = 'Pone en los libros los gastos que se repiten cada mes.';

    public function handle(GeneradorDeGastosFijos $generador): int
    {
        $total = 0;

        foreach (Business::withoutGlobalScopes()->where('is_active', true)->get() as $business) {
            /*
             * El mes en la zona del NEGOCIO.
             *
             * Con la del servidor, un negocio en Bogota veria aparecer el
             * arriendo de octubre a las 7 de la noche del 30 de septiembre.
             */
            $mes = $this->option('mes')
                ? CarbonImmutable::parse($this->option('mes').'-01', $business->businessTimezone())
                : CarbonImmutable::now($business->businessTimezone());

            $creados = $generador->paraElMes($business, $mes);
            $total += $creados;

            if ($creados > 0) {
                $this->info("{$business->name}: {$creados} gasto(s) fijo(s) de {$mes->format('Y-m')}.");
            }
        }

        if ($total === 0) {
            $this->line('Nada nuevo: los de este mes ya estaban.');
        }

        return self::SUCCESS;
    }
}
