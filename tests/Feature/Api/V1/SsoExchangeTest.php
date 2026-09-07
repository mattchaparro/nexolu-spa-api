<?php

namespace Tests\Feature\Api\V1;

use App\Models\User;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Canje de una asercion de nexolu-auth por un token de Sanctum.
 *
 * Lo que estas pruebas cuidan es la frontera entre productos: una asercion
 * emitida para el POS no puede abrir la agenda. Y la reversibilidad: sin
 * llave configurada el canje muere pero POST /v1/login sigue igual, que es
 * el interruptor para apagar el SSO sin dejar a nadie afuera.
 */
class SsoExchangeTest extends TestCase
{
    use RefreshDatabase;

    private const ISSUER = 'https://auth.nexolu.test';

    private const AUDIENCE = 'nexolu-spa-api';

    private const KID = 'test-kid';

    private static string $privateKey;

    private static string $publicKey;

    private static string $otraPrivateKey;

    private User $plataforma;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        // Generadas una vez para toda la clase: un par RSA de 2048 bits
        // cuesta cientos de milisegundos y lo que hay que aislar entre
        // pruebas es la base, no el material criptografico.
        [self::$privateKey, self::$publicKey] = self::generarPar();
        [self::$otraPrivateKey] = self::generarPar();
    }

    /**
     * @return array{0: string, 1: string}
     */
    private static function generarPar(): array
    {
        $resource = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        openssl_pkey_export($resource, $private);
        $public = openssl_pkey_get_details($resource)['key'];

        return [$private, $public];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->plataforma = User::create([
            'business_id' => null,
            'name' => 'Plataforma',
            'email' => 'plataforma@nexolu.test',
            'password' => Hash::make('password123'),
            'is_super_admin' => true,
            'is_active' => true,
        ]);

        $this->configurarSso();
    }

    private function configurarSso(?string $publicKey = null): void
    {
        config()->set('services.nexolu_auth.issuer', self::ISSUER);
        config()->set('services.nexolu_auth.audience', self::AUDIENCE);
        config()->set('services.nexolu_auth.email_fallback', true);
        config()->set('services.nexolu_auth.public_keys', json_encode([
            self::KID => base64_encode($publicKey ?? self::$publicKey),
        ]));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function asercion(array $overrides = [], ?string $key = null): string
    {
        $now = time();

        $claims = array_merge([
            'iss' => self::ISSUER,
            'aud' => self::AUDIENCE,
            'sub' => 'identidad-de-prueba',
            'iat' => $now,
            'nbf' => $now,
            'exp' => $now + 120,
            'jti' => Str::random(32),
            'typ' => 'sso',
            'email' => $this->plataforma->email,
            'name' => 'Plataforma',
            'account' => ['user_id' => (string) $this->plataforma->id],
            'scope' => 'superadmin',
        ], $overrides);

        return JWT::encode($claims, $key ?? self::$privateKey, 'RS256', self::KID);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function canjear(array $overrides = [], ?string $key = null): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/v1/auth/sso/exchange', [
            'assertion' => $this->asercion($overrides, $key),
            'device_name' => 'navegador',
        ]);
    }

    public function test_una_asercion_valida_devuelve_un_token_usable(): void
    {
        $response = $this->canjear();

        $response->assertOk()->assertJsonStructure(['token', 'user' => ['id', 'email']]);

        // El token del canje es un PAT de Sanctum normal: si esto falla, el
        // canje estaria emitiendo algo que el resto de la API no entiende.
        $this->withHeader('Authorization', 'Bearer '.$response->json('token'))
            ->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('id', $this->plataforma->id);
    }

    public function test_la_respuesta_tiene_la_misma_forma_que_el_login(): void
    {
        // El front hace lo mismo con las dos respuestas; si divergen, una de
        // las dos rutas rompe en silencio.
        $delLogin = $this->postJson('/api/v1/login', [
            'email' => $this->plataforma->email,
            'password' => 'password123',
            'device_name' => 'navegador',
        ])->assertOk()->json();

        $delCanje = $this->canjear()->assertOk()->json();

        $this->assertSame(array_keys($delLogin), array_keys($delCanje));
        $this->assertSame($delLogin['user'], $delCanje['user']);
    }

    public function test_resuelve_por_el_vinculo_explicito(): void
    {
        // Correo distinto del de la fila: si resolviera por correo, esto
        // fallaria. Prueba que `account.user_id` manda.
        $this->canjear(['email' => 'otro-correo@nexolu.test'])
            ->assertOk()
            ->assertJsonPath('user.id', $this->plataforma->id);
    }

    public function test_sin_vinculo_cae_al_fallback_por_correo(): void
    {
        $this->canjear(['account' => null])
            ->assertOk()
            ->assertJsonPath('user.id', $this->plataforma->id);
    }

    public function test_con_el_fallback_apagado_y_sin_vinculo_da_403(): void
    {
        config()->set('services.nexolu_auth.email_fallback', false);

        $this->canjear(['account' => null])->assertForbidden();
    }

    public function test_una_identidad_sin_cuenta_aca_da_403_y_no_401(): void
    {
        // 403 terminal: con 401 el front creeria que la asercion vencio,
        // rebotaria a nexolu-auth (que tiene cookie viva), recibiria otra, y
        // el usuario quedaria en un bucle sin ver nunca un formulario.
        $this->canjear([
            'account' => null,
            'email' => 'nadie@nexolu.test',
        ])->assertForbidden();
    }

    public function test_un_usuario_que_no_es_superadmin_no_canjea(): void
    {
        $admin = User::create([
            'business_id' => null,
            'name' => 'Admin de negocio',
            'email' => 'admin@negocio.test',
            'password' => Hash::make('password123'),
            'is_super_admin' => false,
            'is_active' => true,
        ]);

        $this->canjear(['account' => ['user_id' => (string) $admin->id]])->assertForbidden();

        // Y no se emitio ningun token: una asercion robada a nombre de un
        // usuario de negocio no sirve para nada en la Fase 1.
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_una_cuenta_desactivada_no_canjea(): void
    {
        $this->plataforma->update(['is_active' => false]);

        $this->canjear()->assertForbidden();
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_una_asercion_de_otro_producto_no_sirve_aca(): void
    {
        // El test mas importante del archivo: JWT::decode() NO valida `aud`
        // por su cuenta. Si alguien quita el chequeo manual del
        // controlador, esto es lo unico que lo detecta.
        $this->canjear(['aud' => 'nexolu-pos-api'])->assertUnauthorized();
    }

    public function test_un_emisor_distinto_no_sirve(): void
    {
        // Mismo caso: `iss` tampoco lo valida la libreria.
        $this->canjear(['iss' => 'https://impostor.example.com'])->assertUnauthorized();
    }

    public function test_una_asercion_vencida_no_sirve(): void
    {
        $this->canjear(['iat' => time() - 600, 'nbf' => time() - 600, 'exp' => time() - 480])
            ->assertUnauthorized();
    }

    public function test_una_asercion_de_otra_llave_no_sirve(): void
    {
        $this->canjear([], self::$otraPrivateKey)->assertUnauthorized();
    }

    public function test_un_tipo_distinto_de_asercion_no_sirve(): void
    {
        $this->canjear(['typ' => 'otra-cosa'])->assertUnauthorized();
    }

    public function test_alg_none_no_sirve(): void
    {
        $header = rtrim(strtr(base64_encode(json_encode(
            ['alg' => 'none', 'typ' => 'JWT', 'kid' => self::KID]
        )), '+/', '-_'), '=');
        $payload = rtrim(strtr(base64_encode(json_encode([
            'iss' => self::ISSUER, 'aud' => self::AUDIENCE, 'sub' => 'x',
            'iat' => time(), 'exp' => time() + 120, 'jti' => Str::random(32),
            'typ' => 'sso', 'email' => $this->plataforma->email,
        ])), '+/', '-_'), '=');

        $this->postJson('/api/v1/auth/sso/exchange', [
            'assertion' => "{$header}.{$payload}.",
            'device_name' => 'navegador',
        ])->assertUnauthorized();
    }

    public function test_una_firma_alterada_no_sirve(): void
    {
        $valida = $this->asercion();
        $alterada = substr($valida, 0, -6).'AAAAAA';

        $this->postJson('/api/v1/auth/sso/exchange', [
            'assertion' => $alterada,
            'device_name' => 'navegador',
        ])->assertUnauthorized();
    }

    public function test_la_misma_asercion_no_se_canjea_dos_veces(): void
    {
        // Queda en el historial del navegador; volver atras no puede volver
        // a entrar.
        $assertion = $this->asercion();
        $cuerpo = ['assertion' => $assertion, 'device_name' => 'navegador'];

        $this->postJson('/api/v1/auth/sso/exchange', $cuerpo)->assertOk();
        $this->postJson('/api/v1/auth/sso/exchange', $cuerpo)->assertUnauthorized();
    }

    public function test_sin_llave_configurada_el_login_propio_sigue_vivo(): void
    {
        // LA prueba de reversibilidad: vaciar NEXOLU_AUTH_PUBLIC_KEYS apaga
        // el SSO sin dejar a nadie fuera de la agenda.
        config()->set('services.nexolu_auth.public_keys', '{}');

        $this->canjear()->assertStatus(503);

        $this->postJson('/api/v1/login', [
            'email' => $this->plataforma->email,
            'password' => 'password123',
            'device_name' => 'navegador',
        ])->assertOk()->assertJsonStructure(['token']);
    }

    public function test_un_pem_mal_pegado_nombra_la_variable(): void
    {
        // Pegar un PEM multilinea en un .env de una linea es el error mas
        // facil de cometer; el mensaje tiene que apuntar al archivo.
        config()->set('services.nexolu_auth.public_keys', json_encode([
            self::KID => 'esto-no-es-base64!!',
        ]));

        $this->canjear()
            ->assertStatus(503)
            ->assertJsonPath('message', fn ($m) => str_contains((string) $m, 'NEXOLU_AUTH_PUBLIC_KEYS'));
    }
}
