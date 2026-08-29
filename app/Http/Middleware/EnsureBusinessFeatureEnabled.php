<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureBusinessFeatureEnabled
{
    public function handle(Request $request, Closure $next, string $feature): Response
    {
        $user = $request->user();

        if (! $user || ! $user->business_id) {
            return $next($request);
        }

        $business = $user->business;
        if ($business && ! $business->hasFeature($feature)) {
            abort(403, 'Este modulo no esta habilitado para este negocio.');
        }

        return $next($request);
    }
}
