<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gatea rutas que no tienen (ni deberian tener) un permiso granular del
 * catalogo - configuracion del negocio, facturacion/suscripcion, medios de
 * pago aceptados. A diferencia de EnsureBusinessPermission, no acepta
 * parametros: el dueno/admin del negocio siempre puede, un empleado nunca
 * (no es delegable via el picker de permisos de EmployeeFormModal, a
 * proposito - ver PermissionCatalog).
 *
 * is_business_owner || hasRole('admin'): mismo criterio ya usado en
 * UpdateBusinessRequest::authorize(), UpdateBusinessNotificationsRequest::
 * authorize() y BusinessController::clearLowStockSnooze() - no un
 * hasRole('admin') solo, para no ser mas estricto que esas dos rutas que ya
 * estaban protegidas antes de este middleware.
 */
class EnsureBusinessAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_if(! $user, 401);
        abort_unless($user->is_business_owner || $user->hasRole('admin'), 403, 'Solo un administrador puede realizar esta accion.');

        return $next($request);
    }
}
