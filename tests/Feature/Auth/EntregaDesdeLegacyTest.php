<?php

namespace Tests\Feature\Auth;

use App\Models\Business;
use App\Models\User;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * El puente desde el sistema viejo de Luxury.
 *
 * Quien ya se identificó en `luxurynails.com.co` entra a la aplicación
 * nueva sin volver a escribir su clave. Lo que se prueba acá es que el
 * atajo no se convierta en una puerta: el pase dura poco, se gasta al
 * usarse, y la llave compartida no viaja nunca al navegador.
 */
class EntregaDesdeLegacyTest extends TestCase
{
    use RefreshDatabase;

    private const LLAVE = 'llave-compartida-de-prueba';

    private Business $business;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        PermissionCatalog::sync();
        config()->set('services.legacy_handoff.key', self::LLAVE);

        $this->business = Business::create([
            'name' => 'Luxury Nails',
            'slug' => 'luxury-nails',
            'timezone' => 'America/Bogota',
            'is_active' => true,
        ]);

        $this->user = User::create([
            'business_id' => $this->business->id,
            'name' => 'Marcela',
            'email' => 'marcela@luxurynails.com.co',
            'password' => bcrypt('lo-que-sea'),
            'is_active' => true,
        ]);
    }

    /** @param array<string, mixed> $datos */
    private function emitir(array $datos, ?string $llave = self::LLAVE)
    {
        $headers = $llave === null ? [] : ['X-Legacy-Key' => $llave];

        return $this->withHeaders($headers)->postJson('/api/v1/auth/legacy/emitir', $datos);
    }

    public function test_el_sistema_viejo_entrega_a_alguien_que_si_tiene_cuenta(): void
    {
        $ticket = $this->emitir(['email' => 'marcela@luxurynails.com.co'])
            ->assertOk()
            ->json('ticket');

        $this->assertNotEmpty($ticket);

        $respuesta = $this->postJson('/api/v1/auth/legacy/canjear', [
            'ticket' => $ticket,
            'device_name' => 'navegador',
        ])->assertOk();

        $this->assertNotEmpty($respuesta->json('token'));
        $this->assertSame('marcela@luxurynails.com.co', $respuesta->json('user.email'));
    }

    public function test_el_pase_se_gasta_al_usarse(): void
    {
        /*
         * Viaja en la URL, así que va a quedar en el historial del navegador
         * y en los registros de cualquier proxy por el que pase. Que sirva
         * una sola vez es lo que hace que eso no importe.
         */
        $ticket = $this->emitir(['email' => 'marcela@luxurynails.com.co'])->json('ticket');

        $this->postJson('/api/v1/auth/legacy/canjear', [
            'ticket' => $ticket, 'device_name' => 'navegador',
        ])->assertOk();

        $this->postJson('/api/v1/auth/legacy/canjear', [
            'ticket' => $ticket, 'device_name' => 'otro navegador',
        ])->assertStatus(401);
    }

    public function test_sin_la_llave_compartida_no_se_emite_nada(): void
    {
        $this->emitir(['email' => 'marcela@luxurynails.com.co'], llave: null)->assertStatus(401);
        $this->emitir(['email' => 'marcela@luxurynails.com.co'], llave: 'otra-llave')->assertStatus(401);
    }

    public function test_un_pase_inventado_no_sirve(): void
    {
        $this->postJson('/api/v1/auth/legacy/canjear', [
            'ticket' => str_repeat('a', 64),
            'device_name' => 'navegador',
        ])->assertStatus(401);
    }

    public function test_quien_no_tiene_cuenta_aca_se_queda_alla(): void
    {
        /*
         * De los diecinueve usuarios del sistema viejo solo tres se
         * migraron: el resto ya no trabaja en el salón. Para ellos esto no
         * es un error que haya que arreglar, es que no hay a dónde
         * llevarlos, y el sistema viejo los deja donde estaban.
         */
        $this->emitir(['email' => 'karen@luxurynails.com.co'])->assertStatus(404);
    }

    public function test_una_cuenta_desactivada_no_entra(): void
    {
        $this->user->update(['is_active' => false]);

        $this->emitir(['email' => 'marcela@luxurynails.com.co'])->assertStatus(403);
    }

    public function test_sin_llave_configurada_la_puerta_no_existe(): void
    {
        // El interruptor: mientras no haya `LEGACY_HANDOFF_KEY` en el
        // entorno, no hay forma de entrar por acá ni equivocándose.
        config()->set('services.legacy_handoff.key', null);

        $this->emitir(['email' => 'marcela@luxurynails.com.co'])->assertStatus(503);
    }
}
