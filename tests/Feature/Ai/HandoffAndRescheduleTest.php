<?php

namespace Tests\Feature\Ai;

use App\Models\Appointment;
use App\Models\Business;
use App\Models\Client;
use App\Models\Message;
use App\Models\Resource;
use App\Models\Service;
use App\Models\WhatsappConversation;
use App\Services\Scheduling\BookingService;
use App\Support\ChannelPhone;
use App\Support\PermissionCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Scheduling\SchedulingScenario;
use Tests\TestCase;

/**
 * Las dos cosas que el agente no sabía hacer y sí le pedían:
 *
 *  1. Pasar la conversación a una persona. Antes solo existía si la clienta
 *     tocaba el botón de un flujo de Connect; escribírselo al bot no hacía
 *     nada, y el bot seguía intentando agendarle una cita a alguien que venía
 *     a reclamar.
 *  2. Mover una cita sin cancelarla primero. Cancelar y volver a crear deja a
 *     la clienta sin nada si la hora nueva se ocupó entre las dos llamadas.
 */
class HandoffAndRescheduleTest extends TestCase
{
    use RefreshDatabase, SchedulingScenario;

    private const KEY = 'llave-de-prueba-del-core';

    private const PHONE = '573001112233';

    private Business $business;

    private Resource $maria;

    private Service $manicure;

    private Client $carolina;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(
            CarbonImmutable::now('America/Bogota')->startOfDay()
                ->previous(CarbonImmutable::WEDNESDAY)->setTime(8, 0),
        );

        PermissionCatalog::sync();
        config()->set('services.ia_core.api_key', self::KEY);

        $this->business = $this->makeBusiness([
            'min_booking_notice_min' => 0,
            'min_cancellation_notice_min' => 0,
        ]);
        $this->maria = $this->makeResource($this->business, 'Maria');
        $this->manicure = $this->makeService($this->business, 60, [$this->maria]);

