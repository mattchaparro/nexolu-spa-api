<?php

namespace App\Capabilities;

use App\Models\Business;
use App\Models\User;

/**
 * Una capacidad de negocio invocable por el Nexolu IA Core (repo Python
 * aparte, ver README de ese proyecto) via POST /api/ai/tools/invoke.
 *
 * Cada capacidad SOLO llama Services existentes, nunca Eloquent directo: es
 * la misma logica de negocio que ya usan los controllers HTTP, expuesta por
 * un canal distinto. El nombre por el que el IA Core la invoca vive en
 * Registry::MAP, no aqui, para que exista una unica fuente de verdad del
 * vocabulario compartido con el contrato externo.
 */
interface Capability
{
    /**
     * Permiso Spatie requerido, o null si cualquier usuario activo del
     * negocio puede usarla. Un admin siempre pasa este chequeo por rol.
     */
    public function requiredPermission(): ?string;

    /** Feature flag del negocio requerido, o null si es siempre accesible. */
    public function requiredFeature(): ?string;

    /**
     * Reglas de validacion (formato Laravel Validator) para los argumentos.
     * El IA Core ya los valido contra su propio JSON Schema antes de
     * llamar, pero esta API nunca confia en input de red sin revalidar.
     *
     * @return array<string, mixed>
     */
    public function rules(): array;

    /**
     * Ejecuta la capacidad para el negocio y usuario ya resueltos y
     * autorizados. $arguments ya paso por rules() y esta validado.
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public function execute(Business $business, User $user, array $arguments): array;
}
