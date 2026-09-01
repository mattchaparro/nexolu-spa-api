<?php

namespace Tests\Feature\Messaging;

use App\Models\Appointment;
use App\Models\Business;
use App\Models\Message;
use App\Models\Resource;
use App\Models\Service;
use App\Models\User;
use App\Services\Messaging\Contracts\MessagingChannel;
use App\Services\Messaging\MessageDispatcher;
use App\Support\PermissionCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Scheduling\SchedulingScenario;
use Tests\Support\FakeMessagingChannel;
use Tests\TestCase;

/**
 * La bandeja de salida.
 *
 * Lo que se defiende: que ningún mensaje se pierda, que ninguno salga dos
 * veces, y que un negocio SIN WhatsApp pueda operar completo.
 *
 * El estado anterior era peor de lo que parecía: `LoggingMessagingChannel`
 * escribía a un modelo `WhatsappLog` que en este repo nunca existió. El primer
 * envío real habría muerto con un class not found, y no se notó porque
 * `isConfigured()` devuelve false sin credenciales y esa rama nunca corría.
 */
class MessagingTest extends TestCase
{
    use RefreshDatabase, SchedulingScenario;

    private Business $business;

    private User $admin;

    private Resource $maria;

    private Service $manicure;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(
            CarbonImmutable::now('America/Bogota')->startOfDay()
                ->previous(CarbonImmutable::WEDNESDAY)->setTime(8, 0),
        );

        PermissionCatalog::sync();

        $this->business = $this->makeBusiness(['min_booking_notice_min' => 0]);

        $this->admin = User::create([
            'business_id' => $this->business->id, 'name' => 'Dueña',
            'email' => 'admin@prueba.test', 'password' => Hash::make('password123'),
            'is_active' => true, 'is_owner' => true,
        ]);
        PermissionCatalog::applyRole($this->admin, PermissionCatalog::ROLE_ADMIN);

        $this->maria = $this->makeResource($this->business, 'Maria');
        $this->manicure = $this->makeService($this->business, 60, [$this->maria]);

