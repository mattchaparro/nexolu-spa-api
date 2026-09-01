<?php

namespace App\Console\Commands;

use App\Models\Business;
use App\Services\Messaging\ReminderService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Prepara los recordatorios de las citas que vienen.
 *
 * "Prepara" y no "manda": en modo manual los deja en la bandeja para que una
 * persona los envíe, y en automático salen solos. El comando no sabe la
 * diferencia y no tiene por qué -- eso lo decide `MessageDispatcher`.
 *
 * IDEMPOTENTE. Correrlo dos veces seguidas no manda nada dos veces: lo impide
 * el índice único de `messages`. Eso es lo que permite programarlo cada 15
 * minutos sin miedo, y lo que hace que una corrida perdida se recupere sola en
 * la siguiente.
 */
class SendReminders extends Command
{
    protected $signature = 'recordatorios:preparar
                            {--business= : Solo este negocio, por id}
                            {--dry-run : Muestra a quién le tocaría, sin preparar nada}';

    protected $description = 'Prepara los recordatorios de las citas próximas';

    public function handle(ReminderService $reminders): int
    {
        $negocios = Business::query()
            ->where('is_active', true)
            ->when($this->option('business'), fn ($q, $id) => $q->whereKey($id))
            ->get();

        $totalQueued = 0;
        $totalSkipped = 0;

        foreach ($negocios as $business) {
            if (! $business->hasFeature('reminders')) {
                continue;
            }

            if ($this->option('dry-run')) {
                $citas = $reminders->due(
                    $business,
                    CarbonImmutable::now($business->businessTimezone()),
                    (int) $business->schedulingSetting('reminder_hours_before'),
                );

                foreach ($citas as $cita) {
                    $this->line(sprintf(
                        '  %s · %s · %s',
                        $business->name,
                        $cita->starts_at?->setTimezone($business->businessTimezone())->format('Y-m-d H:i'),
                        $cita->client_name ?? 'sin nombre',
                    ));
                }

                $totalQueued += $citas->count();

                continue;
            }

            $resultado = $reminders->run($business);

            $totalQueued += $resultado['queued'];
            $totalSkipped += $resultado['skipped'];

            if ($resultado['queued'] > 0) {
                $this->line("{$business->name}: {$resultado['queued']} recordatorio(s).");
            }
        }

        /*
         * Los omitidos se reportan aparte y no como error.
         *
         * Casi siempre son citas sin teléfono, que es normalísimo -- alguien
         * se agendó por el mostrador y nadie lo anotó. Contarlos como fallos
         * haría que el comando se vea rojo todos los días y que nadie mire la
         * salida cuando de verdad falle algo.
         */
        $this->info($this->option('dry-run')
            ? "Le tocaría a {$totalQueued} cita(s)."
            : "Listos: {$totalQueued}. Sin teléfono o ya avisados: {$totalSkipped}.");

        return self::SUCCESS;
    }
}
