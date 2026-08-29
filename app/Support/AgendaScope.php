<?php

namespace App\Support;

use App\Models\User;

/**
 * Hasta donde llega la agenda que ve una persona.
 *
 * `citas.ver` deja entrar a la agenda; `citas.ver_todas` decide si esa agenda
 * es la del negocio o solo la propia. Sin esta separacion una profesional
 * entraba con el permiso minimo y veia la clientela completa: la misma fuga
 * que motiva ocultarle el telefono, en version suave.
 *
 * Tres estados posibles:
 *   - `resourceId === null`  -> ve todo el negocio.
 *   - `resourceId === int`   -> solo su columna.
 *   - `seesNothing()`        -> no tiene ficha de recurso y no puede ver todo;
 *                               no hay "su agenda" que mostrar.
 */
final class AgendaScope
{
    private function __construct(
        public readonly ?int $resourceId,
        private readonly bool $nothing,
    ) {}

    public static function for(User $user): self
    {
        if ($user->hasBusinessPermission('citas.ver_todas')) {
            return new self(null, false);
        }

        $resourceId = $user->resource?->id;

        return $resourceId === null
            ? new self(null, true)
            : new self($resourceId, false);
    }

    public function seesNothing(): bool
    {
        return $this->nothing;
    }
}
