<?php

namespace Tests\Feature\WhatsApp;

use App\Ai\EsUnaPrueba;
use App\Services\WhatsApp\NexoluCommsChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Que la evaluación no le escriba a nadie.
 *
 * `ia:evaluar` conversa con el bot como si fuera una clienta y el bot
 * contesta como le toca: mandando listas de horas por WhatsApp. El
 * número con el que conversa es de una persona de verdad -- a propósito,
 * para que un mensaje que se escape le llegue a quien está probando y no
 * a una clienta -- así que lo que impide que le llenen el teléfono en
 * cada corrida es esta marca. Si deja de funcionar, no hay error: hay
 * treinta mensajes.
 *
 * Se prueba en el canal y no en el comando porque el envío lo hace otro
 * proceso: la evaluación le habla al Core, el Core le pega al endpoint
 * de herramientas, y ese request no sabe que enfrente tiene una prueba.
 */
class EsUnaPruebaTest extends TestCase
{
    use RefreshDatabase;

    private const PHONE = '573001112233';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.comms_core.api_key', 'llave-comms');
        config()->set('services.comms_core.base_url', 'http://comms.test');

        Http::fake([
            'comms.test/*' => Http::response(['results' => [['channel' => 'whatsapp', 'status' => 'sent']]]),
        ]);
    }

    public function test_con_la_marca_puesta_el_mensaje_no_sale(): void
    {
        EsUnaPrueba::marcar(self::PHONE);

        $salio = app(NexoluCommsChannel::class)->sendText(self::PHONE, 'no debe salir', 1);

        // `true` para quien llama -- lo que se mide es que el bot ofrezca,
        // no que Meta entregue -- pero nada se fue al canal.
        $this->assertTrue($salio);
        Http::assertNothingSent();
    }

    public function test_las_opciones_tocables_tampoco_salen(): void
    {
        // Son las que más se mandan en una evaluación: cada consulta de
        // agenda manda una lista de horas.
        EsUnaPrueba::marcar(self::PHONE);

        app(NexoluCommsChannel::class)->sendOptions(
            self::PHONE,
            'Tengo estas horas',
            [['id' => 'h0', 'title' => '10:00 am']],
            1,
        );

        Http::assertNothingSent();
    }

    public function test_el_mas_ni_el_signo_la_esquivan(): void
    {
        // El teléfono viaja a veces con `+` y a veces sin él según de
        // dónde salga; la marca no puede depender de eso.
        EsUnaPrueba::marcar('+'.self::PHONE);

        app(NexoluCommsChannel::class)->sendText(self::PHONE, 'no debe salir', 1);

        Http::assertNothingSent();
    }

    public function test_sin_marca_el_mensaje_sale_como_siempre(): void
    {
        // Lo importante del otro lado: que esto no deje mudo al negocio.
        app(NexoluCommsChannel::class)->sendText(self::PHONE, 'esto sí sale', 1);

        Http::assertSent(fn ($request) => ($request->data()['text'] ?? '') === 'esto sí sale');
    }

    public function test_al_olvidarla_vuelve_a_salir(): void
    {
        EsUnaPrueba::marcar(self::PHONE);
        EsUnaPrueba::olvidar(self::PHONE);

        app(NexoluCommsChannel::class)->sendText(self::PHONE, 'de nuevo', 1);

        Http::assertSentCount(1);
    }
}
