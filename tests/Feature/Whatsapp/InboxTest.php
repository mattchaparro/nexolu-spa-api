<?php

namespace Tests\Feature\Whatsapp;

use App\Models\Business;
use App\Models\Client;
use App\Models\Message;
use App\Models\User;
use App\Models\WhatsappConversation;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
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
    private function entra(string $texto, string $from = '573001234567'): TestResponse
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

        // Sin respuesta humana todavía, solo los rieles podrían contestar,
        // y una pregunta libre no es de ellos: el agente sigue callado.
        $this->assertSame('paused_rails', $r->json('agent'));
        $this->assertTrue($conv->fresh()->agentIsPaused());

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

    /*
    |--------------------------------------------------------------------------
    | La ventana de 24 horas de Meta
    |--------------------------------------------------------------------------
    */

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

    public function test_una_conversacion_cerrada_se_reabre_si_vuelve_a_escribir(): void
    {
        /*
         * Una conversación cerrada que recibe un mensaje y sigue escondida es
         * una clienta a la que nadie contesta.
         */
        $this->entra('Hola')->assertOk();
        $conv = $this->conversacion();

        $conv->update(['status' => WhatsappConversation::STATUS_CLOSED]);

        $this->entra('Una cosa más')->assertOk();

        $this->assertSame(WhatsappConversation::STATUS_OPEN, $conv->fresh()->status);
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
