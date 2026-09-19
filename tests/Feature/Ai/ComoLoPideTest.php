<?php

namespace Tests\Feature\Ai;

use App\Ai\ComoLoPide;
use App\Models\Service;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * "Las manitos" tiene que llegar al catálogo.
 *
 * En las pruebas con mensajes reales el bot se caía siempre en el mismo
 * punto: la clienta pedía como pide la gente -- "arreglarse las manitos",
 * "hacerme las uñas" -- y el agente respondía «¿qué servicio quieres?»,
 * que es pedirle que adivine cómo bautizamos nosotros el servicio. Una
 * señora que escribió tres frases para pedir una cita recibía un
 * formulario.
 *
 * Esto se prueba acá y no en `ia:evaluar` a propósito: la evaluación
 * llama al modelo de verdad y dos corridas seguidas del mismo código dan
 * números distintos. Una tabla de palabras no tiene por qué medirse con
 * una regla que se mueve.
 */
class ComoLoPideTest extends TestCase
{
    /** @return Collection<int, Service> */
    private function catalogo(string ...$nombres): Collection
    {
        return collect($nombres)->map(fn (string $n) => new Service(['name' => $n]));
    }

    public function test_las_manitos_son_el_manicure(): void
    {
        $candidatos = ComoLoPide::candidatos(
            $this->catalogo('Manicure tradicional', 'Pedicure spa', 'Cejas'),
            'arreglarse las manitos',
        );

        $this->assertSame(['Manicure tradicional'], $candidatos->pluck('name')->all());
    }

    public function test_los_pieses_son_el_pedicure(): void
    {
        $candidatos = ComoLoPide::candidatos(
            $this->catalogo('Manicure tradicional', 'Pedicure spa'),
            'quiero hacerme los pieses',
        );

        $this->assertSame(['Pedicure spa'], $candidatos->pluck('name')->all());
    }

    public function test_hacerme_las_unas_da_para_varias_y_las_devuelve_todas(): void
    {
        /*
         * Acá NO hay que elegir por ella: "las uñas" son tres servicios
         * distintos con tres precios distintos. Lo que se gana es que
         * elija entre nombres de verdad, tocables, en vez de deletrear
         * uno.
         */
        $candidatos = ComoLoPide::candidatos(
            $this->catalogo('Manicure tradicional', 'Semipermanente', 'Uñas acrílicas', 'Cejas'),
            'queria ver si alcanzo a hacerme las uñas',
        );

        $this->assertSame(
            ['Manicure tradicional', 'Semipermanente', 'Uñas acrílicas'],
            $candidatos->pluck('name')->all(),
        );
    }

    public function test_semi_permanente_separado_tambien_llega(): void
    {
        $candidatos = ComoLoPide::candidatos(
            $this->catalogo('Manicure semipermanente', 'Pedicure spa'),
            'semi permanente',
        );

        $this->assertSame(['Manicure semipermanente'], $candidatos->pluck('name')->all());
    }

    public function test_lo_que_no_se_entiende_no_se_adivina(): void
    {
        // Devolver "lo que más se parece" es como se termina cobrando
        // acrílicas a quien pidió otra cosa: mejor vacío y que el agente
        // diga qué SÍ hay.
        $candidatos = ComoLoPide::candidatos(
            $this->catalogo('Manicure tradicional', 'Pedicure spa'),
            'un masaje descontracturante',
        );

        $this->assertTrue($candidatos->isEmpty());
    }

    public function test_no_inventa_servicios_que_el_local_no_presta(): void
    {
        // Pide uñas donde solo hay pedicure y cejas: no se le ofrece un
        // manicure que no existe en este catálogo.
        $candidatos = ComoLoPide::candidatos(
            $this->catalogo('Pedicure spa', 'Cejas'),
            'las manitos',
        );

        $this->assertTrue($candidatos->isEmpty());
    }
}
