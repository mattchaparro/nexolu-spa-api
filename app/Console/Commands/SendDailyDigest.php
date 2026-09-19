<?php

namespace App\Console\Commands;

use App\Models\Business;
use App\Services\Messaging\DailyDigestService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * El resumen del día, por correo, a quien es dueño del negocio.
 *
 * Corre una vez al final del día. No es idempotente por índice como los
 * recordatorios -- un resumen no es un mensaje a una clienta --, así que la
 * protección es el horario: si alguien lo corre dos veces llegan dos
 * correos iguales, lo cual es molesto pero inofensivo.
 */
class SendDailyDigest extends Command
{
    protected $signature = 'resumen:diario
                            {--business= : Solo este negocio, por id}
                            {--fecha= : El día a resumir (AAAA-MM-DD), por defecto hoy}
                            {--dry-run : Muestra el texto sin mandar nada}';

    protected $description = 'Manda a cada dueño el resumen del día por correo';

    public function handle(DailyDigestService $digest): int
    {
        $negocios = Business::query()
            ->where('is_active', true)
            ->when($this->option('business'), fn ($q, $id) => $q->whereKey($id))
            ->get();

        $enviados = 0;

        foreach ($negocios as $business) {
            $dia = $this->option('fecha')
                ? CarbonImmutable::parse($this->option('fecha'), $business->businessTimezone())
                : null;

            if ($this->option('dry-run')) {
                $texto = $digest->compose($business, $dia);
                $this->line("== {$business->name} ==");
                $this->line($texto ?? '(nada que contar hoy)');
                $this->line('Para: '.(implode(', ', $digest->recipients($business)) ?: '(sin dueños con correo)'));

                continue;
            }

            if ($digest->sendFor($business, $dia)) {
                $enviados++;
            }
        }

        if (! $this->option('dry-run')) {
            $this->info("Resúmenes enviados: {$enviados}");
        }

        return self::SUCCESS;
    }
}
