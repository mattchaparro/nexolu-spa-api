<?php

namespace Tests\Unit\Money;

use App\Support\Money\CommissionPolicy;
use PHPUnit\Framework\TestCase;

/**
 * Sobre qué valor se paga comisión cuando hubo descuento.
 *
 * La pregunta real: si a una clienta le regalan el servicio por su tarjeta de
 * sellos, ¿quien la atendió trabaja gratis? No hay una respuesta correcta para
 * todos los negocios, y por eso se configura por origen del descuento.
 */
class CommissionPolicyTest extends TestCase
{
    public function test_un_descuento_sobre_lo_cobrado_baja_la_comision(): void
    {
        $this->assertEqualsWithDelta(
            20000,
            CommissionPolicy::discountAffectingCommission(
                [CommissionPolicy::SOURCE_MANUAL => 20000],
                [CommissionPolicy::SOURCE_MANUAL => CommissionPolicy::BASE_CHARGED],
            ),
            0.01,
        );
    }

    public function test_un_descuento_sobre_lista_no_baja_la_comision(): void
    {
        $this->assertEqualsWithDelta(
            0,
            CommissionPolicy::discountAffectingCommission(
                [CommissionPolicy::SOURCE_LOYALTY => 50000],
                [CommissionPolicy::SOURCE_LOYALTY => CommissionPolicy::BASE_LIST],
            ),
            0.01,
        );
    }

    public function test_un_cobro_puede_mezclar_origenes_con_reglas_distintas(): void
    {
        /*
         * Un combo con un premio encima: el combo le baja la comisión, el
         * premio no. Cada parte se trata por su cuenta -- si se decidiera por
         * el total, una de las dos reglas quedaría sin aplicarse.
         */
        $total = CommissionPolicy::discountAffectingCommission(
            [
                CommissionPolicy::SOURCE_PACKAGE => 15000,
                CommissionPolicy::SOURCE_LOYALTY => 50000,
            ],
            [
                CommissionPolicy::SOURCE_PACKAGE => CommissionPolicy::BASE_CHARGED,
                CommissionPolicy::SOURCE_LOYALTY => CommissionPolicy::BASE_LIST,
            ],
        );

        $this->assertEqualsWithDelta(15000, $total, 0.01);
    }

    public function test_un_origen_sin_configurar_baja_la_comision(): void
    {
        /*
         * Es el comportamiento que el sistema ya tenía, y el conservador: al
         * revés, un origen nuevo que nadie configuró pagaría comisión sobre
         * plata que no entró, y eso se descubre en la nómina.
         */
        $this->assertEqualsWithDelta(
            30000,
            CommissionPolicy::discountAffectingCommission(
                ['un_origen_nuevo' => 30000],
                [],
            ),
            0.01,
        );
    }

    public function test_un_origen_en_cero_no_aporta(): void
    {
        $this->assertEqualsWithDelta(
            0,
            CommissionPolicy::discountAffectingCommission(
                [CommissionPolicy::SOURCE_MANUAL => 0],
                [CommissionPolicy::SOURCE_MANUAL => CommissionPolicy::BASE_CHARGED],
            ),
            0.01,
        );
    }

    public function test_cada_origen_tiene_como_explicarse(): void
    {
        // Un interruptor que dice `commission_base_loyalty` no se configura
        // con confianza.
        foreach (CommissionPolicy::sources() as $source) {
            $this->assertArrayHasKey($source, CommissionPolicy::labels());
            $this->assertNotSame($source, CommissionPolicy::labels()[$source]['label']);
        }
    }
}
