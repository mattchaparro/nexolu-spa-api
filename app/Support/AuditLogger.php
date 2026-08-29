<?php

namespace App\Support;

use App\Http\Controllers\Api\V1\SuperAdmin\ImpersonateController;
use App\Models\LogAction;

/**
 * Rastro de acciones administrativas, consultable con SQL (`log_actions`).
 *
 * El esquema es el de ESTE producto -- `payload`, `subject_type/subject_id`,
 * `ip_address` -- y no el del POS, de donde se copio esta clase: escribia
 * `url`, `method` y `agent`, columnas que aca no existen, asi que cualquier
 * llamada reventaba con un 500. Estuvo asi hasta que la impersonacion fue el
 * primer sitio en usarla de verdad.
 *
 * Todavia instrumenta poco: hoy solo lo de plataforma. Retro-instrumentar
 * cobros, cierres y nomina es un cambio aparte y mas grande.
 */
class AuditLogger
{
    /**
     * @param  array<string, mixed>  $details  Queda en `payload`. Si trae
     *                                         `business_id`, manda ese; si no,
     *                                         el del usuario autenticado.
     */
    public static function log(string $action, array $details = [], ?string $subjectType = null, ?int $subjectId = null): void
    {
        $request = request();
        $user = $request?->user();

        /*
         * El token de un superadmin impersonando se llama
         * "impersonation-by-{id}" (ver ImpersonateController). Sin este
         * marcador, lo que hace soporte durante esa sesion queda con el
         * user_id del negocio, indistinguible de algo que esa persona hizo de
         * verdad -- y el dueño lee su auditoria y culpa a quien no fue.
         * AuditLogQuery::forBusiness() lo usa para excluirlas.
         */
        $tokenName = (string) ($user?->currentAccessToken()?->name ?? '');

        if (str_starts_with($tokenName, ImpersonateController::TOKEN_NAME_PREFIX)) {
            $details['impersonated_by_superadmin_id'] = (int) substr(
                $tokenName,
                strlen(ImpersonateController::TOKEN_NAME_PREFIX),
            );
        }

        LogAction::create([
            'action' => $action,
            'user_id' => $user?->id,
            'business_id' => $details['business_id'] ?? $user?->business_id,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'payload' => $details,
            'ip_address' => $request?->ip(),
        ]);
    }
}
