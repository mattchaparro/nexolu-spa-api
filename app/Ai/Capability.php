<?php

namespace App\Ai;

/**
 * Una capacidad de negocio invocable por el Nexolu IA Core (servicio Python
 * aparte) via POST /api/ai/tools/invoke.
 *
 * Cada capacidad SOLO llama Services que ya existen -- AvailabilityService,
 * BookingService, ClientPortalService -- nunca Eloquent suelto ni una copia
 * de la regla. Es la misma logica que usan los controllers HTTP, expuesta
 * por otro canal.
 *
 * Es importante por que: la garantia anti-solape vive en el indice unico que
 * reclama `BookingService`, y los limites de sede y de aviso minimo viven en
 * esos servicios. Una capacidad que agende "por su cuenta" seria un segundo
 * camino de agendamiento con reglas distintas -- exactamente el problema del
 * que este producto viene huyendo.
 */
interface Capability
{
    /**
     * Permiso requerido para una EMPLEADA, o null si cualquiera del negocio
     * puede usarla. No aplica a clientas: ellas nunca tienen permisos.
     */
    public function requiredPermission(): ?string;

    /** Feature flag del negocio requerido, o null si es siempre accesible. */
    public function requiredFeature(): ?string;

    /**
     * Si una CLIENTA anonima (WhatsApp) puede invocarla.
     *
     * Por defecto se implementa `false` en cada capacidad nueva a proposito:
     * abrirle una capacidad al publico tiene que ser una decision escrita,
     * nunca lo que pasa por olvido. La base de clientas del negocio es suya
     * -- "podria robarse los datos para atenderlos fuera de mi local" -- y
     * una capacidad que enumere clientes JAMAS debe devolver true aca.
     */
    public function allowsCustomers(): bool;

    /**
     * Reglas de validacion (formato Laravel) para los argumentos.
     *
     * El IA Core ya los valido contra su JSON Schema, pero esto es entrada
     * de red: se revalida siempre. Ademas, lo que propone un modelo de
     * lenguaje no es input confiable ni cuando el transporte lo es.
     *
     * @return array<string, mixed>
     */
    public function rules(): array;

    /**
     * @param  array<string, mixed>  $arguments  ya validados por rules()
     * @return array<string, mixed>
     */
    public function execute(AiCaller $caller, array $arguments): array;
}
