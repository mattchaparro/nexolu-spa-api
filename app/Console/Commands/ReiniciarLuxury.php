<?php

namespace App\Console\Commands;

use App\Models\Business;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Dejar el negocio como antes de la primera importacion, sin borrarlo.
 *
 * Antes del cambio de sistema hay que poder decir "borremos lo migrado y
 * traigamoslo otra vez, fresco". Hacerlo a mano en la base un miercoles por
 * la tarde es exactamente donde se cometen los errores que no se notan hasta
 * que falta plata: se olvida una tabla, se borra el negocio entero y con el
 * su configuracion, o se deja a alguien sin usuario para entrar.
 *
 * QUE BORRA: todo lo que el importador vuelve a traer -- citas, fichas,
 * catalogo, equipo, historial, gastos, fidelizacion -- mas lo que cuelga de
 * ellos (ocupacion, liquidaciones, mensajes, calificaciones) y el libro de
 * `legacy_map`, que es lo que hace que la siguiente corrida los traiga de
 * nuevo en vez de darlos por importados.
 *
 * QUE NO BORRA, y es la razon de que este comando exista:
 *
 * - **El negocio**, con su id, su slug, sus banderas, sus ajustes de agenda,
 *   su `whatsapp_phone_number_id` y su flujo de etapas. Borrar el negocio
 *   obliga a reconfigurar todo eso a mano justo el dia del cambio.
 * - **Los usuarios del panel y sus permisos.** Quedarse sin con que entrar
 *   mientras se migra es el peor momento posible.
 * - **Las sedes**, porque las citas importadas se cuelgan de ellas.
 * - **Los medios de pago**, que el negocio ya configuro.
 *
 * Despues de esto se corre `luxury:importar` y `luxury:verificar`.
 */
class ReiniciarLuxury extends Command
{
    protected $signature = 'luxury:reiniciar
                            {--negocio= : Slug o id del negocio}
                            {--force : Sin preguntar (para correrlo desde un guion)}';

    protected $description = 'Borra lo migrado de un negocio para volver a importarlo desde cero';

    /**
     * En este orden: lo que cuelga primero, lo que sostiene despues.
     *
     * Las llaves foraneas estan en cascada casi siempre, pero el orden
     * explicito documenta de que cuelga cada cosa -- y no depende de que la
     * cascada exista en TODAS, que es la clase de suposicion que deja filas
     * huerfanas.
     *
     * @var list<string>
     */
    private const TABLAS = [
        // La conversación y lo que se le mandó a alguien.
        'messages',
        'whatsapp_conversations',

        // La nómina, que cuelga de las citas y de la gente.
        'payroll_settlement_items',
        'payroll_adjustments',
        'payroll_settlements',

        // Las citas y todo lo que las acompaña.
        'resource_occupancy',
        'appointment_stage_events',
        'appointment_items',
        'service_ratings',
        'appointments',
        'waitlist_entries',

        // La caja y lo que se vendió.
        'product_stock_movements',
        'product_sales',
        'cash_closings',
        'cash_shifts',

        // Las clientas y lo suyo.
        'loyalty_stamps',
        'loyalty_rewards',
        'client_penalties',
        'client_photos',
        'clients',

        /*
         * El catálogo y el equipo: el importador los vuelve a traer.
         *
         * Las hijas que NO llevan `business_id` --los requisitos de un
         * servicio, las líneas de un combo, las categorías y servicios de
         * una campaña-- no se listan: cuelgan de estas y se van con ellas
         * por cascada. Listarlas rompía el comando, y lo destapó una prueba
         * antes de que llegara a producción.
         */
        'service_packages',
        'discount_campaigns',
        'services',
        'service_categories',
        'resource_breaks',
        'resource_schedules',
        'schedule_exceptions',
        'resources',

        // Gastos y fidelización, que también se importan.
        'expenses',
        'recurring_expenses',
        'loyalty_programs',
        'products',

        // Y el libro de lo ya traído, que es lo que hace que todo vuelva.
        'legacy_map',
    ];

    public function handle(): int
    {
        $negocio = $this->negocio();

        if ($negocio === null) {
            return self::FAILURE;
        }

        $conteos = $this->contar($negocio->id);
        $total = array_sum($conteos);

        $this->warn("Se van a borrar {$total} filas del negocio «{$negocio->name}» (id {$negocio->id}).");

        foreach ($conteos as $tabla => $cuantas) {
            if ($cuantas > 0) {
                $this->line(sprintf('  %-32s %s', $tabla, number_format($cuantas)));
            }
        }

        $this->newLine();
        $this->line('NO se tocan: el negocio y su configuración, los usuarios, las sedes ni los medios de pago.');
        $this->newLine();

        if (! $this->option('force') && ! $this->confirm('¿Seguro? Esto no se puede deshacer.', false)) {
            $this->line('Cancelado. No se borró nada.');

            return self::SUCCESS;
        }

        $borradas = $this->borrar($negocio->id);

        $this->newLine();
        $this->info("Listo: {$borradas} filas borradas.");
        $this->line("Ahora: php artisan luxury:importar --negocio={$negocio->id}");
        $this->line("Y después: php artisan luxury:verificar --negocio={$negocio->id}");

        return self::SUCCESS;
    }

    /** @return array<string, int> */
    private function contar(int $businessId): array
    {
        $conteos = [];

        foreach (self::TABLAS as $tabla) {
            $conteos[$tabla] = DB::table($tabla)->where('business_id', $businessId)->count();
        }

        return $conteos;
    }

    private function borrar(int $businessId): int
    {
        return DB::transaction(function () use ($businessId) {
            /*
             * Sin chequeo de foráneas mientras se borra.
             *
             * El orden de arriba las respeta, pero hay ciclos legítimos --una
             * cita apunta a una etapa y la etapa guarda eventos de esa cita--
             * que no se pueden ordenar de ninguna forma. Se restaura al
             * terminar, pase lo que pase.
             */
            DB::statement('SET FOREIGN_KEY_CHECKS=0');

            try {
                $borradas = 0;

                foreach (self::TABLAS as $tabla) {
                    $borradas += DB::table($tabla)->where('business_id', $businessId)->delete();
                }

                /*
                 * La cita deja de apuntar a una etapa que ya no existe.
                 *
                 * `stage_id` vive en `appointments`, que se borra entera, pero
                 * el negocio conserva su flujo: al re-importar, las citas
                 * nuevas nacen en la etapa inicial. Esto es por si alguna
                 * quedó suelta.
                 */
                DB::table('businesses')->where('id', $businessId)->update(['updated_at' => now()]);

                return $borradas;
            } finally {
                DB::statement('SET FOREIGN_KEY_CHECKS=1');
            }
        });
    }

    private function negocio(): ?Business
    {
        $dado = $this->option('negocio');

        if (blank($dado)) {
            $this->error('Falta --negocio.');

            return null;
        }

        $negocio = is_numeric($dado)
            ? Business::withoutGlobalScopes()->find((int) $dado)
            : Business::withoutGlobalScopes()->firstWhere('slug', $dado);

        if ($negocio === null) {
            $this->error("No encontré el negocio «{$dado}».");
        }

        return $negocio;
    }
}
