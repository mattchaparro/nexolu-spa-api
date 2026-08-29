<?php

namespace App\Support;

use App\Http\Controllers\Api\V1\SuperAdmin\ImpersonateController;
use App\Models\LogAction;

/**
 * Rastro de acciones administrativas consultable con SQL (tabla `log_actions`,
 * ya existe en el schema compartido). Instrumenta acciones de todo el POS
 * (ventas, turnos de caja, gastos, cierres, fiados, cocina...), no solo
 * SuperAdmin - retro-instrumentar cada modulo restante con esto es un
 * cambio aparte, mas grande.
 */
class AuditLogger
{
    /**
     * @param  array<string, mixed>  $details
     */
    public static function log(string $action, array $details = []): void
    {
        $request = request();
        $user = $request?->user();

        // El token de un superadmin impersonando un negocio se nombra
        // "impersonation-by-{id}" (ver ImpersonateController::start()) - sin
        // este marcador, una accion hecha por el superadmin durante esa
        // sesion queda en log_actions con el user_id del negocio
        // impersonado, indistinguible de una accion real de ese usuario.
        // AuditLogQuery::forBusiness() usa este campo para excluirlas del
        // listado que ve el dueño del negocio (ver ese archivo).
        $tokenName = (string) ($user?->currentAccessToken()?->name ?? '');
        if (str_starts_with($tokenName, ImpersonateController::TOKEN_NAME_PREFIX)) {
            $details['impersonated_by_superadmin_id'] = (int) substr($tokenName, strlen(ImpersonateController::TOKEN_NAME_PREFIX));
        }

        LogAction::create([
            'action' => $action,
            'url' => $request?->fullUrl(),
            'method' => $request?->method(),
            'ip' => $request?->ip(),
            'agent' => $request?->userAgent(),
            'user_id' => $user?->id,
            'business_id' => $details['business_id'] ?? $user?->business_id,
            'details' => $details,
        ]);
    }
}
