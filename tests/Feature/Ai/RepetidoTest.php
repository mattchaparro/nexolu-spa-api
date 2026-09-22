<?php

namespace Tests\Feature\Ai;

use App\Ai\Repetido;
use App\Models\Business;
use App\Models\Message;
use App\Models\WhatsappConversation;
use App\Support\ChannelPhone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Scheduling\SchedulingScenario;
use Tests\TestCase;

/**
 * El bot repitiéndose no es una respuesta: es un bucle.
 *
 * Gloria dijo cuatro veces "en la sede principal, para mañana" y cuatro
 * veces recibió la misma pregunta. Cuando lo que va a salir es un eco de
 * lo último que el agente dijo, se corta: enlace de la agenda, agente en
 * pausa y la conversación pendiente para una persona.
 */
class RepetidoTest extends TestCase
{
    use RefreshDatabase, SchedulingScenario;

    private const PHONE = '573001112233';

    private Business $business;

    private WhatsappConversation $conversacion;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('spa.public_booking_url', 'https://agenda.test');

        $this->business = $this->makeBusiness();
        $this->business->update(['slug' => 'luxury']);

        $this->conversacion = WhatsappConversation::withoutGlobalScope('business')->create([
            'business_id' => $this->business->id,
            'phone' => ChannelPhone::normalize(self::PHONE),
            'last_message_at' => now(),
            'last_inbound_at' => now(),
            'status' => WhatsappConversation::STATUS_OPEN,
        ]);
    }

    private function elBotYaDijo(string $texto, int $haceMinutos = 1): void
    {
        $mensaje = Message::create([
            'business_id' => $this->business->id,
            'conversation_id' => $this->conversacion->id,
            'kind' => Message::KIND_AGENT,
            'direction' => Message::DIRECTION_OUT,
            'to' => $this->conversacion->phone,
            'body' => $texto,
            'status' => Message::STATUS_SENT,
            'sent_at' => now()->subMinutes($haceMinutos),
        ]);

        // `created_at` no es fillable: se fuerza aparte.
        $mensaje->forceFill(['created_at' => now()->subMinutes($haceMinutos)])->save();
    }

    public function test_un_eco_se_corta_con_el_enlace_y_pasa_a_una_persona(): void
    {
        $pregunta = '¿Para qué sede te gustaría la cita? Tenemos Principal y Cedritos.';
        $this->elBotYaDijo($pregunta);

        $atajo = app(Repetido::class)->atajar($this->conversacion, $pregunta);

        $this->assertNotNull($atajo);
        $this->assertStringContainsString('https://agenda.test/reservar/luxury', $atajo);

        // El agente quedó en pausa y la bandeja tiene la nota, sin leer.
        $this->conversacion->refresh();
        $this->assertTrue($this->conversacion->agentIsPaused());
        $this->assertNull($this->conversacion->read_at);
        $this->assertTrue(
            Message::withoutGlobalScope('business')
                ->where('conversation_id', $this->conversacion->id)
                ->where('kind', Message::KIND_STAFF)
                ->where('body', 'like', '%bucle%')
                ->exists(),
        );
    }

    public function test_da_igual_mayusculas_tildes_y_espacios(): void
    {
        $this->elBotYaDijo("¿Para qué SEDE te gustaría   la cita?\nTenemos Principal.");

        $atajo = app(Repetido::class)->atajar($this->conversacion, '¿para que sede te gustaria la cita? tenemos principal.');

        $this->assertNotNull($atajo);
    }

    public function test_una_respuesta_nueva_pasa_sin_tocar(): void
    {
        $this->elBotYaDijo('¿Para qué sede te gustaría la cita? Tenemos Principal y Cedritos.');

        $this->assertNull(app(Repetido::class)->atajar(
            $this->conversacion,
            'Para el martes tengo estas horas: 9 am, 10 am y 11 am. ¿Cuál te sirve?',
        ));
        $this->assertFalse($this->conversacion->refresh()->agentIsPaused());
    }

    public function test_repetir_un_saludo_viejo_no_es_bucle(): void
    {
        /*
         * Lo de Alejandro: saludó, conversó, y 20 minutos después volvió a
         * saludar. El bot le devolvió el mismo saludo de la primera vez,
         * esto lo tomó por bucle y lo dejó en pausa dos horas -- y nadie
         * le contestó más. Un bucle es repetir la ÚLTIMA respuesta.
         */
        $saludo = '¡Hola! Para agendar tu cita, ¿qué servicio te gustaría y para qué día? 💅';
        $this->elBotYaDijo($saludo, haceMinutos: 5);
        $this->elBotYaDijo('Ya tienes una cita de Semipermanente el martes a las 9 am.', haceMinutos: 2);

        $this->assertNull(app(Repetido::class)->atajar($this->conversacion, $saludo));
        $this->assertFalse($this->conversacion->refresh()->agentIsPaused());
    }

    public function test_la_pausa_del_bucle_es_corta(): void
    {
        // La decide la máquina, no la clienta: si nadie la atiende, el bot
        // tiene que volver pronto en vez de dejarla sin respuesta.
        $pregunta = '¿Para qué sede te gustaría la cita? Tenemos Principal y Cedritos.';
        $this->elBotYaDijo($pregunta);

        app(Repetido::class)->atajar($this->conversacion, $pregunta);

        $hasta = $this->conversacion->refresh()->agent_paused_until;
        $this->assertTrue($hasta->lte(now()->addMinutes(15)));
        $this->assertTrue($hasta->gt(now()->addMinutes(10)));
    }

    public function test_lo_corto_no_cuenta_como_bucle(): void
    {
        // "¿Para qué día?" repetido puede ser legítimo (cambió de servicio).
        $this->elBotYaDijo('¿Para qué día? 😊');

        $this->assertNull(app(Repetido::class)->atajar($this->conversacion, '¿Para qué día? 😊'));
    }

    public function test_un_eco_de_hace_una_hora_ya_no_es_bucle(): void
    {
        $pregunta = '¿Para qué sede te gustaría la cita? Tenemos Principal y Cedritos.';
        $this->elBotYaDijo($pregunta, haceMinutos: 60);

        $this->assertNull(app(Repetido::class)->atajar($this->conversacion, $pregunta));
    }

    public function test_con_historial_dado_no_mira_la_base(): void
    {
        // El simulador no persiste las respuestas del modelo: pasa las suyas.
        $pregunta = '¿Para qué sede te gustaría la cita? Tenemos Principal y Cedritos.';

        $atajo = app(Repetido::class)->atajar($this->conversacion, $pregunta, [$pregunta]);

        $this->assertNotNull($atajo);
        $this->assertNull(app(Repetido::class)->atajar($this->conversacion, $pregunta, ['Otra cosa distinta de verdad']));
    }
}
