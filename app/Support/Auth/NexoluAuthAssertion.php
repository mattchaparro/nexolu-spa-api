<?php

namespace App\Support\Auth;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Verifica LOCALMENTE una asercion emitida por nexolu-auth.
 *
 * Local de verdad: la llave publica viaja fijada en NEXOLU_AUTH_PUBLIC_KEYS
 * y esta clase nunca hace una peticion de red. Un JWKS consultado en
 * caliente pondria a nexolu-auth en el camino de cada login, que es
 * exactamente lo que el diseno evita -- y su primer fetch caeria justo
 * despues de un deploy, cuando el contenedor acaba de reiniciar.
 *
 * La asercion vale 120 segundos y sirve para UN solo producto: el claim
 * `aud` tiene que ser el de esta API, no el de otra.
 */
final class NexoluAuthAssertion
{
    private const ALGORITHM = 'RS256';

    /**
     * Margen para deriva de reloj entre este droplet y el de nexolu-auth.
     * Sin esto, una asercion de 120 s puede nacer vencida contra un
     * servidor con la hora corrida, y el sintoma seria "el SSO no
     * funciona" sin nada que apunte al reloj.
     */
    private const LEEWAY_SECONDS = 60;

    private function __construct(
        public readonly string $subject,
        public readonly string $email,
        public readonly ?string $externalUserId,
        public readonly string $jti,
    ) {}

    /**
     * @throws SsoNotConfigured   cuando no hay llave publica configurada (503)
     * @throws InvalidAssertion   cuando la asercion no es valida (401)
     */
    public static function verify(string $assertion): self
    {
        $keys = self::publicKeys();

        if ($keys === []) {
            throw new SsoNotConfigured(
                'El acceso con Nexolu no esta configurado (falta NEXOLU_AUTH_PUBLIC_KEYS).'
            );
        }

        JWT::$leeway = self::LEEWAY_SECONDS;

        try {
            // decode() valida la firma y las fechas, y `$keys` indexado por
            // kid mas el algoritmo explicito cierran la confusion de
            // algoritmos (alg:none, o HS256 usando la llave publica como
            // secreto).
            $claims = JWT::decode($assertion, $keys);
        } catch (Throwable $error) {
            throw new InvalidAssertion($error->getMessage(), previous: $error);
        }

        // OJO: JWT::decode NO valida `aud` ni `iss` (verificado en el
        // codigo de firebase/php-jwt v7). Sin estos dos chequeos a mano,
        // una asercion emitida para otro producto -- o por un emisor
        // cualquiera -- entraria aca como valida.
        $expectedIssuer = (string) config('services.nexolu_auth.issuer');
        $expectedAudience = (string) config('services.nexolu_auth.audience');

        if (($claims->iss ?? null) !== $expectedIssuer) {
            throw new InvalidAssertion('El emisor de la asercion no es el esperado.');
        }

        if (($claims->aud ?? null) !== $expectedAudience) {
            throw new InvalidAssertion('La asercion fue emitida para otro producto.');
        }

        if (($claims->typ ?? null) !== 'sso') {
            throw new InvalidAssertion('La asercion no es de tipo sso.');
        }

        $jti = (string) ($claims->jti ?? '');

        if ($jti === '') {
            throw new InvalidAssertion('La asercion no trae jti.');
        }

        self::assertNotReplayed($jti);

        return new self(
            subject: (string) ($claims->sub ?? ''),
            email: strtolower(trim((string) ($claims->email ?? ''))),
            externalUserId: isset($claims->account->user_id)
                ? (string) $claims->account->user_id
                : null,
            jti: $jti,
        );
    }

    /**
     * Una asercion se canjea UNA vez.
     *
     * Vive 120 s en el fragmento de la URL y queda en el historial del
     * navegador; sin esto, volver atras la reutilizaria. Cache::add es
     * atomico, asi que dos peticiones simultaneas no pueden ganar las dos.
     */
    private static function assertNotReplayed(string $jti): void
    {
        if (! Cache::add('sso:jti:'.$jti, true, now()->addMinutes(5))) {
            throw new InvalidAssertion('Esta asercion ya se uso.');
        }
    }

    /**
     * @return array<string, Key>
     */
    private static function publicKeys(): array
    {
        $raw = (string) config('services.nexolu_auth.public_keys');
        $decoded = json_decode($raw !== '' ? $raw : '{}', true);

        if (! is_array($decoded)) {
            throw new SsoNotConfigured(
                'NEXOLU_AUTH_PUBLIC_KEYS no es un JSON valido. Se espera {"kid": "<PEM en base64>"}.'
            );
        }

        $keys = [];

        foreach ($decoded as $kid => $encoded) {
            $pem = base64_decode((string) $encoded, true);

            if ($pem === false || ! str_contains($pem, 'BEGIN PUBLIC KEY')) {
                // Nombrar la variable a proposito: pegar un PEM multilinea
                // en un .env de una linea es el error mas facil de cometer
                // aca, y sin este mensaje el sintoma seria "todos los
                // canjes fallan" sin nada que apunte al archivo.
                throw new SsoNotConfigured(
                    "La llave publica '{$kid}' de NEXOLU_AUTH_PUBLIC_KEYS no es un PEM en base64."
                );
            }

            $keys[$kid] = new Key($pem, self::ALGORITHM);
        }

        return $keys;
    }
}
