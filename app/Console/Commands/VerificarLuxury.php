<?php

namespace App\Console\Commands;

use App\Models\Business;
use App\Services\Migration\Auditoria;
use App\Services\Migration\LecturaSolamente;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Compara las dos bases y dice en que NO se parecen.
 *
 *   php artisan luxury:verificar --negocio=luxury-nails
 *
 * Se corre DESPUES de cada importacion, y sobre todo antes de dejar que
 * alguien empiece a trabajar en la app nueva.
 *
 * Devuelve codigo 1 si algo no cuadra, para poder encadenarlo:
 *
 *   php artisan luxury:importar --negocio=X && php artisan luxury:verificar --negocio=X
 *
 * NO ARREGLA NADA. Arreglar es corregir el codigo y volver a correr el
 * importador, que es idempotente. Una auditoria que ademas repara es una
 * auditoria en la que uno deja de confiar.
 */
class VerificarLuxury extends Command
{
    protected $signature = 'luxury:verificar
        {--negocio= : Slug o id del negocio}
        {--todo : Mostrar tambien lo que esta bien}';

    protected $description = 'Compara la base migrada contra la del sistema viejo y reporta diferencias';

    public function handle(): int
    {
        $negocio = $this->negocio();

        if ($negocio === null) {
            return self::FAILURE;
        }

        try {
            DB::connection('legacy')->getPdo();
        } catch (Throwable $e) {
            $this->error('No se pudo conectar a la base vieja: '.$e->getMessage());

            return self::FAILURE;
        }

        LecturaSolamente::proteger('legacy');

        $auditoria = new Auditoria($negocio);
        $resultados = $auditoria->correr();

        $this->newLine();
        $this->line("  Auditoría de <options=bold>{$negocio->name}</>");
        $this->newLine();

        $grupo = null;

        foreach ($resultados as $r) {
            if (! $r['ok'] || $this->option('todo')) {
                if ($r['grupo'] !== $grupo) {
                    $grupo = $r['grupo'];
                    $this->line("  <options=bold>{$grupo}</>");
                }

                $informativo = $r['esperado'] === 'informativo';
                $marca = $informativo ? '<fg=blue>i</>' : ($r['ok'] ? '<fg=green>✓</>' : '<fg=red>✗</>');

                $this->line("    {$marca} {$r['que']}"
                    .($informativo ? ": <options=bold>{$r['obtenido']}</>" : ''));

                /*
                 * Un informativo SIEMPRE muestra su nota, aunque este en
                 * verde: existe justamente para contar algo que no es una
                 * falla pero que alguien tiene que saber.
                 */
                if ($informativo && $r['nota'] !== null) {
                    $this->line("        <fg=gray>{$r['nota']}</>");
                }

                if (! $r['ok'] && ! $informativo) {
                    $this->line("        esperado <fg=green>{$r['esperado']}</>, "
                        ."obtenido <fg=red>{$r['obtenido']}</>");

                    if ($r['nota'] !== null) {
                        $this->line("        <fg=gray>{$r['nota']}</>");
                    }
                }
            }
        }

        $fallas = $auditoria->fallas();
        $total = count($resultados);

        $this->newLine();

        if ($fallas === 0) {
            $this->line("  <fg=green>Las {$total} comprobaciones pasan.</>");

            return self::SUCCESS;
        }

        $this->line("  <fg=red>{$fallas} de {$total} comprobaciones no cuadran.</>");
        $this->line('  <fg=gray>Corregir el código y volver a correr `luxury:importar`: es idempotente,</>');
        $this->line('  <fg=gray>no hay que borrar nada.</>');

        return self::FAILURE;
    }

    private function negocio(): ?Business
    {
        $clave = $this->option('negocio');

        if (blank($clave)) {
            $this->error('Falta --negocio.');

            return null;
        }

        $negocio = Business::query()
            ->when(is_numeric($clave), fn ($q) => $q->where('id', (int) $clave))
            ->when(! is_numeric($clave), fn ($q) => $q->where('slug', $clave))
            ->first();

        if ($negocio === null) {
            $this->error("No existe un negocio con slug o id «{$clave}».");
        }

        return $negocio;
    }
}
