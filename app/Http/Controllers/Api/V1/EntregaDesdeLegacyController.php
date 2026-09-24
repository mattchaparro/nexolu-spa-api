<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\UserResource;
use App\Models\User;
use App\Support\Auth\TicketDeEntrada;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * El puente desde el sistema viejo: quien ya se identificó allá entra acá
 * sin volver a escribir su clave.
 *
 * Son dos pasos y cada uno tiene su credencial:
 *
 *   emitir()  lo llama el SERVIDOR viejo, con la llave compartida. Devuelve
 *             un pase de un solo uso. La llave nunca toca el navegador.
 *   canjear() lo llama el NAVEGADOR con ese pase, y recibe el token normal
 *             de Sanctum -- el mismo que emite el login de siempre.
 *
 * Separarlo así es lo que hace que un enlace copiado no valga: lo que viaja
 * por la URL se gasta al usarse y dura un minuto.
 *
 * No usa `SsoExchangeController` porque aquel canjea una aserción firmada
 * por nexolu-auth, y el sistema viejo no puede emitir una: no tiene sus
 * llaves ni va a tenerlas para algo que se apaga cuando termine la
 * convivencia.
 */
class EntregaDesdeLegacyController
{
    public function emitir(Request $request): JsonResponse
    {
        $this->exigirLlave($request);

        $datos = $request->validate([
            'email' => ['required', 'email', 'max:255'],
        ]);

        $user = User::withoutGlobalScopes()
            ->where('email', $datos['email'])
            ->first();

        if ($user === null) {
            /*
             * De los diecinueve usuarios del sistema viejo solo tres tienen
             * cuenta acá: el resto son gente que ya no trabaja en el salón.
             * Para ellos esto no es un error, es que no hay a dónde
             * llevarlos, y el sistema viejo los deja donde estaban.
             */
            Log::info('legacy.entrega.sin_cuenta', ['email' => $datos['email']]);

            return response()->json(['message' => 'Esa cuenta no existe en esta aplicación.'], 404);
        }

        if (! $user->is_active) {
            Log::warning('legacy.entrega.cuenta_inactiva', ['user_id' => $user->id]);

            return response()->json(['message' => 'Esta cuenta está desactivada.'], 403);
        }

        Log::info('legacy.entrega.emitida', ['user_id' => $user->id]);

        return response()->json(['ticket' => TicketDeEntrada::emitir($user)]);
    }

    public function canjear(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'ticket' => ['required', 'string', 'max:128'],
            'device_name' => ['required', 'string', 'max:255'],
        ]);

        $user = TicketDeEntrada::canjear($datos['ticket']);

        if ($user === null) {
            // 401 y no 403: acá sí tiene sentido que el front mande a la
            // persona a escribir su clave. El pase venció o ya se usó, y
            // volver a pedirlo desde el sistema viejo es justamente lo que
            // hay que hacer.
            return response()->json(['message' => 'Este acceso ya venció. Vuelve a entrar.'], 401);
        }

        if (! $user->is_active) {
            return response()->json(['message' => 'Esta cuenta está desactivada.'], 403);
        }

        $user->load('business', 'resource');

        Log::info('legacy.entrega.canjeada', ['user_id' => $user->id]);

        return response()->json([
            'token' => $user->createToken($datos['device_name'])->plainTextToken,
            'user' => new UserResource($user),
        ]);
    }

    /**
     * La llave compartida, comparada en tiempo constante.
     *
     * `hash_equals` y no `===` porque comparar secretos con `===` se corta
     * en el primer carácter distinto, y de ahí se puede deducir el secreto
     * midiendo cuánto tardó cada intento.
     */
    private function exigirLlave(Request $request): void
    {
        $esperada = (string) config('services.legacy_handoff.key');

        abort_if($esperada === '', 503, 'La entrega desde el sistema viejo no está configurada.');

        $recibida = (string) $request->header('X-Legacy-Key', '');

        abort_unless(hash_equals($esperada, $recibida), 401, 'Llave inválida.');
    }
}
