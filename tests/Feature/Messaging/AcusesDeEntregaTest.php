<?php

namespace Tests\Feature\Messaging;

use App\Models\Business;
use App\Models\Message;
use App\Services\Messaging\Contracts\MessagingChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Scheduling\SchedulingScenario;
use Tests\Support\FakeMessagingChannel;
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

    /** Un aviso que salió como texto y que trae plantilla para cuando haga falta. */
    private function textoConPlantilla(string $wamid): Message
    {
        $this->business->update(['messaging_mode' => 'auto']);

        return Message::withoutGlobalScopes()->create([
            'business_id' => $this->business->id,
            'kind' => Message::KIND_TEAM_BOOKED,
            'direction' => Message::DIRECTION_OUT,
            'to' => '573142305988',
            'body' => 'Te agendaron una cita',
            'template_name' => 'cita_nueva_equipo',
            'template_language' => 'es',
            'template_params' => ['Marcela', 'Carolina', 'Pedicure', 'Viernes 25', '6:00 pm'],
            'status' => Message::STATUS_SENT,
            'sent_at' => now(),
            'attempts' => 1,
            'provider_message_id' => $wamid,
        ]);
    }

    private function rechazoPorLaVentana(string $wamid): TestResponse
    {
        return $this->llegaAcuse([
            'id' => $wamid,
            'status' => 'failed',
            'timestamp' => (string) now()->timestamp,
            'errors' => [['code' => 131047, 'title' => 'Re-engagement message']],
        ]);
    }

    private function conCanal(): FakeMessagingChannel
    {
        $canal = new FakeMessagingChannel;
        $this->app->instance(MessagingChannel::class, $canal);

        return $canal;
    }

    public function test_si_meta_lo_rechaza_por_la_ventana_sale_otra_vez_como_plantilla(): void
    {
        /*
         * Lo de Marcela sin que nadie tenga que hacer nada: el texto se
         * rechazó por la ventana, y la plantilla --que Meta sí entrega--
         * sale sola.
         */
        $canal = $this->conCanal();
        $mensaje = $this->textoConPlantilla('wamid.marcela');

        $this->rechazoPorLaVentana('wamid.marcela')->assertOk();

        $this->assertCount(1, $canal->sent);
        $this->assertSame('cita_nueva_equipo', $canal->sent[0]['template']);
        $this->assertSame(['Marcela', 'Carolina', 'Pedicure', 'Viernes 25', '6:00 pm'], $canal->sent[0]['params']);
        // Otra clave: con la del texto, Connect devolvería el envío rechazado.
        $this->assertSame('spa-msg:'.$mensaje->id.':plantilla', $canal->sent[0]['idempotency_key']);

        $mensaje->refresh();
        $this->assertSame(Message::STATUS_SENT, $mensaje->status);
        $this->assertSame('wamid.prueba.1', $mensaje->provider_message_id);
    }

    public function test_si_meta_repite_el_rechazo_la_plantilla_sale_una_sola_vez(): void
    {
        $canal = $this->conCanal();
        $this->textoConPlantilla('wamid.marcela');

        $this->rechazoPorLaVentana('wamid.marcela');
        $this->rechazoPorLaVentana('wamid.marcela');

        $this->assertCount(1, $canal->sent);
    }

    public function test_sin_plantilla_no_hay_nada_que_reintentar(): void
    {
        // Una respuesta del bot: solo texto. Queda fallida con su motivo.
        $canal = $this->conCanal();
        $mensaje = $this->mensajeEnviado('wamid.bot');

        $this->rechazoPorLaVentana('wamid.bot');

        $this->assertSame([], $canal->sent);
        $this->assertSame(Message::STATUS_FAILED, $mensaje->fresh()->status);
    }

    public function test_otro_rechazo_no_se_reintenta(): void
    {
        // Un número que no tiene WhatsApp no se arregla mandando plantilla.
        $canal = $this->conCanal();
        $mensaje = $this->textoConPlantilla('wamid.sin.whatsapp');

        $this->llegaAcuse([
            'id' => 'wamid.sin.whatsapp',
            'status' => 'failed',
            'errors' => [['code' => 131026, 'title' => 'Message undeliverable']],
        ]);

        $this->assertSame([], $canal->sent);
        $this->assertSame(Message::STATUS_FAILED, $mensaje->fresh()->status);
    }
}
