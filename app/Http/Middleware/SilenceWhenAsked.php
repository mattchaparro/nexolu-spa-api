<?php

namespace App\Http\Middleware;

use App\Services\Messaging\MessageDispatcher;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * «No avisar a nadie»: la acción se hace, los mensajes no salen.
 *
 * Agendar, cobrar, cancelar o mover de etapa avisan a la clienta y al equipo,
 * y casi siempre eso es lo que se quiere. Pero hay trabajo que es solo de
 * administración: pasar al sistema lo que se hizo ayer y nadie subió, cobrar
 * a medianoche un servicio de la tarde, corregir una cita cargada dos veces.
 * El 25 de septiembre hubo que subir seis servicios del día anterior pasada
 * la medianoche; sin esto, a cada clienta le habría llegado el gracias con la
 * encuesta a las doce y media de la noche.
 *
 * Solo lo pide quien tiene `avisos.silenciar`: callar un aviso es decidir que
 * la clienta no se entere, y eso no lo decide cualquiera del equipo. Pedirlo
 * sin el permiso es un error y no un «igual se avisa»: quien marcó la casilla
 * cree que no salió nada.
 */
class SilenceWhenAsked
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->boolean('silent')) {
            return $next($request);
        }

        $user = $request->user();

        abort_if(! $user, 401);
        abort_unless(
            $user->hasBusinessPermission('avisos.silenciar'),
            403,
            'No tienes permiso para hacer cambios sin avisar.',
        );

        return MessageDispatcher::silently(fn () => $next($request));
    }
}
