<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Api\V1\SsoExchangeRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Support\Auth\InvalidAssertion;
use App\Support\Auth\NexoluAuthAssertion;
use App\Support\Auth\SsoNotConfigured;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

/**
 * Canjea una asercion de nexolu-auth por un token de Sanctum normal.
 *
 * Despues de este canje, nexolu-auth desaparece del camino: el token es el
 * mismo PAT que emite AuthController::login(), con su misma expiracion, y
 * todo lo demas (auth:sanctum, spatie, el scope por sede) sigue igual. Por
 * eso el canje es un controlador y no un middleware: verificar el JWT en
 * cada peticion meteria la rotacion de llaves en el camino caliente.
 *
 * La ruta es publica porque la asercion ES la credencial: viene firmada y
 * dura 120 segundos.
 */
class SsoExchangeController
{
    public function __invoke(SsoExchangeRequest $request): JsonResponse
    {
        $data = $request->validated();

        try {
            $assertion = NexoluAuthAssertion::verify($data['assertion']);
        } catch (SsoNotConfigured $error) {
            // 503: el SSO no esta habilitado aca. El login propio de esta
            // API sigue funcionando, que es justo el punto de tener el
            // interruptor.
            abort(503, $error->getMessage());
        } catch (InvalidAssertion $error) {
            Log::warning('sso.asercion_invalida', ['motivo' => $error->getMessage()]);
            abort(401, 'La asercion no es valida.');
        }

        $user = $this->resolve($assertion);

        if (! $user) {
            // 403 y NO 401. Con 401 el frontend creeria que la asercion
            // vencio, rebotaria a nexolu-auth (que tiene la cookie viva),
            // recibiria otra asercion, volveria a fallar igual... y el
            // usuario quedaria en un bucle sin ver nunca un formulario.
            // 403 es terminal: el front lo trata como final.
            Log::warning('sso.cuenta_no_vinculada', ['email' => $assertion->email]);
            abort(403, 'Esa identidad no tiene una cuenta en esta aplicacion.');
        }

        $this->assertEligible($user);

        $user->load('business', 'resource');

        return response()->json([
            'token' => $user->createToken($data['device_name'])->plainTextToken,
            'user' => new UserResource($user),
        ]);
    }

    /**
     * Resuelve por el vinculo explicito y, solo si no hay, por correo.
     *
     * El orden importa: el `account.user_id` viene de `linked_accounts` en
     * nexolu-auth y es la fuente autoritativa. El correo es el seguro
     * contra "restaure un dump y cambiaron los ids", y se puede apagar con
     * NEXOLU_AUTH_EMAIL_FALLBACK cuando el mapeo este probado.
     *
     * El log dice por CUAL de los dos caminos resolvio -- sin eso nadie se
     * entera nunca de que el mapeo esta roto y el fallback lo esta tapando.
     */
    private function resolve(NexoluAuthAssertion $assertion): ?User
    {
        if ($assertion->externalUserId !== null) {
            $user = User::find($assertion->externalUserId);

            if ($user) {
                Log::info('sso.usuario_resuelto', ['via' => 'linked_account', 'user_id' => $user->id]);

                return $user;
            }
        }

        if (! config('services.nexolu_auth.email_fallback')) {
            return null;
        }

        $user = User::where('email', $assertion->email)->first();

        if ($user) {
            Log::warning('sso.usuario_resuelto', ['via' => 'email_fallback', 'user_id' => $user->id]);
        }

        return $user;
    }

    /**
     * La UNICA linea que difiere del canje de nexolu-pos-api, donde el
     * superadmin es un rol de spatie y aca es una columna (ver
     * EnsureSuperAdmin y su docblock).
     *
     * Es una compuerta dura, no una pista: en la Fase 1 solo el superadmin
     * canja. El claim `scope` de la asercion no se mira -- nexolu-auth dice
     * quien eres, esta API decide que puedes.
     */
    private function assertEligible(User $user): void
    {
        abort_unless($user->is_super_admin, 403, 'Esa identidad no puede entrar por aca.');
        abort_unless($user->is_active, 403, 'Esta cuenta esta desactivada.');
    }
}
