<?php

namespace Tests\Feature\Whatsapp;

use App\Ai\GuidedEntry;
use App\Ai\OpcionesEnviadas;
use App\Ai\UltimoPedido;
use App\Jobs\AnswerWhatsappMessageJob;
use App\Models\Client;
use App\Models\Message;
use App\Models\WhatsappConversation;
use App\Support\ChannelPhone;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Scheduling\SchedulingScenario;
use Tests\TestCase;

/**
 * El job que contesta, de punta a punta, turno tras turno.
 *
 * Las pruebas de Toques y GuidedEntry llamaban a esas clases directo y
 * nunca pasaban por el job -- y el hueco estaba en el job: la marca de
 * "ya salieron botones" quedaba viva del turno anterior y se tragaba la
 * respuesta siguiente. Alejandro tocó «Agendar en la web» y no recibió
 * el link. Aquí cada turno entra como entra en producción.
 */
class AnswerJobTest extends TestCase
{
    use RefreshDatabase, SchedulingScenario;

    private const PHONE = '573001112233';

    private WhatsappConversation $conversacion;

    protected function setUp(): void
    {
        parent::setUp();

        PermissionCatalog::sync();
        config()->set('services.comms_core.api_key', 'llave-comms');
        config()->set('services.comms_core.base_url', 'http://comms.test');
        config()->set('services.ia_core.api_key', 'llave-ia');
        config()->set('services.ia_core.base_url', 'http://ia-core.test');
        config()->set('spa.public_booking_url', 'https://agenda.test');

        Http::fake([
            'comms.test/*' => Http::response(['results' => [['channel' => 'whatsapp', 'status' => 'sent']]]),
            // Si el modelo llegara a hablar, se nota: no debería en estos turnos.
            'ia-core.test/*' => Http::response(['conversation_id' => 'c1', 'text' => 'RESPUESTA DEL MODELO', 'tools_used' => []]),
        ]);

        $business = $this->makeBusiness();
        $business->forceFill(['slug' => 'luxury', 'messaging_mode' => 'auto', 'whatsapp_phone_number_id' => '111222333'])->save();
        $maria = $this->makeResource($business, 'Maria', '09:00:00', '18:00:00');
        $this->makeService($business, 60, [$maria], name: 'Semipermanente');

        $cliente = Client::create([
            'business_id' => $business->id,
            'name' => 'Mateo',
            'phone' => ChannelPhone::normalize(self::PHONE),
            'is_active' => true,
        ]);

        $this->conversacion = WhatsappConversation::withoutGlobalScope('business')->create([
            'business_id' => $business->id,
            'phone' => ChannelPhone::normalize(self::PHONE),
            'client_id' => $cliente->id,
            'last_message_at' => now(),
            'last_inbound_at' => now(),
            'status' => WhatsappConversation::STATUS_OPEN,
        ]);

        UltimoPedido::olvidar((string) ChannelPhone::normalize(self::PHONE));
    }

    /** Un mensaje de la clienta, contestado por el job como en producción. */
    private function escribe(string $texto): void
    {
        $entrante = Message::create([
            'business_id' => $this->conversacion->business_id,
            'conversation_id' => $this->conversacion->id,
            'client_id' => $this->conversacion->client_id,
            'kind' => Message::KIND_INBOUND,
            'direction' => Message::DIRECTION_IN,
            'to' => $this->conversacion->phone,
            'body' => $texto,
            'status' => Message::STATUS_SENT,
            'sent_at' => now(),
        ]);

        AnswerWhatsappMessageJob::dispatchSync($this->conversacion->id, $entrante->id);
    }

    public function test_agendar_en_la_web_si_manda_el_link(): void
    {
        $this->escribe('Buenas noches, para agendar una cita');
        Http::assertSent(fn ($r) => str_contains($r->data()['text'] ?? '', '¿Cómo prefieres agendar?'));

        // El toque, segundos después: el link TIENE que salir.
        $this->escribe(GuidedEntry::WEB);

        Http::assertSent(fn ($r) => str_contains($r->data()['text'] ?? '', 'https://agenda.test/reservar/luxury'));
        Http::assertNotSent(fn ($r) => str_contains($r->data()['text'] ?? '', 'RESPUESTA DEL MODELO'));
    }

    public function test_otra_consulta_tambien_contesta(): void
    {
        $this->escribe('Hola, quiero una cita');
        $this->escribe(GuidedEntry::OTHER);

        Http::assertSent(fn ($r) => str_contains($r->data()['text'] ?? '', '¿en qué te ayudo?'));
    }

    public function test_si_una_herramienta_manda_botones_el_texto_del_modelo_no_se_duplica(): void
    {
        // La guarda original sigue viva para el MODELO: si en su turno una
        // herramienta mandó botones, su texto sobra.
        Http::fake([
            'comms.test/*' => Http::response(['results' => [['channel' => 'whatsapp', 'status' => 'sent']]]),
            'ia-core.test/*' => function () {
                OpcionesEnviadas::marcar((string) ChannelPhone::normalize(self::PHONE));

                return Http::response(['conversation_id' => 'c1', 'text' => 'Te mando las horas', 'tools_used' => ['disponibilidad']]);
            },
        ]);

        $this->escribe('¿tienen parqueadero? y quiero ver horas');

        Http::assertNotSent(fn ($r) => str_contains($r->data()['text'] ?? '', 'Te mando las horas'));
    }
}
