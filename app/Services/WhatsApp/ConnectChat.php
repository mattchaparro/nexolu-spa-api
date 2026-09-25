<?php

namespace App\Services\WhatsApp;

use App\Models\Client;
use App\Models\User;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * El chat de WhatsApp del salon vive en Nexolu Connect, no aca.
 *
 * El Spa tuvo su propia bandeja y despues mostraba la de Connect en un
 * iframe; las dos se quedaban atras de Connect. Ahora el menu "WhatsApp"
 * abre Connect directamente, con la persona ya adentro como usuaria de SU
 * salon, y Connect le avisa al celular cada vez que alguien escribe.
 *
 * Quien puede entrar lo decide el Spa (permiso `clientes.ver`), porque la
 * cuenta y los permisos de la persona viven aca. Connect solo recorta: le
 * cree al Spa, que se identifica con su API key de servidor a servidor.
 * Esa llave NUNCA baja a un navegador; al navegador solo le llega la URL
 * con un pase de un solo uso.
 */
class ConnectChat
{
    public function isConfigured(): bool
    {
        return ! empty(config('services.comms_core.api_key'))
            && ! empty(config('services.comms_core.base_url'));
    }

    private function http(): PendingRequest
    {
        return Http::withToken((string) config('services.comms_core.api_key'))
            ->acceptJson()
            ->timeout(8)
            ->baseUrl(rtrim((string) config('services.comms_core.base_url'), '/'));
    }

    /**
     * La URL (con pase de un solo uso) que entra a esta persona al chat de
     * su salon. Null si Connect no esta configurado o no respondio.
     */
    public function loginUrl(User $user, string $next = '/chat'): ?string
    {
        if (! $this->isConfigured()) {
            return null;
        }

        try {
            $response = $this->http()->post('/v1/app-users/login-ticket', [
                'user_ref' => (string) $user->id,
                'business_id' => (string) $user->business_id,
                'full_name' => trim($user->name.' '.($user->last_name ?? '')),
                'next' => $next,
            ]);
        } catch (\Throwable $e) {
            Log::warning('connect_chat: no se pudo pedir el pase', ['error' => $e->getMessage()]);

            return null;
        }

        if (! $response->successful()) {
            Log::warning('connect_chat: Connect rechazo el pase', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return null;
        }

        return $response->json('url');
    }

    /**
     * Esta persona ya no atiende el chat (la desactivaron, la borraron o le
     * quitaron `clientes.ver`): Connect le cierra la sesion y deja de
     * avisarle al celular. Lanza si falla, para que el job reintente.
     */
    public function revoke(int $userId): void
    {
        if (! $this->isConfigured()) {
            return;
        }

        $this->http()->delete('/v1/app-users/'.$userId)->throw();
    }

    /**
     * El nombre de la clienta, para que el chat de Connect la muestre como
     * la conocemos acá y no como su perfil de WhatsApp ("." o un emoji).
     * Si Connect no la conoce (nunca escribió), no pasa nada. Lanza si
     * falla, para que el job reintente.
     */
    public function renameContact(Client $client): void
    {
        if (! $this->isConfigured() || empty($client->phone)) {
            return;
        }

        $this->http()->patch('/v1/contacts', [
            'phone' => (string) $client->phone,
            'business_id' => (string) $client->business_id,
            'name' => trim($client->name.' '.($client->last_name ?? '')),
        ])->throw();
    }

    /**
     * Cuantas conversaciones del salon esperan respuesta: el numerito del
     * menu. Cacheado 30 s por salon -- el menu lo pide cada minuto por
     * persona conectada -- y 0 si Connect no responde: un contador que
     * falla no puede tumbar el menu.
     */
    public function unreadCount(int $businessId): int
    {
        if (! $this->isConfigured()) {
            return 0;
        }

        return Cache::remember("connect_unread:{$businessId}", 30, function () use ($businessId) {
            try {
                $response = $this->http()->timeout(4)->get('/v1/app-users/unread', [
                    'business_id' => (string) $businessId,
                ]);
            } catch (\Throwable $e) {
                Log::warning('connect_chat: sin contador de no leidos', ['error' => $e->getMessage()]);

                return 0;
            }

            return $response->successful() ? (int) $response->json('unread', 0) : 0;
        });
    }
}
