<?php

namespace App\Support;

use App\Models\Business;
use App\Models\Location;
use App\Models\User;

/**
 * Hasta donde llega la vista de sedes de una persona.
 *
 * Hermano de `AgendaScope`, y por la misma razon: sin esto todos los que
 * entran ven el negocio entero. Con un solo local eso daba igual; con dos, es
 * la diferencia entre que la administradora de Cedritos vea su caja o vea
 * tambien la de Chapinero.
 *
 * TRES REGLAS, y ninguna es obvia:
 *
 * 1. El DUENO ve todo, siempre, y no se le puede restringir. No es un permiso
 *    -- un permiso se quita sin querer desde la pantalla de permisos, y un
 *    negocio sin nadie que vea sus dos sedes es un negocio que no puede
 *    administrarse a si mismo.
 *
 * 2. La lista VACIA no significa "todas". Significa que se cae a la sede donde
 *    esa persona trabaja. Lo contrario es que una recepcionista recien creada
 *    vea la clientela de los dos locales porque nadie se acordo de asignarle
 *    sede: el default inseguro es el que nadie revisa.
 *
 * 3. Con UNA sola sede todo esto es inerte. El negocio de un local nunca ve
 *    un filtro, y nada de lo que ya funcionaba cambia.
 *
 * LEER y ACTUAR piden cosas distintas, y por eso hay dos resolvedores. Un
 * reporte puede abarcar las dos sedes de quien tiene dos; un cierre de caja no
 * -- se cuenta UN cajon -- y ahi hay que preguntar cual.
 */
final class LocationScope
{
    /**
     * @param  list<int>|null  $locationIds  null = todas las del negocio.
     */
    private function __construct(
        public readonly ?array $locationIds,
    ) {}

    public static function for(User $user): self
    {
        // El dueno, sin condiciones.
        if ($user->is_owner) {
            return new self(null);
        }

        $asignadas = $user->locations()
            ->where('locations.is_active', true)
            ->pluck('locations.id')
            ->all();

        if ($asignadas !== []) {
            return new self(array_map('intval', $asignadas));
        }

        /*
         * Sin asignacion explicita: la sede donde trabaja. Es lo que hace que
         * el negocio de un solo local no tenga que configurar nada, y que
         * quien atiende en Cedritos vea Cedritos sin que nadie lo marque.
         */
        $suya = $user->resource?->location_id
            ?? Location::withoutGlobalScopes()
                ->where('business_id', $user->business_id)
                ->where('is_primary', true)
                ->value('id');

        return new self($suya === null ? [] : [(int) $suya]);
    }

    /** Ve todas las sedes del negocio. */
    public function seesAll(): bool
    {
        return $this->locationIds === null;
    }

    /** Si puede mirar esta sede. */
    public function allows(?int $locationId): bool
    {
        if ($this->seesAll()) {
            return true;
        }

        /*
         * Lo que no tiene sede -- un gasto del negocio entero, una cita
         * anterior a las sedes -- lo ve cualquiera que ya este adentro. No es
         * de nadie en particular, y esconderselo a todo el mundo seria hacerlo
         * desaparecer de los reportes sin que nadie lo note.
         */
        if ($locationId === null) {
            return true;
        }

        return in_array($locationId, $this->locationIds ?? [], true);
    }

    /**
     * Por que sedes filtrar una consulta de LECTURA.
     *
     * Null = sin filtro. Sin sede pedida devuelve TODAS las suyas, no la
     * primera: quien administra dos locales y abre el reporte espera ver los
     * dos, y darle uno solo le esconde la mitad de su negocio sin decirselo.
     *
     * @return list<int>|null
     *
     * @throws \DomainException
     */
    public function filterFor(?int $requested): ?array
    {
        if ($requested === null) {
            return $this->locationIds;
        }

        if (! $this->allows($requested)) {
            throw new \DomainException('No tienes acceso a esa sede.');
        }

        return [$requested];
    }

    /**
     * La UNICA sede en la que ocurre un acto fisico: abrir un turno, cerrar
     * el dia, registrar un gasto del local.
     *
     * Si no la mandaron y esta persona solo puede estar en una, es esa. Si
     * puede estar en varias, hay que preguntar: un cierre que abarque dos
     * cajones no se puede cuadrar contra ninguno.
     *
     * @throws \DomainException
     */
    public function resolveOne(?int $requested, string $paraQue = 'esta operación'): ?int
    {
        if ($requested !== null) {
            if (! $this->allows($requested)) {
                throw new \DomainException('No tienes acceso a esa sede.');
            }

            return $requested;
        }

        $suyas = $this->locationIds;

        // Ve todas: sin sede explicita no se puede adivinar cual.
        if ($suyas === null) {
            return null;
        }

        if (count($suyas) === 1) {
            return $suyas[0];
        }

        throw new \DomainException("Dinos en qué sede es {$paraQue}.");
    }

    /**
     * Igual que `resolveOne`, pero sabiendo cuantas sedes tiene el negocio.
     *
     * Hace falta porque quien ve TODO no tiene lista contra la cual contar: a
     * la dueña de dos locales `resolveOne` le devolveria null -- "todas" -- y
     * un turno de caja que no es de ningun cajon es exactamente el numero sin
     * significado que este modulo existe para evitar.
     *
     * @throws \DomainException
     */
    public function resolveOneFor(Business $business, ?int $requested, string $paraQue = 'esta operación'): ?int
    {
        $id = $this->resolveOne($requested, $paraQue);

        if ($id === null && $business->locations()->where('is_active', true)->count() > 1) {
            throw new \DomainException("Dinos en qué sede es {$paraQue}.");
        }

        return $id;
    }
}