        Sanctum::actingAs($this->admin->fresh());
    }

    /** Instala un canal de mentira y lo devuelve para poder afirmar sobre él. */
    private function canal(FakeMessagingChannel $fake): FakeMessagingChannel
    {
        $this->app->instance(MessagingChannel::class, $fake);

        return $fake;
    }

    private function canalQueFunciona(): FakeMessagingChannel
    {
        return $this->canal(new FakeMessagingChannel);
    }

    private function dispatcher(): MessageDispatcher
    {
        return $this->app->make(MessageDispatcher::class);
    }

    private function agendar(string $hora = '10:00'): Appointment
    {
        $id = $this->postJson('/api/v1/appointments', [
            'service_id' => $this->manicure->id,
            'resource_id' => $this->maria->id,
            'starts_at' => CarbonImmutable::now('America/Bogota')->addDay()->format('Y-m-d')." {$hora}:00",
            'client_name' => 'Carolina',
            'client_phone' => '+573001112233',
        ])->assertCreated()->json('id');

        return Appointment::find($id);
    }

    // ---- Operar sin WhatsApp ----

    public function test_un_negocio_nace_en_manual(): void
    {
        /*
         * No es un placeholder: es como va a operar un spa sus primeras semanas
         * de todas formas, y hay quien no va a querer salir de ahí nunca.
         * Encender el envío automático sin que lo pidan sería mandarle mensajes
         * a sus clientas a su nombre sin avisarle.
         */
        $this->assertSame('manual', $this->business->messaging_mode);
        $this->assertFalse($this->dispatcher()->sendsByItself($this->business));
    }

    public function test_en_manual_el_mensaje_se_guarda_pero_no_sale(): void
    {
        $canal = $this->canalQueFunciona();

        $mensaje = $this->dispatcher()->queue(
            $this->business,
            Message::KIND_REMINDER,
            '+573001112233',
            'Hola Carolina, te esperamos mañana.',
        );

        $this->assertSame(Message::STATUS_MANUAL, $mensaje->status);
        // Y NO se envió: el canal funciona, pero nadie le pidió que lo usara.
        $this->assertCount(0, $canal->sent);
    }

    public function test_lo_pendiente_sale_en_la_bandeja(): void
    {
        $this->dispatcher()->queue(
            $this->business,
            Message::KIND_REMINDER,
            '+573001112233',
            'Hola Carolina, te esperamos mañana.',
        );

        $body = $this->getJson('/api/v1/messages')->assertOk();

        $this->assertCount(1, $body->json('data'));
        $this->assertSame('Por enviar a mano', $body->json('data.0.status_label'));
        $this->assertSame(1, $body->json('pending'));
        $this->assertFalse($body->json('sends_by_itself'));
    }

    public function test_la_bandeja_trae_el_enlace_de_whatsapp_listo(): void
    {
        // Es el atajo que hace usable el modo manual: un toque y sale el chat
        // con esa persona y el texto adentro.
        $this->dispatcher()->queue(
            $this->business,
            Message::KIND_REMINDER,
            '+573001112233',
            'Hola Carolina',
        );

        $url = $this->getJson('/api/v1/messages')->assertOk()->json('data.0.whatsapp_url');

        $this->assertStringStartsWith('https://wa.me/573001112233?text=', $url);
        $this->assertStringContainsString('Carolina', urldecode($url));
    }

    public function test_marcarlo_como_enviado_lo_saca_de_la_lista(): void
    {
        /*
         * El cierre del modo manual. Sin esto la lista crece para siempre y
         * deja de servir, porque nadie distingue lo que falta de lo que ya se
         * hizo.
         */
        $mensaje = $this->dispatcher()->queue(
            $this->business,
            Message::KIND_REMINDER,
            '+573001112233',
            'Hola Carolina',
        );

        $this->postJson("/api/v1/messages/{$mensaje->id}/sent")->assertOk();

        $this->assertSame(Message::STATUS_SENT, $mensaje->fresh()->status);
        // Queda anotado que hubo una persona detrás, no un envío automático.
        $this->assertSame($this->admin->id, $mensaje->fresh()->sent_by_user_id);
        $this->assertCount(0, $this->getJson('/api/v1/messages')->json('data'));
    }

    public function test_reintentar_en_manual_no_promete_nada(): void
    {
        // Un botón de reintentar que no puede enviar es peor que no tenerlo:
        // se toca, no pasa nada, y nadie sabe por qué.
        $mensaje = $this->dispatcher()->queue(
            $this->business,
            Message::KIND_REMINDER,
            '+573001112233',
            'Hola',
        );

        $this->postJson("/api/v1/messages/{$mensaje->id}/retry")
            ->assertStatus(422)
            ->assertJsonPath('message', fn (string $m) => str_contains($m, 'a mano'));
    }

    // ---- Modo automático ----

    public function test_en_automatico_sale_solo(): void
    {
        $canal = $this->canalQueFunciona();
        $this->business->update(['messaging_mode' => 'auto']);

        $mensaje = $this->dispatcher()->queue(
            $this->business->fresh(),
            Message::KIND_REMINDER,
            '+573001112233',
            'Hola Carolina',
        );

        $this->assertSame(Message::STATUS_SENT, $mensaje->status);
        $this->assertNotNull($mensaje->sent_at);
        $this->assertCount(1, $canal->sent);
    }

    public function test_sin_canal_configurado_no_promete_envio(): void
    {
        /*
         * Aunque el negocio pida automático. Prometerlo dejaría mensajes "en
         * cola" que nadie va a mover nunca, y la pantalla diría que están por
         * salir cuando en realidad esperan a que alguien los copie.
         */
        $this->business->update(['messaging_mode' => 'auto']);

        $mensaje = $this->dispatcher()->queue(
            $this->business->fresh(),
            Message::KIND_REMINDER,
            '+573001112233',
            'Hola',
        );

        $this->assertSame(Message::STATUS_MANUAL, $mensaje->status);
    }

    public function test_un_canal_caido_guarda_el_motivo(): void
    {
        /*
         * Tragarse el error deja a quien administra con un "falló" que no se
         * puede accionar. La diferencia entre un timeout y un número inválido
         * es la diferencia entre reintentar y corregir la ficha.
         */
        $this->business->update(['messaging_mode' => 'auto']);

        $this->canal(FakeMessagingChannel::caido());

        $mensaje = $this->dispatcher()->queue(
            $this->business->fresh(),
            Message::KIND_REMINDER,
            '+573001112233',
            'Hola',
        );

        $this->assertSame(Message::STATUS_FAILED, $mensaje->status);
        $this->assertStringContainsString('Timeout', $mensaje->error);
        $this->assertSame(1, $mensaje->attempts);
    }

    public function test_lo_fallido_tambien_sale_en_la_bandeja(): void
    {
        // Un mensaje fallido que no se puede mirar ni reintentar es un cliente
        // que no se enteró de nada y un negocio que cree que sí.
        Message::create([
            'business_id' => $this->business->id,
            'kind' => Message::KIND_REMINDER,
            'to' => '+573001112233',
            'body' => 'Hola',
            'status' => Message::STATUS_FAILED,
            'error' => 'Timeout',
        ]);

        $body = $this->getJson('/api/v1/messages')->assertOk();

        $this->assertCount(1, $body->json('data'));
        $this->assertSame('Timeout', $body->json('data.0.error'));
    }

    // ---- No mandar dos veces ----

    public function test_no_se_manda_dos_veces_lo_mismo_a_la_misma_cita(): void
    {
        /*
         * Por el índice único, no por un contador. Es la lección de
         * `gamification:recalculate` en Blue Souls: los contadores se
         * desincronizan y hay que escribir un comando para repararlos. Una
         * restricción no se desincroniza.
         */
        $cita = $this->agendar();

        $primero = $this->dispatcher()->queue(
            $this->business, Message::KIND_REMINDER, '+573001112233', 'Recordatorio', $cita,
        );
        $segundo = $this->dispatcher()->queue(
            $this->business, Message::KIND_REMINDER, '+573001112233', 'Recordatorio otra vez', $cita,
        );

        $this->assertNotNull($primero);
        $this->assertNull($segundo);
        $this->assertSame(1, Message::where('appointment_id', $cita->id)->count());
    }

    public function test_tipos_distintos_de_la_misma_cita_si_conviven(): void
    {
        // El recordatorio y la encuesta son dos mensajes legítimos de la misma
        // visita: la restricción es por tipo, no por cita.
        $cita = $this->agendar();

        $this->dispatcher()->queue($this->business, Message::KIND_REMINDER, '+573001112233', 'A', $cita);
        $this->dispatcher()->queue($this->business, Message::KIND_SURVEY, '+573001112233', 'B', $cita);

        $this->assertSame(2, Message::where('appointment_id', $cita->id)->count());
    }

    public function test_los_mensajes_sueltos_si_se_pueden_repetir(): void
    {
        // Sin cita no hay a qué pertenecer: una promoción o algo escrito a mano
        // no se pelea con la regla de "uno por cita".
        $this->dispatcher()->queue($this->business, Message::KIND_REMINDER, '+573001112233', 'A');
        $this->dispatcher()->queue($this->business, Message::KIND_REMINDER, '+573001112233', 'B');

        $this->assertSame(2, Message::count());
    }

    // ---- Lo que no se guarda ----

    public function test_sin_telefono_no_se_guarda_nada(): void
    {
        // No es un error: que una cita no tenga teléfono es normal. Guardar un
        // mensaje sin destinatario llenaría la bandeja de cosas imposibles.
        $this->assertNull(
            $this->dispatcher()->queue($this->business, Message::KIND_REMINDER, null, 'Hola'),
        );

        $this->assertSame(0, Message::count());
    }

    public function test_un_telefono_invalido_tampoco(): void
    {
        $this->assertNull(
            $this->dispatcher()->queue($this->business, Message::KIND_REMINDER, 'no es un numero', 'Hola'),
        );
    }

    public function test_un_cuerpo_vacio_tampoco(): void
    {
        // Una plantilla sin llenar produce texto vacío: mandarlo sería mandarle
        // un mensaje en blanco a una clienta.
        $this->assertNull(
            $this->dispatcher()->queue($this->business, Message::KIND_REMINDER, '+573001112233', '   '),
        );
    }

    // ---- Aislamiento ----

    public function test_no_se_ven_los_mensajes_de_otro_negocio(): void
    {
        $otro = $this->makeBusiness();

        /*
         * Insert directo, no `Message::create()`.
         *
         * `BelongsToBusiness` sobrescribe el `business_id` con el del usuario
         * autenticado, y acá hay uno: el mensaje "del otro spa" terminaría en
         * el mío y la prueba pasaría sin probar nada. `withoutGlobalScopes()`
         * no ayuda — quita los scopes de LECTURA, no el hook de creación.
         */
        DB::table('messages')->insert([
            'business_id' => $otro->id,
            'kind' => Message::KIND_REMINDER,
            'to' => '+573009998877',
            'body' => 'De otro spa',
            'status' => Message::STATUS_MANUAL,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertCount(0, $this->getJson('/api/v1/messages')->assertOk()->json('data'));
    }

    public function test_descartar_uno_lo_borra(): void
    {
        // Un mensaje descartado no es un dato histórico que haya que explicar,
        // y dejarlo en la lista con otro estado es no descartarlo.
        $mensaje = $this->dispatcher()->queue(
            $this->business, Message::KIND_REMINDER, '+573001112233', 'Hola',
        );

        $this->deleteJson("/api/v1/messages/{$mensaje->id}")->assertOk();

        $this->assertSame(0, Message::count());
    }
}
