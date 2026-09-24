<?php

namespace Tests\Feature\Ai;

use App\Ai\UltimoPedido;
use Carbon\CarbonImmutable;
use Tests\TestCase;

/**
 * Quien deja una cita a medias la retoma cuando puede.
 *
 * El pedido vivía media hora. Alejandro se distrajo en el trabajo, volvió a
 * la conversación, y el bot ya no sabía qué servicio ni qué día le había
 * ofrecido: le tocaba empezar de cero. Ahora vive el resto del día.
 */
class RetomarElPedidoTest extends TestCase
{
    private const PHONE = '+573001112233';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-09-24 10:00', 'America/Bogota'));
    }

    public function test_el_pedido_sigue_ahi_horas_despues(): void
    {
        UltimoPedido::guardar(self::PHONE, ['servicios' => ['Semipermanente'], 'fecha' => 'mañana']);

        // Se distrajo toda la mañana.
        $this->travel(4)->hours();

        $this->assertSame(['Semipermanente'], UltimoPedido::ver(self::PHONE)['servicios'] ?? null);
    }

    public function test_un_pedido_de_ayer_no_se_retoma_hoy(): void
    {
        /*
         * El pedido guarda «mañana» como lo dijo la clienta. A las once de la
         * noche eso es el 25; a la una de la mañana, ese mismo «mañana» ya
         * sería el 26, y se le ofrecerían horas del día que no pidió. Cruzar
         * la medianoche descarta el pedido.
         */
        $this->travelTo(CarbonImmutable::parse('2026-09-24 23:00', 'America/Bogota'));
        UltimoPedido::guardar(self::PHONE, ['servicios' => ['Semipermanente'], 'fecha' => 'mañana']);

        $this->travelTo(CarbonImmutable::parse('2026-09-25 01:00', 'America/Bogota'));

        $this->assertSame([], UltimoPedido::ver(self::PHONE));
    }

    public function test_despues_de_ocho_horas_si_se_olvida(): void
    {
        UltimoPedido::guardar(self::PHONE, ['servicios' => ['Semipermanente']]);

        $this->travel(9)->hours();

        $this->assertSame([], UltimoPedido::ver(self::PHONE));
    }

    public function test_la_medianoche_es_la_de_colombia_y_no_la_del_servidor(): void
    {
        /*
         * El servidor corre en UTC. Con su medianoche, un pedido empezado a
         * las 6:30 pm en Sibaté se habría borrado a las 7:00 pm -- en pleno
         * horario del salón. El día que cuenta es el del negocio.
         */
        $this->travelTo(CarbonImmutable::parse('2026-09-24 18:30', 'America/Bogota'));
        UltimoPedido::guardar(self::PHONE, ['servicios' => ['Semipermanente']]);

        $this->travelTo(CarbonImmutable::parse('2026-09-24 20:00', 'America/Bogota'));

        $this->assertSame(['Semipermanente'], UltimoPedido::ver(self::PHONE)['servicios'] ?? null);
    }
}
