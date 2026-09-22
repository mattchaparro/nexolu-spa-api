<?php

namespace App\Console\Commands;

use App\Models\Business;
use App\Services\Messaging\RetouchReminderService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * "Ya casi te toca retoque": el mensaje que trae de vuelta a la clienta.
 *
 * Corre cada hora y cada negocio decide a qué hora LOCAL sale el suyo
 * (`retouch_reminder_hour`, 10 de la mañana por defecto). Un solo cron
 * para todos los husos, sin programar una tarea por negocio.
 *
 * IDEMPOTENTE, como los recordatorios de cita: dos corridas no mandan dos
 * veces -- lo impide el índice único de `messages` (appointment_id, kind).
 */
class SendRetouchReminders extends Command
{
    protected $signature = 'retoques:recordar
                            {--business= : Solo este negocio, por id}
                            {--ahora : Ignora la hora configurada y corre ya}
                            {--dry-run : Muestra a quién le tocaría, sin preparar nada}';

    protected $description = 'Prepara los recordatorios de retoque de quienes ya cumplieron los días de su servicio';

    public function handle(RetouchReminderService $retoques): int
    {
        $negocios = Business::query()
            ->where('is_active', true)
            ->when($this->option('business'), fn ($q, $id) => $q->whereKey($id))
            ->get();

        $preparados = 0;

        foreach ($negocios as $business) {
            $tz = $business->businessTimezone();
            $ahora = CarbonImmutable::now($tz);
            $hora = (int) $business->schedulingSetting('retouch_reminder_hour');

            // La hora del negocio, no la del servidor: un salón en otro huso
            // no puede recibir su mensaje de las 10 am a las 5 am.
            if (! $this->option('ahora') && $ahora->hour !== $hora) {
                continue;
            }

            if ($this->option('dry-run')) {
                foreach ($retoques->due($business, $ahora) as $cita) {
                    $servicio = $cita->items->first(fn ($i) => $i->service !== null)?->service?->name ?? '¿?';
                    $this->line(sprintf(
                        '  %s · %s · última visita %s',
                        $cita->client?->fullName() ?? $cita->client_name ?? '¿?',
                        $servicio,
                        $cita->starts_at?->setTimezone($tz)->format('Y-m-d'),
                    ));
                    $preparados++;
                }

                continue;
            }

            ['queued' => $queued, 'skipped' => $skipped] = $retoques->run($business, $ahora);
            $preparados += $queued;

            if ($queued > 0 || $skipped > 0) {
                $this->line("  {$business->name}: {$queued} preparados, {$skipped} omitidos");
            }
        }

        $this->info("Retoques: {$preparados}.");

        return self::SUCCESS;
    }
}
