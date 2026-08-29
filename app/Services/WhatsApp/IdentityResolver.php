<?php

namespace App\Services\WhatsApp;

use App\Models\AiChannelIdentity;
use App\Models\User;

/**
 * Resuelve el usuario dueño de un identificador de canal externo verificado
 * (hoy solo WhatsApp). Sin usuario autenticado, BelongsToBusiness no aplica
 * scope - correcto aqui: este metodo corre desde un job de cola, antes de
 * que exista ninguna sesion.
 */
class IdentityResolver
{
    public function resolveUser(string $channel, string $externalId): ?User
    {
        $identity = AiChannelIdentity::query()
            ->where('channel', $channel)
            ->where('external_id', $externalId)
            ->whereNotNull('verified_at')
            ->first();

        if ($identity === null) {
            return null;
        }

        $user = $identity->user;

        // Usuario desactivado no opera por WhatsApp aunque el vinculo siga.
        if ($user === null || ! $user->is_active) {
            return null;
        }

        return $user;
    }
}