        $this->carolina = Client::create([
            'business_id' => $this->business->id,
            'name' => 'Carolina',
            'phone' => ChannelPhone::normalize(self::PHONE, $this->business->country_code),
            'is_active' => true,
        ]);
    }

    /** @param array<string, mixed> $arguments */
    private function invoke(string $tool, array $arguments = []): TestResponse
    {
        return $this->withHeader('Authorization', 'Bearer '.self::KEY)
            ->postJson('/api/ai/tools/invoke', [
                'tool' => $tool,
                'arguments' => $arguments,
                'context' => [
                    'business_id' => (string) $this->business->id,
                    'user_id' => self::PHONE,
                    'channel' => 'whatsapp',
                ],
            ]);
    }

    private function conversacion(): WhatsappConversation
    {
        return WhatsappConversation::withoutGlobalScope('business')->create([
            'business_id' => $this->business->id,
            'phone' => self::PHONE,
            'client_id' => $this->carolina->id,
            'last_message_at' => now(),
            'last_inbound_at' => now(),
            'read_at' => now(),
            'status' => WhatsappConversation::STATUS_OPEN,
        ]);
    }

    private function citaManana(int $hora = 10): Appointment
    {
        $inicio = CarbonImmutable::now('America/Bogota')->addDay()->startOfDay()->setTime($hora, 0);

        return app(BookingService::class)->book(
            $this->business,
            [['service_id' => $this->manicure->id, 'resource_id' => $this->maria->id, 'starts_at' => $inicio]],
            $this->carolina,
        );
    }

    /*
    |--------------------------------------------------------------------------
    | hablar_con_persona
    |--------------------------------------------------------------------------
    */

    public function test_pedir_una_persona_calla_al_agente_y_deja_la_nota(): void
    {
        $conversacion = $this->conversacion();

        $respuesta = $this->invoke('hablar_con_persona', [
            'motivo' => 'Está molesta: dice que el semipermanente se le levantó a los tres días.',
        ]);

        $respuesta->assertOk()->assertJsonPath('data.avisado', true);

        $conversacion->refresh();
        $this->assertTrue($conversacion->agentIsPaused());
        // Vuelve a la bandeja como pendiente: alguien tiene que verla.
        $this->assertNull($conversacion->read_at);

        $nota = Message::withoutGlobalScopes()->where('kind', Message::KIND_STAFF)->first();
        $this->assertStringContainsString('se le levantó', $nota->body);
        // Nota interna: si naciera pendiente, el outbox se la mandaría a la clienta.
        $this->assertSame(Message::STATUS_SENT, $nota->status);
    }

    public function test_al_agente_se_le_dice_que_deje_de_intentarlo(): void
    {
        $this->conversacion();

        // El modelo necesita que le digan qué hacer después, o sigue
        // ofreciendo horas a alguien que pidió hablar con una persona.
        $this->invoke('hablar_con_persona', ['motivo' => 'Quiere hablar con alguien'])
            ->assertOk()
            ->assertJsonPath('data.avisado', true);

        $instruccion = $this->invoke('hablar_con_persona', ['motivo' => 'otra vez'])->json('data.instruccion');
        $this->assertStringContainsString('no sigas intentando resolverlo', $instruccion);
    }

    public function test_sin_conversacion_no_se_inventa_un_aviso(): void
    {
        // Sin hilo (p.ej. el agente respondiendo por otro canal) no hay a
        // quién avisarle: se dice, en vez de fingir que alguien va a escribir.
        $this->invoke('hablar_con_persona', ['motivo' => 'Quiere hablar'])
            ->assertOk()
            ->assertJsonPath('data.avisado', false);

        $this->assertSame(0, Message::withoutGlobalScopes()->count());
    }

    public function test_el_motivo_es_obligatorio(): void
    {
        // Sin motivo, quien atiende abre la bandeja sin saber qué pasó.
        $this->invoke('hablar_con_persona')->assertStatus(422);
    }

    /*
    |--------------------------------------------------------------------------
    | reagendar_cita
    |--------------------------------------------------------------------------
    */

    public function test_mover_una_cita_conserva_la_misma_cita(): void
    {
        $cita = $this->citaManana(10);
        $fecha = CarbonImmutable::now('America/Bogota')->addDay()->format('Y-m-d');

        $this->invoke('reagendar_cita', ['cita_id' => $cita->id, 'fecha' => $fecha, 'hora' => '15:00'])
            ->assertOk()
            ->assertJsonPath('data.movida', true)
            ->assertJsonPath('data.id', $cita->id)
            // Legible para escribirle a la clienta, y H:i para reusar.
            ->assertJsonPath('data.hora', '3:00 pm')
            ->assertJsonPath('data.hora_24', '15:00');

        $this->assertSame(
            '15:00',
            $cita->fresh()->starts_at->setTimezone('America/Bogota')->format('H:i'),
        );
        // Una sola cita: no se canceló y se creó otra.
        $this->assertSame(1, Appointment::withoutGlobalScopes()->count());
    }

    public function test_si_la_hora_nueva_esta_ocupada_la_cita_vieja_sigue_en_pie(): void
    {
        $otra = Client::create([
            'business_id' => $this->business->id,
            'name' => 'Otra',
            'phone' => '573009998877',
            'is_active' => true,
        ]);
        $inicio = CarbonImmutable::now('America/Bogota')->addDay()->startOfDay()->setTime(15, 0);
        app(BookingService::class)->book(
            $this->business,
            [['service_id' => $this->manicure->id, 'resource_id' => $this->maria->id, 'starts_at' => $inicio]],
            $otra,
        );

        $cita = $this->citaManana(10);
        $fecha = CarbonImmutable::now('America/Bogota')->addDay()->format('Y-m-d');

        // Dato, no error: el agente ofrece otras horas en vez de disculparse.
        $this->invoke('reagendar_cita', ['cita_id' => $cita->id, 'fecha' => $fecha, 'hora' => '15:00'])
            ->assertOk()
            ->assertJsonPath('data.movida', false)
            ->assertJsonPath('data.motivo', 'Esa hora ya se ocupó. Ofrécele otras del mismo día.');

        $this->assertSame(
            '10:00',
            $cita->fresh()->starts_at->setTimezone('America/Bogota')->format('H:i'),
        );
    }

    public function test_con_una_sola_cita_un_id_equivocado_igual_la_cancela(): void
    {
        /*
         * Pasó en la primera prueba real: el modelo llamó con `cita_id: 1`
         * -- el id de la FICHA de la clienta, no el de la cita -- y ante el
         * "no la encuentro" escaló a un humano algo que estaba a la vista.
         * Con una sola cita próxima, "cancélame la cita" no es ambiguo.
         */
        $cita = $this->citaManana(10);

        $this->invoke('cancelar_cita', ['cita_id' => 1])
            ->assertOk()
            ->assertJsonPath('data.cancelada', true)
            ->assertJsonPath('data.id', $cita->id);

        $this->assertSame(Appointment::STATUS_CANCELLED, $cita->fresh()->status);
    }

    public function test_con_varias_citas_el_error_dice_cuales_son(): void
    {
        // Acá sí es ambiguo, y el modelo necesita los ids REALES para
        // reintentar en vez de adivinar otra vez.
        $primera = $this->citaManana(10);
        $segunda = $this->citaManana(14);

        $respuesta = $this->invoke('cancelar_cita', ['cita_id' => 1])->assertOk();

        $respuesta->assertJsonPath('data.cancelada', false);
        $ids = array_column($respuesta->json('data.citas'), 'id');
        $this->assertEqualsCanonicalizing([$primera->id, $segunda->id], $ids);
        $this->assertSame(Appointment::STATUS_PENDING, $primera->fresh()->status);
    }

    public function test_no_se_puede_mover_la_cita_de_otra_persona(): void
    {
        $otra = Client::create([
            'business_id' => $this->business->id,
            'name' => 'Otra',
            'phone' => '573009998877',
            'is_active' => true,
        ]);
        $inicio = CarbonImmutable::now('America/Bogota')->addDay()->startOfDay()->setTime(9, 0);
        $ajena = app(BookingService::class)->book(
            $this->business,
            [['service_id' => $this->manicure->id, 'resource_id' => $this->maria->id, 'starts_at' => $inicio]],
            $otra,
        );

        $fecha = CarbonImmutable::now('America/Bogota')->addDay()->format('Y-m-d');

        // Probar ids consecutivos no puede mover la agenda del local entera:
        // lo ajeno se trata como inexistente, y como quien escribe no tiene
        // citas propias, no hay ninguna que caiga por descarte.
        $this->invoke('reagendar_cita', ['cita_id' => $ajena->id, 'fecha' => $fecha, 'hora' => '16:00'])
            ->assertOk()
            ->assertJsonPath('data.movida', false)
            ->assertJsonPath('data.motivo', 'No tienes citas próximas para mover.');

        $this->assertSame(
            '09:00',
            $ajena->fresh()->starts_at->setTimezone('America/Bogota')->format('H:i'),
        );
    }
}
