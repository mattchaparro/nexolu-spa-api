<?php

namespace App\Http\Controllers\Api\V1\SuperAdmin;

use App\Http\Resources\UserResource;
use App\Models\User;
use App\Support\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * "Entrar como" un usuario de un negocio, para soporte.
 *
 * En una API sin sesion no hay nada que cambiar: impersonar es emitir un token
 * Sanctum a nombre del usuario destino. El superadmin conserva el suyo intacto
 * -- el front lo guarda aparte -- y volver es dejar de usar el de
 * impersonacion, no un endpoint propio: un POST /logout con ese token lo revoca
 * server-side y el front restaura el original sin pedir contraseña de nuevo.
 *
 * El token se llama `impersonation-by-{id}` para que quede huella. Sin ese
 * marcador, todo lo que el superadmin haga durante la sesion queda en la
 * auditoria con el user_id del negocio, indistinguible de algo que esa persona
 * hizo de verdad -- y el dueño del spa lee su auditoria creyendo que su
 * recepcionista cambio un precio.
 */
class ImpersonateController
{
    /** Prefijo del nombre del token, seguido del id del superadmin. */
    public const TOKEN_NAME_PREFIX = 'impersonation-by-';

    public function start(Request $request, User $user): JsonResponse
    {
        if ($user->is_super_admin) {
            // No aporta nada y confunde el rastro: dos superadmins con el
            // token del otro y ninguna forma de saber quien hizo que.
            throw ValidationException::withMessages([
                'user' => 'No puedes entrar como otro usuario de plataforma.',
            ]);
        }

        if ($user->business_id === null) {
            throw ValidationException::withMessages([
                'user' => 'Ese usuario no pertenece a ningún negocio.',
            ]);
        }

        if (! $user->is_active) {
            // Entrar como alguien desactivado mostraria una pantalla que esa
            // persona no puede ver, y llevaria a "arreglar" un problema que no
            // existe.
            throw ValidationException::withMessages([
                'user' => 'Ese usuario está desactivado. Actívalo antes de entrar como él.',
            ]);
        }

        $token = $user->createToken(self::TOKEN_NAME_PREFIX.$request->user()->id)->plainTextToken;

        AuditLogger::log('superadmin.impersonation.started', [
            'business_id' => $user->business_id,
            'impersonated_user_id' => $user->id,
            /*
             * Se pasa a mano porque esta peticion todavia viene autenticada
             * con el token REAL del superadmin: el de impersonacion se acaba
             * de crear y no es el token "actual" de la request, asi que el
             * marcador automatico de AuditLogger::log() no lo detecta. Sin
             * esto, "entrar como" seria el unico evento de la sesion que
             * aparece en la auditoria del dueño como si lo hubiera hecho su
             * propio equipo.
             */
            'impersonated_by_superadmin_id' => $request->user()->id,
        ]);

        return response()->json([
            'token' => $token,
            'user' => new UserResource($user->fresh()->load('business', 'resource')),
        ]);
    }
}
