<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * El superadmin es una propiedad del usuario, no un rol del negocio.
 *
 * Los roles de spatie viven dentro de un negocio -- un "admin" lo es de SU
 * spa. Quien administra la plataforma no pertenece a ninguno: su
 * `business_id` es nulo, y por eso el scope de BelongsToBusiness no lo filtra
 * y puede ver todos los negocios.
 *
 * Esa misma propiedad es el riesgo: un usuario sin negocio ve todo. Por eso
 * la marca es una columna explicita y no la ausencia de business_id.
 */
class EnsureSuperAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_if(! $user, 401);
        abort_if(! $user->is_super_admin, 403, 'Solo la plataforma puede acceder aca.');
        abort_if(! $user->is_active, 403, 'Esta cuenta esta desactivada.');

        return $next($request);
    }
}
