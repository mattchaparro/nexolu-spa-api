<?php

namespace Tests\Feature\Ai;

use App\Ai\DateInText;
use App\Ai\UltimoPedido;
use Tests\TestCase;

/**
 * Lo que la clienta escribió manda sobre lo que el modelo interprete.
 *
 * Valentina: "para mañana después de las 5" terminó en una llamada con
 * fecha=hoy, dos veces. La fecha y la franja se extraen del texto crudo
 * en código y pisan los argumentos del modelo durante la ventana.
 */
class DateInTextTest extends TestCase
{
    public function test_encuentra_lo_inequivoco(): void
    {
        $casos = [
            'para mañana despues de las 5' => ['fecha' => 'mañana', 'franja' => 'tarde'],
            'mañana en la mañana porfa' => ['fecha' => 'mañana', 'franja' => 'mañana'],
            'el jueves por la tarde' => ['fecha' => 'jueves', 'franja' => 'tarde'],
            'tienen algo hoy?' => ['fecha' => 'hoy', 'franja' => null],
            'pasado mañana' => ['fecha' => 'pasado mañana', 'franja' => null],
            'el sábado en la noche' => ['fecha' => 'sabado', 'franja' => 'noche'],
            'a partir de las 6' => ['fecha' => null, 'franja' => 'tarde'],
            'despues de las 19' => ['fecha' => null, 'franja' => 'noche'],
        ];

        foreach ($casos as $texto => $esperado) {
            $this->assertSame($esperado, DateInText::find($texto), "«{$texto}»");
        }
    }

    public function test_lo_ambiguo_no_se_toca(): void
    {
        foreach (['quiero una cita', 'el 25 de septiembre', 'en quince dias', 'despues de las 9'] as $texto) {
            $this->assertSame(['fecha' => null, 'franja' => null], DateInText::find($texto), "«{$texto}»");
        }
    }

    public function test_lo_escrito_pisa_al_modelo_mientras_dura_la_ventana(): void
    {
        $phone = '573001112233';
        UltimoPedido::olvidar($phone);

        DateInText::remember($phone, 'para mañana despues de las 5 porfa');

        // El modelo llama con fecha=hoy inventada: gana lo escrito.
        $args = UltimoPedido::completar($phone, ['servicio' => 'Semipermanente', 'fecha' => 'hoy']);

        $this->assertSame('mañana', $args['fecha']);
        $this->assertSame('tarde', $args['franja']);
    }

    public function test_pasada_la_ventana_el_modelo_recupera_la_voz(): void
    {
        $phone = '573001112234';
        UltimoPedido::olvidar($phone);

        DateInText::remember($phone, 'para mañana en la tarde');
        $this->travel(10)->minutes();

        // Ella cambio de fecha con palabras que el extractor no entiende
        // ("el 25"); el modelo la entendio y su argumento debe pasar.
        $args = UltimoPedido::completar($phone, ['servicio' => 'Semipermanente', 'fecha' => '2026-09-25']);

        $this->assertSame('2026-09-25', $args['fecha']);
    }

    public function test_un_mensaje_sin_fecha_no_borra_nada_ni_manda(): void
    {
        $phone = '573001112235';
        UltimoPedido::olvidar($phone);
        UltimoPedido::guardar($phone, ['servicios' => ['Semipermanente'], 'fecha' => 'el jueves']);

        DateInText::remember($phone, 'si, dale');

        $pedido = UltimoPedido::ver($phone);
        $this->assertSame('el jueves', $pedido['fecha']);
        $this->assertArrayNotHasKey('texto_manda', $pedido);
    }
}
