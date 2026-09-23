<?php

namespace Tests\Feature\Messaging;

use App\Models\Appointment;
use App\Models\AppointmentStageEvent;
use App\Models\AppointmentWorkflowStage;
use App\Models\Business;
use App\Models\Client;
use App\Models\Message;
use App\Models\Resource;
use App\Models\Service;
use App\Models\WhatsappConversation;
use App\Services\Messaging\Contracts\MessagingChannel;
use App\Services\Scheduling\Actions\NotifyClientAction;
use App\Services\Scheduling\Actions\StageActionContext;
use App\Services\Scheduling\BookingService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Scheduling\SchedulingScenario;
use Tests\Support\FakeMessagingChannel;
use Tests\TestCase;

/**
 * La confirmación de una cita que agenda el SALÓN.
 *
 * Es el único aviso que sale sin que la clienta haya escrito: se confirma la
 * agenda del día siguiente, o se agenda a alguien que no escribe hace un mes.
 * Fuera de las 24 horas Meta acepta el texto libre y NO lo entrega --sin
 * error, sin rebote-- así que ese mensaje no existía para ella.
 */
class ConfirmationTest extends TestCase
{
    use RefreshDatabase, SchedulingScenario;

    private const PHONE = '573001112233';

    private Business $business;

    private Client $carolina;

    private Service $semi;

    private Resource $maria;

    private FakeMessagingChannel $canal;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(
            CarbonImmutable::now('America/Bogota')->startOfDay()
                ->previous(CarbonImmutable::WEDNESDAY)->setTime(9, 0),
        );

        $this->canal = new FakeMessagingChannel;
        $this->app->instance(MessagingChannel::class, $this->canal);

        $this->business = $this->makeBusiness(['min_booking_notice_min' => 0]);
        $this->business->update(['messaging_mode' => 'auto']);
        $this->maria = $this->makeResource($this->business, 'Maria');
        $this->semi = $this->makeService($this->business, 60, [$this->maria], name: 'Semipermanente');

