<?php

namespace App\Services\Ia;

use App\Models\User;
use App\Support\PermissionCatalog;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * El asistente del panel: quien administra le pregunta en palabras.
 *
 * «¿Cuánto vendí hoy?», «¿qué servicios se hicieron más esta semana?»,
 * «bloquéale a Alejandra el viernes de 5 a 6». Es el mismo IA Core del bot
 * de WhatsApp y del asistente del POS, con otro agente (`administrador`) y
 * herramientas de empleada: las que pide permisos las filtra el IA Core con
 * los permisos que viajan acá, y el Spa los vuelve a revisar al invocarlas.
 *
 * Lo que escribe (un bloqueo) no se ejecuta al pedirlo: el IA Core arma un
 * borrador que la persona confirma en una tarjeta (ver `confirm`).
 */
class PanelAssistant
{
    public const AGENT = 'administrador';

    public function __construct(private readonly BusinessProfile $profile) {}

    /** @return array<string, mixed> */
    public function ask(User $user, string $message, ?string $conversationId = null): array
    {
        return $this->post('/v1/chat', [
            'agent' => self::AGENT,
            'message' => $message,
            'conversation_id' => $conversationId,
            'context' => $this->context($user),
        ])->json();
    }

    public function confirm(User $user, string $draftId, ?array $values = null): Response
    {
        return $this->post("/v1/drafts/{$draftId}/confirm", array_filter([
            'context' => $this->context($user),
            'values' => $values,
        ], fn ($v) => $v !== null), false);
    }

    public function discard(User $user, string $draftId): Response
    {
        return $this->post("/v1/drafts/{$draftId}/discard", ['context' => $this->context($user)], false);
    }

    /**
     * Quién pregunta: su negocio, sus permisos y sus funciones.
     *
     * @return array<string, mixed>
     */
    public function context(User $user): array
    {
        $business = $user->business;

        return [
            'business_id' => (string) $business->id,
            'user_id' => (string) $user->id,
            'is_admin' => $user->hasRole(PermissionCatalog::ROLE_ADMIN),
            'permissions' => array_values(array_filter(
                PermissionCatalog::names(),
                fn (string $p) => $user->hasBusinessPermission($p),
            )),
            'features' => array_keys(array_filter($business->feature_flags ?? [])),
            'channel' => 'panel',
            'business_profile' => $this->profile->for($business),
            'user_profile' => 'Te escribe '.$user->name.', del equipo del negocio, desde el panel.',
            'timezone' => $business->businessTimezone(),
            'locale' => 'es',
        ];
    }

    /** @param  array<string, mixed>  $payload */
    private function post(string $path, array $payload, bool $throw = true): Response
    {
        $baseUrl = (string) config('services.ia_core.base_url');
        $apiKey = (string) config('services.ia_core.api_key');

        if ($baseUrl === '' || $apiKey === '') {
            throw new RuntimeException('El asistente no está configurado en esta instalación.');
        }

        try {
            $response = Http::withToken($apiKey)->timeout(60)->post(rtrim($baseUrl, '/').$path, $payload);
        } catch (ConnectionException $e) {
            throw new RuntimeException('No se pudo contactar al asistente. Intenta de nuevo.', previous: $e);
        }

        if ($throw && $response->failed()) {
            throw new RuntimeException((string) ($response->json('detail') ?? 'El asistente no pudo responder.'));
        }

        return $response;
    }
}
