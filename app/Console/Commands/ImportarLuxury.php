<?php

namespace App\Console\Commands;

use App\Models\Business;
use App\Services\Migration\Importadores\ImportaCalificaciones;
use App\Services\Migration\Importadores\ImportaCatalogo;
use App\Services\Migration\Importadores\ImportaCitasFuturas;
use App\Services\Migration\Importadores\ImportaClientas;
use App\Services\Migration\Importadores\ImportaEquipo;
use App\Services\Migration\Importadores\ImportaFidelizacion;
use App\Services\Migration\Importadores\ImportaGastos;
use App\Services\Migration\Importadores\ImportaHistorial;
use App\Services\Migration\Importadores\ImportaMediosDePago;
use App\Services\Migration\Importadores\ImportaNomina;
use App\Services\Migration\Importadores\ImportaUsuarios;
use App\Services\Migration\Importadores\Importador;
use App\Services\Migration\LecturaSolamente;
use App\Services\Migration\LegacyMap;
use App\Services\Migration\Reporte;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Trae los datos de la app vieja del spa a este sistema.
 *
 * SE PUEDE CORRER TODOS LOS DIAS. Es la propiedad que manda el diseno: la
 * app vieja de Luxury no se apaga el dia de la mudanza, sigue atendiendo
 * clientas mientras el equipo se acostumbra. Asi que este comando se corre
 * hoy y trae todo el historico, y se corre manana y trae solo lo de hoy.
 *
 *   php artisan luxury:importar --negocio=luxury-nails --simular
 *   php artisan luxury:importar --negocio=luxury-nails
 *   php artisan luxury:importar --negocio=luxury-nails --paso=Historial
 *
 * SIN TRANSACCION, a proposito. Envolver 3.300 inserciones en una sola
 * transaccion tendria la tabla bloqueada varios minutos, y un fallo a mitad
 * de camino tirando todo el trabajo. Como cada fila se anota en `legacy_map`
 * en cuanto se crea, una corrida interrumpida se arregla volviendola a
 * correr: sigue donde iba.
 *
 * La conexion al legacy es de SOLO LECTURA y esta bloqueada por codigo
 * ademas de por el GRANT de MySQL. Ese sistema esta en produccion de verdad.
 */
class ImportarLuxury extends Command
{
    protected $signature = 'luxury:importar
        {--negocio= : Slug o id del negocio destino}
        {--simular : No escribe nada; solo dice que haria}
        {--paso=* : Correr solo estos pasos (por nombre)}';

    protected $description = 'Importa clientas, historial, catalogo y gastos desde la app vieja del spa';

