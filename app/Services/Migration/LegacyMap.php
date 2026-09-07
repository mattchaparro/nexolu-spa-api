<?php

namespace App\Services\Migration;

use Illuminate\Support\Facades\DB;

/**
 * El libro de "que fila del legacy ya trajimos, y como quedo aca".
 *
 * Toda la idempotencia de la migracion vive en esta clase. La app vieja de
 * Luxury sigue viva mientras el equipo se muda, asi que el comando se corre
 * muchas veces: hoy trae 3.329 atenciones, manana trae las 12 de hoy.
 *
 * Los mapas se cargan ENTEROS a memoria antes de importar. Son unas 5.000
 * filas -- nada -- y la alternativa es una consulta por cada fila del legacy:
 * en la corrida grande eso son miles de viajes a la base para descubrir, casi
 * siempre, que la fila ya estaba.
 */
class LegacyMap
{
    /** @var array<string, array<int, int>> entity => [legacy_id => new_id] */
    private array $ids = [];

    /** @var array<string, array<int, ?string>> entity => [legacy_id => fingerprint] */
    private array $huellas = [];

    /** @var array<string, bool> */
    private array $cargados = [];

    public function __construct(private readonly int $businessId) {}

    /**
     * La huella de una fila origen.
     *
     * Solo con los campos que la migracion mira. Si el legacy toca un campo
     * que no traemos, la huella no cambia y la fila no se reescribe -- que es
     * lo correcto: no hay nada nuevo que traer.
     *
     * @param  array<int|string, mixed>  $campos
     */
    public static function huella(array $campos): string
    {
        return hash('sha256', json_encode($campos, JSON_UNESCAPED_UNICODE) ?: '');
    }

    private function cargar(string $entity): void
    {
        if (isset($this->cargados[$entity])) {
            return;
        }

        $this->ids[$entity] = [];
        $this->huellas[$entity] = [];

        DB::table('legacy_map')
            ->where('business_id', $this->businessId)
            ->where('entity', $entity)
            ->select('legacy_id', 'new_id', 'fingerprint')
            ->orderBy('id')
            ->chunk(2000, function ($filas) use ($entity) {
                foreach ($filas as $f) {
                    $this->ids[$entity][(int) $f->legacy_id] = (int) $f->new_id;
                    $this->huellas[$entity][(int) $f->legacy_id] = $f->fingerprint;
                }
            });

        $this->cargados[$entity] = true;
    }

    /** El id nuevo de una fila vieja, o null si nunca se importo. */
    public function idNuevo(string $entity, int|string|null $legacyId): ?int
    {
        if ($legacyId === null) {
            return null;
        }

        $this->cargar($entity);

        return $this->ids[$entity][(int) $legacyId] ?? null;
    }

    public function yaExiste(string $entity, int|string|null $legacyId): bool
    {
        return $this->idNuevo($entity, $legacyId) !== null;
    }

    /**
     * ¿Cambio la fila origen desde la ultima importacion?
     *
     * Falso tambien cuando nunca se importo: para eso esta `yaExiste()`. Esto
     * responde solo "lo que ya trajimos, ¿sigue igual?".
     */
    public function cambio(string $entity, int|string $legacyId, string $huella): bool
    {
        $this->cargar($entity);

        $anterior = $this->huellas[$entity][(int) $legacyId] ?? null;

        return $anterior !== null && $anterior !== $huella;
    }

    /**
     * Anota (o reanota) que una fila vieja vive aca con este id.
     *
     * `updateOrInsert` y no `insert`: una corrida que actualiza una fila ya
     * mapeada tiene que refrescar la huella, o la corrida siguiente creeria
     * que sigue desactualizada y la reescribiria para siempre.
     */
    public function anotar(string $entity, int|string $legacyId, int $newId, ?string $huella = null): void
    {
        $this->cargar($entity);

        DB::table('legacy_map')->updateOrInsert(
            [
                'business_id' => $this->businessId,
                'entity' => $entity,
                'legacy_id' => (int) $legacyId,
            ],
            [
                'new_id' => $newId,
                'fingerprint' => $huella,
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );

        $this->ids[$entity][(int) $legacyId] = $newId;
        $this->huellas[$entity][(int) $legacyId] = $huella;
    }

    /** Cuantas filas de esta entidad ya se importaron. */
    public function cuantos(string $entity): int
    {
        $this->cargar($entity);

        return count($this->ids[$entity]);
    }

    /**
     * Un mapa vacio en memoria, para `--dry-run`.
     *
     * En simulacion nada se escribe, pero los importadores igual preguntan
     * "¿ya existe?" y anotan lo que crearian -- si no, un dry-run reportaria
     * que va a crear la misma clienta una vez por cada una de sus 12 visitas.
     */
    public function anotarEnMemoria(string $entity, int|string $legacyId, int $newId, ?string $huella = null): void
    {
        $this->cargar($entity);

        $this->ids[$entity][(int) $legacyId] = $newId;
        $this->huellas[$entity][(int) $legacyId] = $huella;
    }
}
