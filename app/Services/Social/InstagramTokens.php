<?php

namespace App\Services\Social;

use App\Models\BusinessSocialAccount;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Los tokens de Instagram: comprobarlos y renovarlos antes de que caduquen.
 *
 * EL PROBLEMA QUE RESUELVE ES QUE UN TOKEN VENCIDO NO AVISA. Meta da tokens de
 * larga duracion de sesenta dias. El dia sesenta y uno las publicaciones
 * simplemente dejan de salir, y nadie revisa una cuenta que "funcionaba": el
 * negocio se entera semanas despues, cuando nota que hace mucho no publica
 * nada. Por eso se guarda la fecha de vencimiento y se renueva sola.
 */
class InstagramTokens
{
    /**
     * Con cuantos dias de anticipacion se renueva.
     *
     * Meta exige que el token tenga al menos 24 horas de vida para poder
     * renovarlo, asi que esperar al ultimo dia es quedarse sin margen si la
     * corrida de ese dia falla. Siete deja seis reintentos.
     */
    public const RENEW_WITHIN_DAYS = 7;

    /**
     * Comprueba unas credenciales y devuelve lo que Meta dice de ellas.
     *
     * Se usa al conectar: un token mal copiado guardado en silencio es una
     * publicacion que falla dentro de tres semanas, cuando nadie se acuerde de
     * esto.
     *
     * @return array{username: ?string, expires_at: ?CarbonImmutable}|null
     */
    public function describe(string $externalId, string $token): ?array
    {
        try {
            $cuenta = Http::timeout(20)->get($this->url($externalId), [
                'fields' => 'username',
                'access_token' => $token,
            ]);
        } catch (ConnectionException $e) {
            Log::warning('Instagram: no se pudo verificar el token', ['error' => $e->getMessage()]);

            return null;
        }

        if ($cuenta->failed()) {
            Log::warning('Instagram: credenciales rechazadas', [
                'status' => $cuenta->status(),
                'message' => $cuenta->json('error.message'),
            ]);

            return null;
        }

        return [
            'username' => $cuenta->json('username'),
            'expires_at' => $this->expiryOf($token),
        ];
    }

    /**
     * Renueva el token de una cuenta que esta por vencer.
     *
     * Devuelve false tambien cuando NO hacia falta renovar. Quien llama no
     * necesita distinguir "no tocaba" de "fallo": lo que importa es cuantas se
     * renovaron, y los fallos quedan en el log con su motivo.
     */
    public function renew(BusinessSocialAccount $account): bool
    {
        if (! $this->needsRenewal($account)) {
            return false;
        }

        try {
            $response = Http::timeout(20)->get($this->url('oauth/access_token'), [
                'grant_type' => 'fb_exchange_token',
                'client_id' => config('services.instagram.app_id'),
                'client_secret' => config('services.instagram.app_secret'),
                'fb_exchange_token' => $account->access_token,
            ]);
        } catch (ConnectionException $e) {
            Log::warning('Instagram: error de red al renovar', ['error' => $e->getMessage()]);

            return false;
        }

        $nuevo = $response->json('access_token');

        if ($response->failed() || ! $nuevo) {
            /*
             * No se borra el token viejo. Todavia sirve hasta que venza, y
             * quedarse sin nada porque una renovacion fallo seria cambiar un
             * problema de la semana que viene por uno de ahora mismo.
             */
            Log::warning('Instagram: no se pudo renovar el token', [
                'business_id' => $account->business_id,
                'message' => $response->json('error.message'),
            ]);

            return false;
        }

        $account->forceFill([
            'access_token' => $nuevo,
            'token_expires_at' => $this->expiresIn($response->json('expires_in')),
        ])->save();

        return true;
    }

    public function needsRenewal(BusinessSocialAccount $account): bool
    {
        if (! $account->is_active || $account->hasExpired()) {
            // Uno ya vencido no se renueva: Meta lo rechaza y hay que volver a
            // conectar la cuenta. El panel lo dice.
            return false;
        }

        return $account->token_expires_at === null
            || $account->token_expires_at->lessThan(CarbonImmutable::now()->addDays(self::RENEW_WITHIN_DAYS));
    }

    /**
     * Cuando vence un token, preguntandoselo a Meta.
     *
     * Se pregunta en vez de asumir sesenta dias: hay tokens que no vencen
     * nunca -- los de pagina derivados de un usuario de larga duracion -- y
     * ponerles una fecha inventada haria que el panel avisara de un
     * vencimiento que no existe.
     */
    private function expiryOf(string $token): ?CarbonImmutable
    {
        $appId = config('services.instagram.app_id');
        $appSecret = config('services.instagram.app_secret');

        if (empty($appId) || empty($appSecret)) {
            // Sin las credenciales de la app no se puede inspeccionar. Se deja
            // en nulo, que significa "no se sabe", y el modulo funciona igual.
            return null;
        }

        try {
            $response = Http::timeout(20)->get($this->url('debug_token'), [
                'input_token' => $token,
                'access_token' => $appId.'|'.$appSecret,
            ]);
        } catch (ConnectionException) {
            return null;
        }

        $expira = (int) ($response->json('data.expires_at') ?? 0);

        // 0 significa "no vence". Es un valor real de Meta, no un fallo.
        return $expira === 0 ? null : CarbonImmutable::createFromTimestamp($expira);
    }

    private function expiresIn(mixed $seconds): ?CarbonImmutable
    {
        $seconds = (int) $seconds;

        return $seconds > 0 ? CarbonImmutable::now()->addSeconds($seconds) : null;
    }

    private function url(string $path): string
    {
        return rtrim((string) config('services.instagram.graph_url'), '/')
            .'/'.config('services.instagram.graph_version')
            .'/'.$path;
    }
}