        $this->carolina = Client::create([
            'business_id' => $this->business->id,
            'name' => 'Carolina', 'last_name' => 'Pérez',
            'phone' => '+'.self::PHONE, 'is_active' => true,
        ]);
    }

    private function cita(): Appointment
    {
        return app(BookingService::class)->book(
            $this->business,
            [[
                'service_id' => $this->semi->id,
                'resource_id' => $this->maria->id,
                'starts_at' => CarbonImmutable::now('America/Bogota')->addDay()->setTime(15, 0),
            ]],
            $this->carolina,
            'Carolina Pérez',
            self::PHONE,
            Appointment::SOURCE_ADMIN,
            null,
            false,
        )->fresh();
    }

    /** Mueve la cita a «Confirmada», que es lo que hace el panel. */
    private function confirmar(Appointment $cita, string $texto = ''): void
    {
        $stage = new AppointmentWorkflowStage([
            'key' => 'confirmada',
            'label' => 'Confirmada',
            'maps_to_status' => Appointment::STATUS_CONFIRMED,
        ]);

        app(NotifyClientAction::class)->execute(new StageActionContext(
            $cita,
            $stage,
            ['template' => $texto],
            null,
            AppointmentStageEvent::ACTOR_USER,
        ));
    }

    /** La clienta escribió hace poco: la ventana de 24h está abierta. */
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

    public function test_fuera_de_la_ventana_sale_como_plantilla(): void
    {
        /*
         * Nadie escribió: la ventana nunca se abrió. Como texto libre, Meta
         * acepta el envío y no lo entrega -- el mensaje se ve enviado en la
         * bandeja y la clienta nunca lo recibió.
         */
        $this->confirmar($this->cita());

        $enviado = $this->canal->sent[0];

        $this->assertSame('confirmacion_cita', $enviado['template']);
        // El orden de las variables ES el contrato: Meta no recibe nombres.
        $this->assertSame(
            ['Jueves 17 de septiembre', '3:00 pm', 'Semipermanente', '$50.000', 'Maria', $this->business->name],
            $enviado['params'],
        );
    }

    public function test_con_la_ventana_abierta_sale_el_texto(): void
    {
        /*
         * Adentro gana el texto: lleva lo que la plantilla no puede --los
         * renglones que sobran, el Instagram-- y no gasta una plantilla en
         * una conversación que está viva.
         */
        $this->escribioHace(2);

        $this->confirmar($this->cita());

        $enviado = $this->canal->sent[0];

        $this->assertNull($enviado['template']);
        $this->assertStringContainsString('¡Tu cita quedó confirmada! ✅', $enviado['body']);
    }

    public function test_pasadas_las_24_horas_vuelve_a_ser_plantilla(): void
    {
        // La ventana es del último mensaje ENTRANTE, no de que exista el hilo.
        $this->escribioHace(30);

        $this->confirmar($this->cita());

        $this->assertSame('confirmacion_cita', $this->canal->sent[0]['template']);
    }

    public function test_el_texto_es_el_mismo_que_manda_el_bot(): void
    {
        /*
         * Día, hora, servicio, precio y quién atiende, cada cosa en su
         * renglón: el formato que Luxury ya usa en ManyChat y que sus
         * clientas reconocen. Agendar por el chat o que la agende el salón no
         * puede producir dos mensajes distintos de la misma cita.
         */
        $this->confirmar($this->cita());

        $cuerpo = Message::withoutGlobalScopes()->where('kind', Message::KIND_STAGE)->sole()->body;

        $this->assertStringContainsString('📅 Día: *Jueves 17 de septiembre*', $cuerpo);
        $this->assertStringContainsString('⏰ Hora: *3:00 pm*', $cuerpo);
        $this->assertStringContainsString('💅 Servicio: *Semipermanente*', $cuerpo);
        $this->assertStringContainsString('💵 Precio: *$50.000*', $cuerpo);
        $this->assertStringContainsString('🙋‍♀️ Te atiende: *Maria*', $cuerpo);
        $this->assertStringContainsString('Gracias por agendar en *'.$this->business->name.'*', $cuerpo);
    }

    public function test_el_texto_que_escribio_el_negocio_manda_sobre_el_nuestro(): void
    {
        // El que quiera otra redacción la escribe, y la suya gana dentro de
        // la ventana. Afuera sigue saliendo la plantilla: es lo único que
        // Meta entrega.
        $this->escribioHace(1);

        $this->confirmar($this->cita(), 'Hola {cliente}, te esperamos el {fecha}.');

        $this->assertSame('Hola Carolina, te esperamos el jueves 17 de septiembre.', $this->canal->sent[0]['body']);
    }

    /**
     * El negocio SÍ escribió qué decir después de la cita.
     *
     * Los botones de información salen por el canal de verdad
     * (NexoluCommsChannel), no por el doble: EnvioDirecto habla con él
     * directo para poder mandar opciones, que el contrato genérico no cubre.
     */
    private function conConocimiento(): void
    {
        config()->set('services.ia_core.api_key', 'llave-spa');
        config()->set('services.ia_core.base_url', 'http://ia-core.test');
        config()->set('services.comms_core.api_key', 'llave-comms');
        config()->set('services.comms_core.base_url', 'http://comms.test');
        Cache::forget('ia:conocimiento:'.$this->business->id);

        Http::fake([
            'ia-core.test/*' => Http::response([
                ['id' => '1', 'topic' => 'Garantías', 'answer' => 'Te la arreglamos gratis en 8 días.', 'is_active' => true],
                ['id' => '2', 'topic' => 'Recomendaciones y cuidados', 'answer' => 'No metas las manos en cloro.', 'is_active' => true],
            ]),
            'comms.test/*' => Http::response(['results' => [['channel' => 'whatsapp', 'status' => 'sent']]]),
        ]);
    }

    /** @return list<string> */
    private function botonesOfrecidos(): array
    {
        $titulos = [];

        Http::assertSent(function ($request) use (&$titulos) {
            $opciones = $request->data()['whatsapp_options']['options'] ?? null;

            if ($opciones !== null) {
                $titulos = array_column($opciones, 'title');
            }

            return true;
        });

        return $titulos;
    }

    public function test_detras_de_la_confirmacion_van_los_botones_de_informacion(): void
    {
        /*
         * Lo mismo que hace el bot cuando ella agenda sola, y lo que ya
         * conoce de ManyChat: garantías, recomendaciones, cancelaciones. Lo
         * hacía sólo el bot; una cita agendada desde el panel no ofrecía
         * nada.
         *
         * Y sólo los que el negocio TIENE escritos: acá no escribió lo de
         * cancelaciones, así que ese botón no aparece. Uno que contesta "no
         * tengo esa información" es peor que no estar.
         */
        $this->escribioHace(1);
        $this->conConocimiento();

        $this->confirmar($this->cita());

        $this->assertSame(['Info de garantías', 'Recomendaciones'], $this->botonesOfrecidos());
    }

    public function test_fuera_de_la_ventana_no_se_manda_un_segundo_mensaje(): void
    {
        /*
         * Ahí los botones viajan DENTRO de la plantilla (ver
         * docs/plantillas-whatsapp.md): un segundo mensaje de texto no se
         * entregaría, y quedaría como enviado en la bandeja sin haber
         * llegado.
         */
        $this->conConocimiento();

        $this->confirmar($this->cita());

        // Ni siquiera se le pregunta al Core qué ofrecer: no hay a dónde
        // mandarlo.
        Http::assertNothingSent();
    }

    public function test_cancelar_desde_el_panel_tambien_sale_como_plantilla(): void
    {
        /*
         * El salón cancela fuera de toda conversación --se enfermó quien
         * atendía-- así que la ventana está cerrada casi siempre. Sin
         * plantilla, la clienta se aparece a una cita que ya no existe.
         */
        $cita = $this->cita();
        $stage = new AppointmentWorkflowStage([
            'key' => 'cancelada',
            'label' => 'Cancelada',
            'maps_to_status' => Appointment::STATUS_CANCELLED,
        ]);

        app(NotifyClientAction::class)->execute(new StageActionContext(
            $cita,
            $stage,
            ['template' => ''],
            null,
            AppointmentStageEvent::ACTOR_USER,
        ));

        $enviado = $this->canal->sent[0];

        $this->assertSame('cita_cancelada', $enviado['template']);
        $this->assertSame(
            ['Carolina', 'jueves 17 de septiembre', '3:00 pm', $this->business->name],
            $enviado['params'],
        );
    }

    public function test_el_texto_se_guarda_aunque_salga_la_plantilla(): void
    {
        /*
         * Quien manda a mano desde su propio WhatsApp no usa plantillas:
         * copia el texto. Si el mensaje sólo llevara la plantilla, la bandeja
         * quedaría vacía de contenido -- y de "Hola Carolina, tu cita del
         * jueves..." no se sacan de vuelta las variables.
         */
        $this->confirmar($this->cita());

        $mensaje = Message::withoutGlobalScopes()->where('kind', Message::KIND_STAGE)->sole();

        $this->assertNotEmpty($mensaje->body);
        $this->assertSame('confirmacion_cita', $mensaje->template_name);
    }
}
