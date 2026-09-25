<?php

namespace Tests\Feature\Messaging;

use App\Models\Appointment;
use App\Models\AppointmentStageEvent;
use App\Models\AppointmentWorkflowStage;
use App\Models\Business;
use App\Models\Client;
use App\Models\LoyaltyProgram;
use App\Models\LoyaltyStamp;
use App\Models\Message;
use App\Models\Resource;
use App\Models\Service;
use App\Models\WhatsappConversation;
use App\Services\Messaging\Contracts\MessagingChannel;
use App\Services\Scheduling\Actions\NotifyClientAction;
use App\Services\Scheduling\Actions\StageActionContext;
use App\Services\Scheduling\BookingService;
use App\Support\Money\LoyaltyCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Scheduling\SchedulingScenario;
use Tests\Support\FakeMessagingChannel;
use Tests\TestCase;

/**
 * "Gracias por tu visita": el mensaje de cuando la manicurista termina.
 *
 * Es el que Luxury manda hoy desde ManyChat. Cierra la visita con el gracias,
 * CÓMO VA SU TARJETA de sellos y la invitación a calificar. La tarjeta es la
 * parte que hace volver: "te faltan 3" es una razón concreta para agendar, y
 * es información que la clienta no tiene de otra forma -- en el mostrador
 * nadie se la dice.
 */
class ThankYouTest extends TestCase
{
    use RefreshDatabase, SchedulingScenario;

    private const PHONE = '573001112233';

    private Business $business;

    private Client $carolina;

    private Service $manicure;

    private Resource $maria;

    private FakeMessagingChannel $canal;

    protected function setUp(): void
    {
        parent::setUp();

        /*
         * Un miércoles FIJO, no «el miércoles anterior a hoy». Las pruebas
         * esperan fechas escritas a mano («jueves 17 de septiembre»), y con
         * el reloj relativo al día real solo pasaban la semana en que se
         * escribieron: el 24 de septiembre el miércoles anterior ya era el 23
         * y el «mañana» de la prueba era el jueves 24.
         */
        $this->travelTo(CarbonImmutable::parse('2026-09-16 09:00', 'America/Bogota'));

        $this->canal = new FakeMessagingChannel;
        $this->app->instance(MessagingChannel::class, $this->canal);

        $this->business = $this->makeBusiness(['min_booking_notice_min' => 0]);
        $this->business->update(['messaging_mode' => 'auto']);
        $this->maria = $this->makeResource($this->business, 'Maria');
        $this->manicure = $this->makeService($this->business, 60, [$this->maria], name: 'Manicure');

        $this->carolina = Client::create([
            'business_id' => $this->business->id,
            'name' => 'Carolina', 'last_name' => 'Pérez',
            'phone' => '+'.self::PHONE, 'is_active' => true,
        ]);
    }

    /** Una tarjeta de 10 sellos con `$lleva` ya puestos. */
    private function conTarjeta(int $lleva = 7): LoyaltyProgram
    {
        $programa = LoyaltyProgram::create([
            'business_id' => $this->business->id,
            'name' => 'Tarjeta Luxury',
            'mode' => 'simple',
            'stamps_required' => 10,
            'reward_type' => LoyaltyCalculator::REWARD_DISCOUNT_PERCENT,
            'reward_value' => 15,
            'min_ticket' => 0,
            'is_active' => true,
        ]);

        // Cada sello cuelga de una visita: es lo que impide inventarse
        // sellos sueltos. Las de antes se crean directo, sin pasar por la
        // agenda -- lo que se está probando es el mensaje, no cómo se ganan.
        for ($i = 0; $i < $lleva; $i++) {
            $visita = Appointment::create([
                'business_id' => $this->business->id,
                'location_id' => $this->business->locations()->first()->id,
                'client_id' => $this->carolina->id,
                'client_name' => 'Carolina Pérez',
                'starts_at' => now()->subDays($i + 8),
                'ends_at' => now()->subDays($i + 8)->addHour(),
                'status' => Appointment::STATUS_COMPLETED,
            ]);

            LoyaltyStamp::create([
                'business_id' => $this->business->id,
                'program_id' => $programa->id,
                'client_id' => $this->carolina->id,
                'appointment_id' => $visita->id,
                'earned_at' => now()->subDays($i + 8),
            ]);
        }

        return $programa;
    }