    public function handle(): int
    {
        $negocio = $this->negocio();

        if ($negocio === null) {
            return self::FAILURE;
        }

        if (! $this->conectaAlLegacy()) {
            return self::FAILURE;
        }

        LecturaSolamente::proteger('legacy');

        $simular = (bool) $this->option('simular');
        $map = new LegacyMap($negocio->id);
        $reporte = new Reporte;

        $pasos = $this->pasos($negocio, $map, $reporte, $simular);

        if ($pasos === []) {
            $this->error('Ningun paso coincide con --paso.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->line("  Negocio: <options=bold>{$negocio->name}</> (#{$negocio->id})");
        $this->line('  Origen:  '.config('database.connections.legacy.database')
            .' en '.config('database.connections.legacy.host'));

        if ($simular) {
            $this->line('  <fg=yellow>SIMULACRO: no se escribe nada.</>');
        }

        $this->newLine();

        foreach ($pasos as $paso) {
            $this->line("  → {$paso->nombre()}...");

            try {
                $paso->correr();
            } catch (Throwable $e) {
                $this->newLine();
                $this->error("  {$paso->nombre()} se detuvo: {$e->getMessage()}");
                $this->line('  Lo que alcanzo a traer quedo guardado. Volver a correr el comando');
                $this->line('  sigue donde iba, sin duplicar nada.');
                $this->resultados($reporte);

                return self::FAILURE;
            }
        }

        $this->resultados($reporte);

        return self::SUCCESS;
    }

    /** @return list<Importador> */
    private function pasos(Business $negocio, LegacyMap $map, Reporte $reporte, bool $simular): array
    {
        /*
         * El orden no es negociable: cada paso necesita los ids que anoto el
         * anterior. No hay historial sin servicios ni empleadas, y no hay
         * cita cobrada sin medio de pago.
         */
        $todos = [
            new ImportaCatalogo($negocio, $map, $reporte, $simular),
            new ImportaEquipo($negocio, $map, $reporte, $simular),
            // Despues del equipo: cada cuenta se liga a la ficha de esa
            // persona, y ese vinculo es el que hace funcionar "Mi dia".
            new ImportaUsuarios($negocio, $map, $reporte, $simular),
            new ImportaMediosDePago($negocio, $map, $reporte, $simular),
            new ImportaClientas($negocio, $map, $reporte, $simular),
            new ImportaHistorial($negocio, $map, $reporte, $simular),
            // Despues del historial: cada sello cuelga de una cita migrada.
            new ImportaFidelizacion($negocio, $map, $reporte, $simular),
            new ImportaCalificaciones($negocio, $map, $reporte, $simular),
            /*
             * Despues del historial: cada liquidacion tiene que decir que
             * servicios pago, y esos servicios son las citas migradas.
             */
            new ImportaNomina($negocio, $map, $reporte, $simular),
            new ImportaGastos($negocio, $map, $reporte, $simular),
            /*
             * Las futuras van AL FINAL, y no por comodidad: son las unicas
             * que reclaman ocupacion, asi que si algo va a chocar es mejor
             * que choque cuando todo lo demas ya esta adentro y el reporte
             * puede decir exactamente cual cita quedo pendiente.
             */
            new ImportaCitasFuturas($negocio, $map, $reporte, $simular),
        ];

        $pedidos = array_filter((array) $this->option('paso'));

        if ($pedidos === []) {
            return $todos;
        }

        return array_values(array_filter(
            $todos,
            fn (Importador $p) => in_array(mb_strtolower($p->nombre()), array_map('mb_strtolower', $pedidos), true),
        ));
    }

    private function negocio(): ?Business
    {
        $clave = $this->option('negocio');

        if (blank($clave)) {
            $this->error('Falta --negocio. Ejemplo: --negocio=luxury-nails');

            return null;
        }

        $negocio = Business::query()
            ->when(is_numeric($clave), fn ($q) => $q->where('id', (int) $clave))
            ->when(! is_numeric($clave), fn ($q) => $q->where('slug', $clave))
            ->first();

        if ($negocio === null) {
            $this->error("No existe un negocio con slug o id «{$clave}».");
            $this->line('  Negocios disponibles: '.Business::pluck('slug')->implode(', '));
        }

        return $negocio;
    }

    /**
     * Que la base vieja este ahi antes de anunciar nada.
     *
     * Sin esto, el primer paso falla a mitad con un error de PDO y quien lo
     * corre no sabe si el problema es la credencial, el tunel o los datos.
     */
    private function conectaAlLegacy(): bool
    {
        try {
            DB::connection('legacy')->getPdo();

            return true;
        } catch (Throwable $e) {
            $this->error('No se pudo conectar a la base vieja: '.$e->getMessage());
            $this->newLine();
            $this->line('  Revisar en .env: LEGACY_DB_HOST, LEGACY_DB_PORT, LEGACY_DB_DATABASE,');
            $this->line('  LEGACY_DB_USERNAME y LEGACY_DB_PASSWORD.');
            $this->line('  El usuario debe tener SELECT y nada mas.');

            return false;
        }
    }

    private function resultados(Reporte $reporte): void
    {
        $this->newLine();
        $this->table(['Paso', 'Creados', 'Actualizados', 'Sin cambios'], $reporte->filas());

        if (! $reporte->hayAvisos()) {
            return;
        }

        $avisos = $reporte->avisos();

        $this->newLine();
        $this->line('  <fg=yellow>'.count($avisos).' cosas para revisar:</>');

        /*
         * Se muestran los primeros 30 y se dice cuantos quedan. En la primera
         * corrida hay cientos (cada clienta sin telefono es uno), y volcarlos
         * todos a la terminal hace que nadie lea ninguno.
         */
        foreach (array_slice($avisos, 0, 30) as $aviso) {
            $this->line("    [{$aviso['paso']}] {$aviso['mensaje']}");
        }

        if (count($avisos) > 30) {
            $this->line('    ... y '.(count($avisos) - 30).' mas.');
        }
    }
}
