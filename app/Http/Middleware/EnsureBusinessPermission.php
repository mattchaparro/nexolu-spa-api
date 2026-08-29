<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Hace cumplir un permiso granular del catalogo (ver App\Support\PermissionCatalog)
 * en una ruta de negocio. El rol admin siempre pasa (hereda todo por rol,
 * igual que en EmployeeController/AiToolInvokeController); un empleado
 * necesita el permiso asignado directo a el.
 *
 * Uso: ->middleware('permission:clients.manage')
 * Con varios, basta con tener uno: ->middleware('permission:expenses.create,expenses.manage')
 */
class EnsureBusinessPermission
{
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = $request->user();

        abort_if(! $user, 401);

        foreach ($permissions as $permission) {
            if ($user->hasBusinessPermission($permission)) {
                return $next($request);
            }
        }

        abort(403, 'No tienes permiso para esta accion.');
    }
}