    private function cita(): Appointment
    {
        return app(BookingService::class)->book(
            $this->business,
            [[
                'service_id' => $this->manicure->id,
                'resource_id' => $this->maria->id,
                'starts_at' => CarbonImmutable::now('America/Bogota')->setTime(10, 0),
            ]],
            $this->carolina,
            'Carolina Pérez',
            self::PHONE,
            Appointment::SOURCE_ADMIN,
            null,
            false,
        )->fresh();
    }

    /** Mueve la cita a «Lista y cobrada»: la manicurista terminó. */
    private function terminar(Appointment $cita): void
    {
        $stage = new AppointmentWorkflowStage([
            'key' => 'lista',
            'label' => 'Lista y cobrada',
            'maps_to_status' => Appointment::STATUS_COMPLETED,
        ]);

        app(NotifyClientAction::class)->execute(new StageActionContext(
            $cita,
            $stage,
            ['template' => ''],
            null,
            AppointmentStageEvent::ACTOR_USER,
        ));
    }

    private function escribioHace(int $horas): void
    {
        WhatsappConversation::withoutGlobalScope('business')->create([
            'business_id' => $this->business->id,
            'phone' => self::PHONE,
            'client_id' => $this->carolina->id,
            'last_message_at' => now()->subHours($horas),
            'last_inbound_at' => now()->subHours($horas),
            'status' => WhatsappConversation::STATUS_OPEN,
        ]);
    }

    public function test_al_terminar_le_agradece_y_le_dice_como_va_su_tarjeta(): void
    {
        $this->conTarjeta(lleva: 7);

        $this->terminar($this->cita());

        $cuerpo = Message::withoutGlobalScopes()->where('kind', Message::KIND_STAGE)->sole()->body;

        $this->assertStringContainsString('*Gracias por tu visita*', $cuerpo);
        $this->assertStringContainsString('¡Hola, Carolina!', $cuerpo);
        $this->assertStringContainsString('🧾 Servicio: *Manicure*', $cuerpo);
        $this->assertStringContainsString('*7 de 10* sellos', $cuerpo);
        $this->assertStringContainsString('🎁 Próximo: *15% de descuento*', $cuerpo);
    }

    public function test_fuera_de_la_ventana_sale_como_plantilla(): void
    {
        /*
         * La clienta vino al salón; no le escribió por WhatsApp. Ese es el
         * caso NORMAL de este mensaje, y como texto libre Meta lo acepta y no
         * lo entrega.
         */
        $this->conTarjeta(lleva: 7);

        $this->terminar($this->cita());

        $enviado = $this->canal->sent[0];

        $this->assertSame('gracias_por_tu_visita', $enviado['template']);
        $this->assertSame(
            ['Carolina', 'Manicure', 'Miércoles 16 de septiembre', '7', '10', '15% de descuento'],
            $enviado['params'],
        );
    }

    public function test_sin_programa_de_sellos_no_promete_una_tarjeta_que_no_existe(): void
    {
        /*
         * "¡Ya tienes 0 de 0 sellos!" es peor que no decir nada. Sin programa
         * el mensaje va sin esos renglones -- y sin plantilla, porque la
         * plantilla los nombra en renglones fijos.
         */
        $this->terminar($this->cita());

        $mensaje = Message::withoutGlobalScopes()->where('kind', Message::KIND_STAGE)->sole();

        $this->assertStringNotContainsString('sellos', $mensaje->body);
        $this->assertNull($mensaje->template_name);
    }

    public function test_con_la_ventana_abierta_ofrece_calificar_y_ver_la_tarjeta(): void
    {
        config()->set('services.comms_core.api_key', 'llave-comms');
        config()->set('services.comms_core.base_url', 'http://comms.test');
        Http::fake(['comms.test/*' => Http::response(['results' => [['channel' => 'whatsapp', 'status' => 'sent']]])]);

        $this->conTarjeta(lleva: 7);
        $this->escribioHace(2);

        $this->terminar($this->cita());

        $titulos = [];
        Http::assertSent(function ($request) use (&$titulos) {
            $opciones = $request->data()['whatsapp_options']['options'] ?? null;

            if ($opciones !== null) {
                $titulos = array_column($opciones, 'title');
            }

            return true;
        });

        // La opinión primero: es lo que se pide en caliente, cuando acaba de
        // ver sus uñas.
        $this->assertSame(['Calificar servicio', 'Mi tarjeta'], $titulos);
    }
}
