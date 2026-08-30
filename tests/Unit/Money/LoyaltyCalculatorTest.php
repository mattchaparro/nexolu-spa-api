<?php

namespace Tests\Unit\Money;

use App\Support\Money\LoyaltyCalculator;
use PHPUnit\Framework\TestCase;

/**
 * La aritmética de la tarjeta de sellos.
 *
 * Los casos son los bordes que le costarían plata al negocio o confianza al
 * cliente: un premio que vale más que la cuenta, una tarjeta configurada en
 * cero, un saldo que ya alcanza para dos premios.
 */
class LoyaltyCalculatorTest extends TestCase
{
    public function test_como_va_la_tarjeta(): void
    {
        $progreso = LoyaltyCalculator::progress(3, 5);

        $this->assertSame(3, $progreso['stamps']);
        $this->assertSame(2, $progreso['remaining']);
        $this->assertFalse($progreso['complete']);
    }

    public function test_la_tarjeta_completa_se_reconoce(): void
    {
        $this->assertTrue(LoyaltyCalculator::progress(5, 5)['complete']);
        $this->assertSame(0, LoyaltyCalculator::progress(5, 5)['remaining']);
    }

    public function test_una_tarjeta_de_cero_sellos_no_regala_nada(): void
    {
        /*
         * Un programa mal configurado en 0 daría un premio en cada visita,
         * para siempre. Se trata como "sin programa": el error se nota porque
         * nadie gana nada, no porque el negocio pierda plata.
         */
        $this->assertFalse(LoyaltyCalculator::progress(10, 0)['complete']);
        $this->assertSame(0, LoyaltyCalculator::completedCards(10, 0));
    }

    public function test_un_saldo_grande_da_varias_tarjetas(): void
    {
        // Doce sellos con tarjeta de cinco: dos premios y sobran dos sellos.
        // Entregar uno solo y tirar el resto sería quedarse con sellos que la
        // persona ya se ganó.
        $this->assertSame(2, LoyaltyCalculator::completedCards(12, 5));
        $this->assertSame(0, LoyaltyCalculator::completedCards(4, 5));
        $this->assertSame(1, LoyaltyCalculator::completedCards(5, 5));
    }

    public function test_un_porcentaje_descuenta_sobre_la_cuenta(): void
    {
        $this->assertEqualsWithDelta(
            20000,
            LoyaltyCalculator::discountFor(LoyaltyCalculator::REWARD_DISCOUNT_PERCENT, 20, 100000),
            0.01,
        );
    }

    public function test_un_premio_nunca_deja_al_negocio_devolviendo_plata(): void
    {
        // Bono de 50.000 sobre una visita de 30.000: descuenta 30.000 y lo que
        // sobra se pierde, como un bono en el mostrador.
        $this->assertEqualsWithDelta(
            30000,
            LoyaltyCalculator::discountFor(LoyaltyCalculator::REWARD_DISCOUNT_AMOUNT, 50000, 30000),
            0.01,
        );
    }

    public function test_un_porcentaje_fuera_de_rango_se_acota(): void
    {
        $this->assertEqualsWithDelta(
            100000,
            LoyaltyCalculator::discountFor(LoyaltyCalculator::REWARD_DISCOUNT_PERCENT, 250, 100000),
            0.01,
        );
    }

    public function test_el_servicio_gratis_no_se_resuelve_aca(): void
    {
        // Depende del precio que tenga ESA línea en ESA cita, y eso lo sabe el
        // checkout, no una función sin base de datos.
        $this->assertEqualsWithDelta(
            0,
            LoyaltyCalculator::discountFor(LoyaltyCalculator::REWARD_FREE_SERVICE, null, 100000),
            0.01,
        );
    }

    public function test_una_visita_por_debajo_del_minimo_no_da_sello(): void
    {
        /*
         * En el sistema viejo un retoque de 25.000 daba el mismo sello que un
         * juego de acrílicas de 180.000: la tarjeta se llenaba con lo barato y
         * el premio salía del margen de lo caro.
         */
        $this->assertFalse(LoyaltyCalculator::earnsStamp(25000, 40000));
        $this->assertTrue(LoyaltyCalculator::earnsStamp(40000, 40000));
        $this->assertTrue(LoyaltyCalculator::earnsStamp(180000, 40000));
    }

    public function test_sin_minimo_toda_visita_cuenta(): void
    {
        $this->assertTrue(LoyaltyCalculator::earnsStamp(1000, 0));
        // Una visita en cero no cuenta: no hubo consumo que premiar.
        $this->assertFalse(LoyaltyCalculator::earnsStamp(0, 0));
    }

    public function test_como_se_le_explica_el_premio(): void
    {
        $this->assertSame(
            '20% de descuento',
            LoyaltyCalculator::label(LoyaltyCalculator::REWARD_DISCOUNT_PERCENT, 20),
        );
        $this->assertSame(
            '$30.000 de descuento',
            LoyaltyCalculator::label(LoyaltyCalculator::REWARD_DISCOUNT_AMOUNT, 30000),
        );
        $this->assertSame(
            'Manicure gratis',
            LoyaltyCalculator::label(LoyaltyCalculator::REWARD_FREE_SERVICE, null, 'Manicure'),
        );
    }
}
