<?php

namespace App\Services\Migration\Importadores;

use App\Models\Business;
use App\Services\Migration\LegacyMap;
use App\Services\Migration\Reporte;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Lo que todo importador comparte.
 *
 * Cada paso de la migracion es una subclase. Se corren en orden porque se
 * necesitan entre si -- no hay historial sin servicios ni sellos sin
 * historial -- y todos obedecen la misma regla: pueden correrse cien veces
 * seguidas y el resultado es el mismo que correrlos una.
 */
abstract class Importador
{
    /**
     * La zona en que esta escrita la base vieja.
     *
     * Constante y no `$business->businessTimezone()`: es una propiedad del
     * ORIGEN, no del destino. El legacy tiene `America/Bogota` hardcodeado en
     * su config/app.php desde el primer dia; si manana el negocio nuevo se
     * configurara en otra zona, las fechas viejas seguirian siendo de Bogota.
     * Atarlas a la configuracion del destino correria el historial entero.
     */
    protected const ZONA_LEGACY = 'America/Bogota';

    public function __construct(
        protected readonly Business $business,
        protected readonly LegacyMap $map,
        protected readonly Reporte $reporte,
        protected readonly bool $simular,
    ) {}

    /** Como se llama este paso en el reporte. */
    abstract public function nombre(): string;

    abstract public function correr(): void;

    protected function legacy(string $tabla): Builder
    {
        return DB::connection('legacy')->table($tabla);
    }

    /**
     * Una fecha del legacy, ya en UTC y lista para guardar.
     *
     * El unico lugar del importador donde se convierte zona horaria. Que sea
     * uno solo es deliberado: este bug ya aparecio tres veces en el proyecto
     * (el `toISOString()` del front, el `date.today()` de comms, y el
     * `America/Bogota` hardcodeado de esta misma app vieja), y siempre por
     * tener la conversion repartida en varios sitios.
     */
    protected function utc(?string $valor): ?CarbonImmutable
    {
        if ($valor === null || trim($valor) === '' || str_starts_with($valor, '0000')) {
            return null;
        }

        return CarbonImmutable::parse($valor, self::ZONA_LEGACY)->utc();
    }

    /** Una fecha `date` del legacy mas una hora `time`, en UTC. */
    protected function utcDe(?string $fecha, ?string $hora): ?CarbonImmutable
    {
        if ($fecha === null || trim($fecha) === '') {
            return null;
        }

        return $this->utc(trim($fecha).' '.(trim((string) $hora) ?: '00:00:00'));
    }

    /**
     * Escribe, salvo que sea un simulacro.
     *
     * En `--simular` devuelve un id negativo distinto cada vez. Negativo para
     * que, si algo lo guardara por error, se note al instante en vez de
     * apuntar en silencio a la fila 1 de otra tabla.
     *
     * OJO: EN SIMULACRO EL CIERRE NO SE EJECUTA. Eso es lo que hace que el
     * simulacro no escriba, y tambien lo que hace que no vea nada de lo que
     * pase adentro: un `create()` al que se le olvide el `->id` pasa el
     * simulacro limpio y revienta en la corrida de verdad. Ya paso, contra una
     * base de produccion recien creada.
     *
     * El cierre TIENE que devolver el id, no el modelo.
     */
    protected function crear(callable $insertar): int
    {
        static $falso = 0;

        return $this->simular ? --$falso : $insertar();
    }
}
