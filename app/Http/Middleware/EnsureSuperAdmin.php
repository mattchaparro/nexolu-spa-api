<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSuperAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->hasRole('superadmin')) {
            abort(403, 'Solo el superadmin puede acceder a este panel.');
        }

        return $next($request);
    }
}
