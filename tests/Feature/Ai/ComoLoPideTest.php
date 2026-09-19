<?php

namespace Tests\Feature\Ai;

use App\Ai\ComoLoPide;
use App\Models\Service;
use App\Models\ServiceCategory;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * "Las manitos" tiene que llegar al catálogo, y solo a lo que es.
 *
 * En las pruebas con mensajes reales el bot se caía siempre en el mismo
 * punto: la clienta pedía como pide la gente -- "arreglarse las manitos",
 * "hacerme las uñas" -- y el agente respondía «¿qué servicio quieres?»,
 * que es pedirle que adivine cómo bautizamos nosotros el servicio.
 *
 * La traducción va por CATEGORÍA y no por nombre porque es ahí donde el
 * local ya dijo qué es qué: en este catálogo ningún servicio de manos
 * tiene la palabra "manicure" adentro -- se llaman Semipermanente,
 * Tradicional, Capping -- pero todos cuelgan de la categoría Manicure.
 *
 * Esto se prueba acá y no en `ia:evaluar` a propósito: la evaluación
 * llama al modelo de verdad y dos corridas seguidas del mismo código dan
 * números distintos. Una tabla de palabras no se mide con una regla que
 * se mueve.
 */
class ComoLoPideTest extends TestCase
{
    /**
     * Un catálogo como el de verdad: los nombres no dicen de qué parte
     * del cuerpo son, la categoría sí.
     *
     * @param  array<string, list<string>>  $porCategoria
     * @return Collection<int, Service>
     */
    private function catalogo(array $porCategoria): Collection
    {
        $servicios = collect();

        foreach ($porCategoria as $categoria => $nombres) {
            $cat = new ServiceCategory(['name' => $categoria]);

            foreach ($nombres as $nombre) {
                $servicio = new Service(['name' => $nombre]);
                $servicio->setRelation('category', $cat);
                $servicios->push($servicio);
            }
        }

        return $servicios;
    }

    /** @return Collection<int, Service> */
    private function luxury(): Collection
    {
        return $this->catalogo([
            'Manicure' => [
                'Semipermanente', 'Tradicional', 'Capping', 'Retoque Capping',
                'Retoque Acrílico Semi', 'Retiro Semipermanente',
            ],
            'Pedicure' => ['Pedi - Jellyspa', 'Pedi + Jelly Spa + Semi'],
            'Pestañas' => ['Wispy', 'Egipcio 5D', 'Retoque de pestañas'],
            'Cejas' => ['Hilo con Henna'],
        ]);
    }

    public function test_las_manitos_traen_la_categoria_manicure_entera(): void
    {
        $candidatos = ComoLoPide::candidatos($this->luxury(), 'arreglarse las manitos');

        $this->assertSame(
            ['Semipermanente', 'Tradicional', 'Capping', 'Retoque Capping',
                'Retoque Acrílico Semi', 'Retiro Semipermanente'],
            $candidatos->pluck('name')->all(),
        );
    }

    public function test_quien_pide_manos_no_termina_viendo_pestanas(): void
    {
        // Lo que pidió Alejandro con todas las letras: si pide manicure,
        // solo manicure. Mezclar categorías es ofrecerle a alguien que
        // quiere las uñas un servicio de cejas.
        $candidatos = ComoLoPide::candidatos($this->luxury(), 'quiero manicure');

        $categorias = $candidatos->map(fn (Service $s) => $s->category->name)->unique()->values();
        $this->assertSame(['Manicure'], $categorias->all());
    }

    public function test_los_pieses_son_pedicure(): void
    {
        $candidatos = ComoLoPide::candidatos($this->luxury(), 'quiero hacerme los pieses');

        $this->assertSame(['Pedi - Jellyspa', 'Pedi + Jelly Spa + Semi'], $candidatos->pluck('name')->all());
    }

    public function test_decir_el_tipo_acota_dentro_de_la_categoria(): void
    {
        // "Un retoque de manos" son los dos retoques de Manicure, no los
        // veintitrés servicios ni el retoque de pestañas.
        $candidatos = ComoLoPide::candidatos($this->luxury(), 'necesito un retoque de manos');

        $this->assertSame(['Retoque Capping', 'Retoque Acrílico Semi'], $candidatos->pluck('name')->all());
    }

    public function test_las_unas_de_los_pies_son_los_pies(): void
    {
        /*
         * "Uñas" sugiere manos, pero si en la misma frase dice "pies",
         * manda lo que dijo. Ofrecerle manicure a quien pidió pedicure
         * es hacerla repetir.
         */
        $candidatos = ComoLoPide::candidatos($this->luxury(), 'las uñas de los pies');

        $this->assertSame(['Pedi - Jellyspa', 'Pedi + Jelly Spa + Semi'], $candidatos->pluck('name')->all());
    }

    public function test_hacerme_las_unas_sin_mas_es_manicure(): void
    {
        $candidatos = ComoLoPide::candidatos($this->luxury(), 'queria ver si alcanzo a hacerme las uñas');

        $this->assertSame('Manicure', $candidatos->first()->category->name);
        $this->assertCount(6, $candidatos);
    }

    public function test_un_tipo_que_no_existe_en_esa_categoria_no_deja_sin_nada(): void
    {
        // No hay "acrílico" en Pestañas: mejor ofrecerle lo que sí hay de
        // pestañas que decirle que no hay nada.
        $candidatos = ComoLoPide::candidatos($this->luxury(), 'pestañas acrílicas');

        $this->assertSame(['Wispy', 'Egipcio 5D', 'Retoque de pestañas'], $candidatos->pluck('name')->all());
    }

    public function test_lo_que_no_se_entiende_no_se_adivina(): void
    {
        // Devolver "lo que más se parece" es como se termina cobrando
        // acrílicas a quien pidió otra cosa.
        $candidatos = ComoLoPide::candidatos($this->luxury(), 'un masaje descontracturante');

        $this->assertTrue($candidatos->isEmpty());
    }

    public function test_no_ofrece_una_categoria_que_este_local_no_tiene(): void
    {
        // Pide pestañas donde solo hay manos y pies: no se inventa nada.
        $soloUnas = $this->catalogo(['Manicure' => ['Semipermanente'], 'Pedicure' => ['Pedi - Jellyspa']]);

        $this->assertTrue(ComoLoPide::candidatos($soloUnas, 'quiero pestañas')->isEmpty());
    }
}
