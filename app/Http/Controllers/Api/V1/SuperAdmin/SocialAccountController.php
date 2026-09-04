<?php

namespace App\Http\Controllers\Api\V1\SuperAdmin;

use App\Models\Business;
use App\Models\BusinessSocialAccount;
use App\Services\Social\InstagramTokens;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Conectar la cuenta de Instagram de un negocio, pegando las credenciales.
 *
 * ESTO ES UN ATAJO CONSCIENTE, NO LA ARQUITECTURA. Lo correcto es el Embedded
 * Signup de Meta: el negocio conecta su propia cuenta desde su panel, en un
 * popup de Meta, y nadie escribe un token nunca. Igual que con WhatsApp -- ver
 * docs/whatsapp-numero-por-negocio.md, que ya decidio esto para el otro canal.
 *
 * Existe porque exigir el flujo completo antes de que el primer spa pueda ver
 * si esto le sirve es perder el producto en el onboarding. Vive en SUPERADMIN
 * y no en el panel del negocio a proposito: pegar un token de acceso a mano no
 * es una tarea que se le ofrezca a la duena de un spa, y un campo asi en su
 * pantalla es una invitacion a pegar cualquier cosa que encuentre.
 *
 * Cuando exista el Embedded Signup, este controlador se borra.
 */
class SocialAccountController
{
    public function show(Business $business): JsonResponse
    {
        $cuenta = $business->instagramAccount;

        return response()->json(['account' => $this->detail($cuenta)]);
    }

    /**
     * Guarda -- o reemplaza -- las credenciales de un negocio.
     *
     * Se VERIFICA contra Meta antes de guardar. Un token mal copiado guardado
     * en silencio es una publicacion que falla dentro de tres semanas, cuando
     * nadie se acuerde de esto; verificar ahora convierte eso en un error en
     * la pantalla de quien lo esta pegando.
     */
    public function store(Request $request, Business $business, InstagramTokens $tokens): JsonResponse
    {
        $data = $request->validate([
            'external_id' => ['required', 'string', 'max:64'],
            'access_token' => ['required', 'string', 'max:1000'],
        ]);

        $cuenta = $tokens->describe($data['external_id'], $data['access_token']);

        if ($cuenta === null) {
            return response()->json([
                'message' => 'Instagram no aceptó esas credenciales. Revisa el id de la cuenta y el token.',
            ], 422);
        }

        $account = BusinessSocialAccount::withoutGlobalScopes()->updateOrCreate(
            [
                'business_id' => $business->id,
                'provider' => BusinessSocialAccount::PROVIDER_INSTAGRAM,
            ],
            [
                'external_id' => $data['external_id'],
                'username' => $cuenta['username'],
                'access_token' => $data['access_token'],
                'token_expires_at' => $cuenta['expires_at'],
                'is_active' => true,
                'connected_by_user_id' => $request->user()->id,
            ],
        );

        return response()->json(['account' => $this->detail($account->fresh())]);
    }

    /**
     * Apagar la publicacion automatica sin desconectar.
     *
     * Un negocio que quiere dejar de publicar un mes no deberia tener que
     * volver a pasar por Meta para volver. Con la cuenta apagada, el reloj
     * deja las publicaciones en "lista para publicar" -- que es el modo por
     * defecto del producto, no un estado degradado.
     */
    public function toggle(Request $request, Business $business): JsonResponse
    {
        $cuenta = $business->instagramAccount;

        abort_if($cuenta === null, 404);

        $data = $request->validate(['is_active' => ['required', 'boolean']]);

        $cuenta->forceFill(['is_active' => $data['is_active']])->save();

        return response()->json(['account' => $this->detail($cuenta->fresh())]);
    }

    /** Desconectar del todo: se borra el token. */
    public function destroy(Business $business): JsonResponse
    {
        $business->instagramAccount?->delete();

        return response()->json(['message' => 'Cuenta desconectada.']);
    }

    /**
     * @return array<string, mixed>|null
     *
     * El token NUNCA sale de aca. Ni recortado ni con asteriscos: un token
     * parcial sigue siendo mas de lo que una pantalla necesita, y basta con
     * saber que hay uno y hasta cuando sirve.
     */
    private function detail(?BusinessSocialAccount $account): ?array
    {
        if ($account === null) {
            return null;
        }

        return [
            'external_id' => $account->external_id,
            'username' => $account->username,
            'is_active' => $account->is_active,
            'can_publish' => $account->isUsable(),
            'expires_soon' => $account->expiresSoon(),
            'expires_at' => $account->token_expires_at?->toIso8601String(),
            'last_published_at' => $account->last_published_at?->toIso8601String(),
            'connected_by' => $account->connectedBy?->name,
            'reason' => $account->unusableReason(),
        ];
    }
}
