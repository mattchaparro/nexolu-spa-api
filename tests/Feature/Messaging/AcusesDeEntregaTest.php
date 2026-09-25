<?php

namespace Tests\Feature\Messaging;

use App\Models\Business;
use App\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Scheduling\SchedulingScenario;
use Tests\TestCase;

/**
 * Lo que pasó con un mensaje después de que Meta lo aceptó.
 *
 * «Enviado» quería decir aceptado, no entregado. Meta acepta un envío y lo
 * rechaza segundos después, por un aviso aparte: los dos avisos a Marcela
 * figuraban como enviados en el panel mientras Meta los había rechazado.
 */
class AcusesDeEntregaTest extends TestCase
{
    use RefreshDatabase, SchedulingScenario;

    private const SECRET = 'secreto-de-prueba';

    private Business $business;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.comms_core.webhook_secret', self::SECRET);
        $this->business = $this->makeBusiness();
    }

    private function mensajeEnviado(string $wamid): Message
    {
        return Message::withoutGlobalScopes()->create([
            'business_id' => $this->business->id,
            'kind' => Message::KIND_TEAM_BOOKED,
            'direction' => Message::DIRECTION_OUT,
            'to' => '573142305988',
            'body' => 'Te agendaron una cita',
            'status' => Message::STATUS_SENT,
            'sent_at' => now(),
            'provider_message_id' => $wamid,
        ]);
    }

    /** @param array<string, mixed> $estado */
    private function llegaAcuse(array $estado): TestResponse
    {
        $body = json_encode([
            'object' => 'whatsapp_business_account',
            'entry' => [['changes' => [['value' => ['statuses' => [$estado]]]]]],
        ]);
        $timestamp = (string) now()->timestamp;

        return $this->call('POST', '/api/webhooks/nexolu-comms/whatsapp', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_NEXOLU_TIMESTAMP' => $timestamp,
            'HTTP_X_NEXOLU_SIGNATURE' => hash_hmac('sha256', $timestamp.'.'.$body, self::SECRET),
        ], $body);
    }

    public function test_si_meta_lo_rechaza_despues_el_mensaje_queda_fallido(): void
    {
        // El caso de Marcela: aceptado, y rechazado por la ventana.
        $mensaje = $this->mensajeEnviado('wamid.marcela');

        $this->llegaAcuse([
            'id' => 'wamid.marcela',
            'status' => 'failed',
            'timestamp' => (string) now()->timestamp,
            'recipient_id' => '573142305988',
            'errors' => [['code' => 131047, 'title' => 'Re-engagement message']],
        ])->assertOk();

        $mensaje->refresh();
        $this->assertSame(Message::STATUS_FAILED, $mensaje->status);
        $this->assertStringContainsString('24 horas', (string) $mensaje->error);
    }

    public function test_entregado_y_leido_quedan_anotados(): void
    {
        $mensaje = $this->mensajeEnviado('wamid.carolina');

        $this->llegaAcuse(['id' => 'wamid.carolina', 'status' => 'delivered', 'timestamp' => (string) now()->timestamp]);
        $this->llegaAcuse(['id' => 'wamid.carolina', 'status' => 'read', 'timestamp' => (string) now()->timestamp]);

        $mensaje->refresh();
        $this->assertSame(Message::STATUS_SENT, $mensaje->status);
        $this->assertNotNull($mensaje->delivered_at);
        $this->assertNotNull($mensaje->read_at);
    }

    public function test_si_solo_llega_el_leido_tambien_cuenta_como_entregado(): void
    {
        $mensaje = $this->mensajeEnviado('wamid.jenny');

        $this->llegaAcuse(['id' => 'wamid.jenny', 'status' => 'read', 'timestamp' => (string) now()->timestamp]);

        $this->assertNotNull($mensaje->fresh()->delivered_at);
    }

    public function test_un_acuse_de_un_mensaje_ajeno_no_rompe_nada(): void
    {
        // Otra aplicación suscrita al mismo número: no es nuestro, se ignora.
        $this->llegaAcuse(['id' => 'wamid.de.otra.app', 'status' => 'failed', 'errors' => [['code' => 131047]]])
            ->assertOk();

        $this->assertSame(0, Message::withoutGlobalScopes()->where('status', Message::STATUS_FAILED)->count());
    }
}
