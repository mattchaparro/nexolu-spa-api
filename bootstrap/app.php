<?php

use App\Http\Middleware\EnsureBusinessAdmin;
use App\Http\Middleware\EnsureBusinessFeatureEnabled;
use App\Http\Middleware\EnsureBusinessPermission;
use App\Http\Middleware\EnsureSuperAdmin;
use App\Http\Middleware\EnsureValidIaCoreKey;
use App\Http\Middleware\SentryBusinessContext;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Sentry\Laravel\Integration;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // API pura: no hay ruta web de login a la cual redirigir. Un request
        // sin autenticar siempre debe caer en un 401 JSON, nunca en el
        // redirect HTML por defecto de Laravel.
        $middleware->redirectGuestsTo(fn () => null);

        $middleware->alias([
            'feature' => EnsureBusinessFeatureEnabled::class,
            'superadmin' => EnsureSuperAdmin::class,
            'ia-core.key' => EnsureValidIaCoreKey::class,
            'permission' => EnsureBusinessPermission::class,
            'business-admin' => EnsureBusinessAdmin::class,
            'sentry.context' => SentryBusinessContext::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        Integration::handles($exceptions);
    })->create();
