<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Sentry\State\Scope;
use Symfony\Component\HttpFoundation\Response;

use function Sentry\configureScope;

/**
 * Tagea cada evento de Sentry (error o span de performance) de la request
 * autenticada con business_id/business_name/user_id - sin esto, cualquier
 * excepcion llega a Sentry sin forma de saber a que negocio afecto, y hay
 * que ir a buscar el user_id a mano en la base para cruzarlo. Portado de
 * pos-saas-legacy (mismo criterio: SentryBusinessContext).
 *
 * No-op si el SDK esta inerte (DSN vacio - ver config/sentry.php): configureScope()
 * solo prepara el scope, no dispara ninguna llamada de red por si sola.
 */
class SentryBusinessContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user) {
            configureScope(function (Scope $scope) use ($user) {
                $scope->setTag('business_id', (string) ($user->business_id ?? 'none'));
                $scope->setTag('business_name', $user->business?->name ?? 'none');
                $scope->setUser(['id' => $user->id]);
            });
        }

        return $next($request);
    }
}
