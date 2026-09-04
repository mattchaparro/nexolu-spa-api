<?php

namespace App\Console\Commands;

use App\Models\Business;
use App\Services\Messaging\ServiceDoneReminder;
use Illuminate\Console\Command;

/**
 * Le avisa a quien atendio que su servicio termino y falta registrarlo.
 *
 * Cada 15 minutos, sobre una ventana abierta hacia atras: toma lo que ya
 * cumplio su hora, no lo que la cumple justo ahora. Una corrida perdida se
 * recupera sola en la siguiente en vez de dejar a alguien sin aviso para
 * siempre.
 *
 * IDEMPOTENTE por el indice unico de `messages`, no por una bandera. Correrlo
 * dos veces seguidas no le manda dos WhatsApp a nadie.
 *
 * Viene APAGADO para todo negocio: `service_done_reminder_min` arranca en 0.
 * Encenderlo por defecto seria mandarle mensajes a las empleadas de un
 * negocio, a su nombre, sin que nadie lo haya pedido.
 */
class RemindServiceDone extends Command
{
    protected $signature = 'servicios:recordar
                            {--business= : Solo este negocio, por id}
                            {--dry-run : Muestra a quién le tocaría, sin avisar}';

    protected $description = 'Avisa a quien atendió que su servicio terminó y falta registrarlo';

    public function handle(ServiceDoneReminder $reminders): int
    {
        $negocios = Business::query()
            ->where('is_active', true)
            ->when($this->option('business'), fn ($q, $id) => $q->whereKey($id))
            ->get();

        $avisados = 0;
        $omitidos = 0;

        foreach ($negocios as $business) {
            $minutos = $business->serviceDoneReminderMinutes();

            if ($minutos <= 0 || ! $business->hasFeature('reminders')) {
                continue;
            }

            if ($this->option('dry-run')) {
                $citas = $reminders->due($business, now($business->businessTimezone())->toImmutable(), $minutos);

                foreach ($citas as $cita) {
                    $item = $reminders->lastItem($cita);

                    $this->line(sprintf(
                        '  %s · %s · %s · %s',
                        $business->name,
                        $item?->resource?->name ?? 'sin recurso',
                        $item?->service?->name ?? 'sin servicio',
                        $reminders->pending($business, $item),
                    ));
                }

                $avisados += $citas->count();

                continue;
            }

            $resultado = $reminders->run($business);

            $avisados += $resultado['queued'];
            $omitidos += $resultado['skipped'];

            if ($resultado['queued'] > 0) {
                $this->line("{$business->name}: {$resultado['queued']} aviso(s).");
            }
        }

        /*
         * Los omitidos aparte y no como error. Casi siempre es una
         * profesional cuyo usuario no tiene telefono, que es normalisimo: el
         * pendiente igual le sale en "Mi dia". Contarlos como fallos haria
         * que el comando se vea rojo todos los dias y que nadie mire la
         * salida el dia que falle de verdad.
         */
        $this->info($this->option('dry-run')
            ? "Le tocaría a {$avisados} servicio(s)."
            : "Avisos: {$avisados}. Sin teléfono o ya avisados: {$omitidos}.");

        return self::SUCCESS;
    }
}
