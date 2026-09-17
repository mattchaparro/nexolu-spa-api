<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * La puerta de los endpoints que consulta un flujo de Connect.
 *
 * La accion "Solicitud externa" de un flujo manda el header que el operador
 * configuro en el panel de Connect; aca se compara contra la llave del .env.
 * Es una llave de APLICACION distinta de la del IA Core a proposito: rotar
 * una no debe tumbar a la otra, y esta viaja escrita en definiciones de
 * flujo (otro perimetro de exposicion).
 *
 * Pasar por aqui NO autoriza negocio: el controlador resuelve el negocio y
 * responde solo lecturas publicas (horas libres) - nada que escribir.
 */
class EnsureValidConnectKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = config('services.comms_core.tools_key');
        $provided = $request->bearerToken();

        if (! $expected || ! $provided || ! hash_equals((string) $expected, $provided)) {
            return response()->json(['error' => 'No autorizado.'], 401);
        }

        return $next($request);
    }
}
