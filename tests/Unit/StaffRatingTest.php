<?php

namespace Tests\Unit;

use App\Support\StaffRating;
use PHPUnit\Framework\TestCase;

/**
 * La puntuación pública de una persona del equipo.
 *
 * Lo que se prueba no es la división — eso es aritmética — sino CUÁNDO se
 * puede mostrar. Esa decisión tiene consecuencias sobre una persona real: un
 * número en la vitrina del local que dice qué tan buena es su trabajo.
 */
class StaffRatingTest extends TestCase
{
    public function test_promedia_las_notas(): void
    {
        $this->assertSame(4.4, StaffRating::average([5, 4, 5, 4, 4]));
    }

    public function test_redondea_a_un_decimal(): void
    {
        // "4.3333333" en una vitrina no lo lee nadie.
        $this->assertSame(4.3, StaffRating::average([5, 4, 4, 4, 4, 5]));
    }

    public function test_sin_suficientes_notas_no_hay_promedio(): void
    {
        /*
         * Con dos respuestas, una clienta que tuvo un mal día deja a alguien
         * en 3.0 para siempre. Un promedio de pocos datos no es información,
         * es ruido con cara de dato.
         */
        $this->assertNull(StaffRating::average([5, 3]));
        $this->assertNull(StaffRating::average([5, 5, 5, 5]));
        $this->assertNotNull(StaffRating::average([5, 5, 5, 5, 5]));
    }

    public function test_sin_ninguna_nota_devuelve_null_no_cero(): void
    {
        // Un 0.0 al lado de una foto lee como "pésimo", no como "todavía no
        // sabemos". Y un 5.0 de regalo es mentir.
        $this->assertNull(StaffRating::average([]));
        $this->assertSame(0, StaffRating::count([]));
    }

    public function test_ignora_las_respuestas_sin_estrellas(): void
    {
        // Alguien respondió la encuesta y escribió un comentario sin calificar.
        // Eso no es un cero: es una respuesta sin nota.
        $this->assertSame(5, StaffRating::count([5, null, 4, 5, 4, null, 5]));
        $this->assertSame(4.6, StaffRating::average([5, null, 4, 5, 4, null, 5]));
    }

    public function test_descarta_notas_fuera_de_rango(): void
    {
        /*
         * Sólo pueden venir de un payload manipulado. Se ignoran en silencio
         * en vez de reventar: una nota rara no puede tumbar la página pública
         * del negocio.
         */
        $this->assertSame(5, StaffRating::count([5, 99, 4, 0, 5, -3, 4, 5]));
        $this->assertSame(4.6, StaffRating::average([5, 99, 4, 0, 5, -3, 4, 5]));
    }

    public function test_el_conteo_acompana_al_promedio(): void
    {
        // "4.8" solo, sin decir de cuántas, es una cifra que no se puede
        // juzgar. La página muestra las dos.
        $notas = [5, 5, 4, 5, 5, 5];

        $this->assertSame(4.8, StaffRating::average($notas));
        $this->assertSame(6, StaffRating::count($notas));
    }

    public function test_el_minimo_se_puede_bajar_para_un_caso_puntual(): void
    {
        // Existe para las pruebas y para un negocio que quiera otra política,
        // no para saltarse la regla por defecto en silencio.
        $this->assertNull(StaffRating::average([5, 4]));
        $this->assertSame(4.5, StaffRating::average([5, 4], minimo: 2));
    }
}
