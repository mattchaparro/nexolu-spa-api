<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Autentica al Nexolu IA Core (servicio Python externo, repo aparte) contra
 * el endpoint de despacho de herramientas: una API key fija de aplicacion,
 * no un token de usuario Sanctum. El IA Core no tiene sesion de usuario
 * propia - confia en el bloque "context" del body, que este controlador
 * vuelve a validar contra la base de datos (ver AiToolInvokeController).
 */
class EnsureValidIaCoreKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = config('services.ia_core.api_key');
        $provided = $request->bearerToken();

        if (! $expected || ! $provided || ! hash_equals($expected, $provided)) {
            return response()->json(['error' => 'No autorizado.'], 401);
        }

        return $next($request);
    }
}
