<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Autentica al Nexolu IA Core: una API key fija de APLICACION, no un token de
 * usuario.
 *
 * El Core no tiene sesion propia -- solo afirma quien pregunta en el bloque
 * `context` del cuerpo. Por eso pasar por aca no autoriza nada todavia: el
 * controlador vuelve a resolver ese contexto contra la base de datos.
 */
class EnsureValidIaCoreKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = config('services.ia_core.api_key');
        $provided = $request->bearerToken();

        // Sin llave configurada el endpoint no existe: un despliegue a medio
        // configurar no puede quedar abierto de par en par.
        if (! $expected || ! $provided || ! hash_equals((string) $expected, $provided)) {
            return response()->json(['error' => 'No autorizado.'], 401);
        }

        return $next($request);
    }
}
