<?php

namespace Tests\Feature\Whatsapp;

use App\Models\Business;
use App\Models\Client;
use App\Models\Message;
use App\Models\User;
use App\Models\WhatsappConversation;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Scheduling\SchedulingScenario;
use Tests\TestCase;

/**
 * La bandeja de WhatsApp.
 *
 * Existe para poder migrar el número de un local que hoy trabaja en ManyChat:
 * ahí no solo hay automatización, hay gente contestando a mano. Sin esto,
 * cambiar de sistema sería quitarles una herramienta.
 *
 * Lo que estas pruebas defienden, en orden de gravedad:
 *
 *  1. Que el agente NO conteste encima de una persona. Dos respuestas a la
 *     misma pregunta, contradiciéndose delante de la clienta.
 *  2. Que no se intente mandar texto fuera de la ventana de 24h de Meta, que
 *     no falla con un error: simplemente no llega.
 *  3. Que lo que entra quede escrito aunque el agente se caiga.
 */
class InboxTest extends TestCase
{
    use RefreshDatabase, SchedulingScenario;

    private Business $business;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        PermissionCatalog::sync();

        $this->business = $this->makeBusiness();
        /*
         * `forceFill` y no `update`: el numero de WhatsApp NO esta en el
         * fillable del negocio a proposito -- se asigna al conectar la cuenta
         * de Meta, no desde un formulario -- asi que un `update` lo ignoraria
         * en silencio y el webhook no encontraria a quien pertenece.
         */
        $this->business->forceFill(['whatsapp_phone_number_id' => '111222333'])->save();

        $this->admin = User::create([
            'business_id' => $this->business->id, 'name' => 'Dueña',
            'email' => 'admin@prueba.test', 'password' => Hash::make('password123'), 'is_active' => true,
        ]);
        PermissionCatalog::applyRole($this->admin, PermissionCatalog::ROLE_ADMIN);

        config()->set('services.comms_core.api_key', 'llave');
        config()->set('services.comms_core.base_url', 'http://comms.test');
        config()->set('services.comms_core.webhook_secret', 'secreto');
    }

    /**
     * Un mensaje entrante, con el sobre crudo que manda Meta.
     *
     * La forma importa: `entry[].changes[].value` con los metadatos y los
     * mensajes adentro. Communications lo reenvia tal cual, sin aplanarlo.
     */
    private function entra(string $texto, string $from = '573001234567'): \Illuminate\Testing\TestResponse
    {
        $body = json_encode([
            'object' => 'whatsapp_business_account',
            'entry' => [['changes' => [['value' => [
                'metadata' => ['phone_number_id' => '111222333'],
                'messages' => [['from' => $from, 'type' => 'text', 'text' => ['body' => $texto]]],
            ]]]]],
        ]);

        /*
         * El reloj de la APP, no el del sistema. El webhook rechaza firmas de
         * hace mas de cinco minutos, y varias pruebas de aca viajan en el
         * tiempo: con `time()` real, todo lo que ocurre despues de un
         * `travel()` se veria como una firma vencida.
         */
        $timestamp = (string) now()->timestamp;

        return $this->call(
            'POST',
            '/api/webhooks/nexolu-comms/whatsapp',
            [], [], [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_X_NEXOLU_TIMESTAMP' => $timestamp,
                'HTTP_X_NEXOLU_SIGNATURE' => hash_hmac('sha256', $timestamp.'.'.$body, 'secreto'),
            ],
            $body,
        );
    }

    private function conversacion(): WhatsappConversation
    {
        return WhatsappConversation::withoutGlobalScope('business')->latest('id')->firstOrFail();
    }

    /*
    |--------------------------------------------------------------------------
    | Lo que entra queda escrito
    |--------------------------------------------------------------------------
    */

    public function test_lo_que_escribe_la_clienta_se_guarda(): void
    {
        /*
         * Antes de esto, el webhook leía el texto, se lo pasaba al agente y lo
         * tiraba. Quien atendía el mostrador veía lo que el sistema contestó,
         * pero no lo que le preguntaron.
         */
        $this->entra('Hola, ¿tienen cita para mañana?')->assertOk();

        $mensaje = Message::withoutGlobalScope('business')
            ->where('direction', Message::DIRECTION_IN)->first();

        $this->assertNotNull($mensaje);
        $this->assertSame('Hola, ¿tienen cita para mañana?', $mensaje->body);
        $this->assertSame($this->conversacion()->id, $mensaje->conversation_id);
    }

    public function test_un_mensaje_entrante_no_se_va_a_la_cola_de_salida(): void
    {
        /*
         * Nace `enviado` porque YA llegó. Si naciera pendiente, el outbox lo
         * tomaría como algo por mandar y se lo reenviaría a quien lo escribió.
         */
        $this->entra('Hola')->assertOk();

        $entrante = Message::withoutGlobalScope('business')
            ->where('direction', Message::DIRECTION_IN)->first();

        $this->assertSame(Message::STATUS_SENT, $entrante->status);
    }

    public function test_un_mensaje_nuevo_deja_la_conversacion_sin_leer(): void
    {
        $this->entra('Hola')->assertOk();
        $this->assertTrue($this->conversacion()->isUnread());
    }

    /*
    |--------------------------------------------------------------------------
    | El relevo: que el agente no conteste encima
    |--------------------------------------------------------------------------
    */

    public function test_si_una_persona_contesta_el_agente_se_calla(): void
    {
        $this->entra('Hola')->assertOk();
        $conv = $this->conversacion();

        Sanctum::actingAs($this->admin->fresh());
        $this->postJson("/api/v1/whatsapp/inbox/{$conv->id}/reply", ['body' => 'Hola Ana, sí tenemos.'])
            ->assertCreated();

        $this->assertTrue($conv->fresh()->agentIsPaused());
    }

    public function test_con_el_agente_relevado_el_webhook_no_lo_despierta(): void
    {
        /*
         * Es LA prueba de este archivo. Sin esto, la clienta pregunta algo, la
         * empleada le contesta, y el agente contesta otra cosa encima.
         */
        $this->entra('Hola')->assertOk();
        $conv = $this->conversacion();
        $conv->pauseAgent();

        $r = $this->entra('¿A qué hora?')->assertOk();

        $this->assertSame('paused', $r->json('agent'));

        // Y el mensaje igual quedó escrito: el relevo silencia al agente, no
        // a la clienta.
        $this->assertSame(2, Message::withoutGlobalScope('business')
            ->where('direction', Message::DIRECTION_IN)->count());
    }

    public function test_el_relevo_caduca_solo(): void
    {
        /*
         * Por eso es una fecha y no un interruptor: quien toma una
         * conversación no siempre la suelta, y un booleano olvidado deja al
         * agente mudo para siempre sin que nadie se entere.
         */
        $this->entra('Hola')->assertOk();
        $conv = $this->conversacion();
        $conv->pauseAgent(60);

        $this->assertTrue($conv->agentIsPaused());

        $this->travel(61)->minutes();

        $this->assertFalse($conv->fresh()->agentIsPaused());
    }

    public function test_se_le_puede_devolver_la_conversacion_al_agente(): void
    {
        $this->entra('Hola')->assertOk();
        $conv = $this->conversacion();
        $conv->pauseAgent();

        Sanctum::actingAs($this->admin->fresh());
        $this->postJson("/api/v1/whatsapp/inbox/{$conv->id}/resume-agent")->assertOk();

        $this->assertFalse($conv->fresh()->agentIsPaused());
    }

    /*
    |--------------------------------------------------------------------------
    | La ventana de 24 horas de Meta
    |--------------------------------------------------------------------------
    */

    public function test_fuera_de_las_24_horas_no_se_deja_escribir(): void
    {
        /*
         * Meta no rechaza el mensaje con un error: lo acepta y no lo entrega.
         * Si no se corta acá, quien atiende cree que contestó y la clienta
         * nunca recibe nada.
         */
        $this->entra('Hola')->assertOk();
        $conv = $this->conversacion();

        $this->travel(25)->hours();

        Sanctum::actingAs($this->admin->fresh());
        $r = $this->postJson("/api/v1/whatsapp/inbox/{$conv->id}/reply", ['body' => 'Perdón la demora'])
            ->assertStatus(422);

        $this->assertFalse($r->json('window_open'));
        $this->assertStringContainsString('24 horas', $r->json('message'));
    }

    public function test_contestar_nosotros_no_reabre_la_ventana(): void
    {
        /*
         * La ventana la abre EL MENSAJE DE ELLA. Si nuestra respuesta la
         * reabriera, bastaría con escribirle cada 23 horas para tenerla
         * abierta para siempre -- y Meta no funciona así.
         */
        $this->entra('Hola')->assertOk();
        $conv = $this->conversacion();

        $this->travel(20)->hours();

        Sanctum::actingAs($this->admin->fresh());
        $this->postJson("/api/v1/whatsapp/inbox/{$conv->id}/reply", ['body' => 'Ya te confirmo'])->assertCreated();

        $this->travel(5)->hours();

        $this->assertFalse($conv->fresh()->windowIsOpen());
    }

    public function test_cuando_ella_vuelve_a_escribir_la_ventana_se_reabre(): void
    {
        $this->entra('Hola')->assertOk();
        $this->travel(25)->hours();

        $this->assertFalse($this->conversacion()->windowIsOpen());

        $this->entra('¿Sigues ahí?')->assertOk();

        $this->assertTrue($this->conversacion()->fresh()->windowIsOpen());
    }

    /*
    |--------------------------------------------------------------------------
    | La bandeja
    |--------------------------------------------------------------------------
    */

    public function test_abrir_el_hilo_lo_marca_leido(): void
    {
        // Un botón de "marcar leído" aparte es un botón que nadie oprime.
        $this->entra('Hola')->assertOk();
        $conv = $this->conversacion();

        Sanctum::actingAs($this->admin->fresh());
        $this->getJson("/api/v1/whatsapp/inbox/{$conv->id}")->assertOk();

        $this->assertFalse($conv->fresh()->isUnread());
    }

    public function test_una_conversacion_cerrada_se_reabre_si_vuelve_a_escribir(): void
    {
        /*
         * Una conversación cerrada que recibe un mensaje y sigue escondida es
         * una clienta a la que nadie contesta.
         */
        $this->entra('Hola')->assertOk();
        $conv = $this->conversacion();

        Sanctum::actingAs($this->admin->fresh());
        $this->postJson("/api/v1/whatsapp/inbox/{$conv->id}/toggle")->assertOk();
        $this->assertSame(WhatsappConversation::STATUS_CLOSED, $conv->fresh()->status);

        $this->entra('Una cosa más')->assertOk();

        $this->assertSame(WhatsappConversation::STATUS_OPEN, $conv->fresh()->status);
    }

    public function test_la_conversacion_de_otro_negocio_no_existe(): void
    {
        /*
         * El límite duro del multi-tenant: una conversación trae el teléfono y
         * el nombre de una clienta. Ver la del local de al lado sería
         * exactamente la fuga de datos que el dueño no quiere.
         */
        $otro = $this->makeBusiness();

        $ajenaId = DB::table('whatsapp_conversations')->insertGetId([
            'business_id' => $otro->id, 'phone' => '573009998877',
            'status' => WhatsappConversation::STATUS_OPEN,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        Sanctum::actingAs($this->admin->fresh());

        $this->getJson("/api/v1/whatsapp/inbox/{$ajenaId}")->assertNotFound();
        $this->postJson("/api/v1/whatsapp/inbox/{$ajenaId}/reply", ['body' => 'hola'])->assertNotFound();
        $this->postJson("/api/v1/whatsapp/inbox/{$ajenaId}/toggle")->assertNotFound();
    }

    public function test_la_bandeja_pone_arriba_lo_que_falta_contestar(): void
    {
        // La bandeja se mira para saber a quién contestar, no para repasar lo
        // ya resuelto.
        $this->entra('Primera', '573001111111')->assertOk();
        $this->travel(1)->minute();
        $this->entra('Segunda', '573002222222')->assertOk();

        $vieja = WhatsappConversation::withoutGlobalScope('business')
            ->where('phone', '573001111111')->first();

        Sanctum::actingAs($this->admin->fresh());

        // Se lee la más nueva: la vieja queda como la única sin leer.
        $nueva = WhatsappConversation::withoutGlobalScope('business')
            ->where('phone', '573002222222')->first();
        $this->getJson("/api/v1/whatsapp/inbox/{$nueva->id}")->assertOk();

        $lista = $this->getJson('/api/v1/whatsapp/inbox')->assertOk()->json();

        $this->assertSame($vieja->id, $lista['data'][0]['id']);
        $this->assertSame(1, $lista['unread']);
    }

    public function test_la_bandeja_dice_si_se_puede_escribir_y_hasta_cuando(): void
    {
        /*
         * Tres datos separados y no un booleano: la pantalla necesita explicar
         * POR QUÉ no se puede escribir, y "fuera de la ventana" y "el agente
         * está atendiendo" se arreglan de formas distintas.
         */
        $this->entra('Hola')->assertOk();
        $conv = $this->conversacion();

        Sanctum::actingAs($this->admin->fresh());
        $r = $this->getJson("/api/v1/whatsapp/inbox/{$conv->id}")->assertOk()->json('conversation');

        $this->assertTrue($r['window_open']);
        $this->assertNotNull($r['window_closes_at']);
        $this->assertFalse($r['agent_paused']);
    }

    public function test_el_hilo_mezcla_lo_que_entra_y_lo_que_sale_en_orden(): void
    {
        $this->entra('Hola')->assertOk();
        $conv = $this->conversacion();

        Sanctum::actingAs($this->admin->fresh());
        $this->postJson("/api/v1/whatsapp/inbox/{$conv->id}/reply", ['body' => 'Hola, ¿en qué te ayudo?'])
            ->assertCreated();

        $hilo = $this->getJson("/api/v1/whatsapp/inbox/{$conv->id}")->assertOk()->json('messages');

        $this->assertCount(2, $hilo);
        $this->assertSame(Message::DIRECTION_IN, $hilo[0]['direction']);
        $this->assertSame(Message::DIRECTION_OUT, $hilo[1]['direction']);
    }

    public function test_la_conversacion_queda_ligada_a_la_clienta_si_se_reconoce(): void
    {
        // Para que quien contesta vea con quién habla, no un número.
        $cliente = Client::create([
            'business_id' => $this->business->id, 'name' => 'Ana', 'last_name' => 'Pérez',
            'phone' => '573001234567', 'is_active' => true,
        ]);

        $this->entra('Hola')->assertOk();

        $this->assertSame($cliente->id, $this->conversacion()->client_id);
    }
}
