<?php

namespace Tests\Feature\Ai;

use App\Ai\ServicioEnTexto;
use App\Ai\UltimoPedido;
use App\Models\Business;
use App\Models\ServiceCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Scheduling\SchedulingScenario;
use Tests\TestCase;

/**
 * Lo que la clienta escribe sobre el servicio manda sobre lo que el modelo
 * resume.
 *
 * El caso de origen: Julián escribió «quiero agendar una cita de
 * semipermanente» y enseguida «pero de hombre». El modelo llamó a la
 * herramienta con `servicio: semipermanente` y el bot iba a agendarle el de
 * mujer -- veinte mil pesos más que lo que pidió.
 */
class ServicioEnTextoTest extends TestCase
{
    use RefreshDatabase, SchedulingScenario;

    private const PHONE = '573001112233';

    private Business $business;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = $this->makeBusiness();
        $persona = $this->makeResource($this->business, 'Maria');

        /*
         * Con sus categorias, como en produccion: ComoLoPide filtra por ellas
         * («semipermanente» es Manicure), y sin categoria no encuentra nada.
         * Un catalogo sin categorias haria pasar la prueba por la razon
         * equivocada o no pasarla por ninguna.
         */
        $categorias = [
            'Manicure' => ['Semipermanente', 'Semipermanente Hombre', 'Tradicional'],
            'Pedicure' => ['Pedi - Hombre - Semi'],
        ];

        foreach ($categorias as $categoria => $nombres) {
            $cat = ServiceCategory::create([
                'business_id' => $this->business->id,
                'name' => $categoria,
                'is_active' => true,
            ]);

            foreach ($nombres as $nombre) {
                $this->makeService($this->business, 60, [$persona], name: $nombre)
                    ->update(['service_category_id' => $cat->id]);
            }
        }
    }

    /** @param array<string, mixed> $argumentos */
    private function completar(array $argumentos): array
    {
        return UltimoPedido::completar(self::PHONE, $argumentos);
    }

    public function test_el_de_hombre_que_escribio_no_se_pierde(): void
    {
        ServicioEnTexto::remember(
            self::PHONE,
            "Quiero agendar una cita de semipermanente\nPero de hombre",
            $this->business->id,
        );

        $this->assertSame(
            'Semipermanente Hombre',
            $this->completar(['servicio' => 'semipermanente'])['servicio'],
        );
    }

    public function test_si_no_dijo_mas_no_se_inventa_nada(): void
    {
        // Pidió semipermanente a secas: es el de siempre, no el de hombre.
        ServicioEnTexto::remember(self::PHONE, 'quiero un semipermanente', $this->business->id);

        $this->assertSame(
            'semipermanente',
            $this->completar(['servicio' => 'semipermanente'])['servicio'],
        );
    }

    public function test_un_servicio_que_no_tiene_que_ver_no_pisa_al_modelo(): void
    {
        /*
         * Si la clienta nombró otra cosa --de pasada, en una pregunta--, eso
         * no es una versión más específica de lo que eligió el modelo, y no
         * se le pasa por encima: pudo tener buenas razones.
         */
        ServicioEnTexto::remember(self::PHONE, 'y el tradicional cuanto dura?', $this->business->id);

        $this->assertSame(
            'semipermanente',
            $this->completar(['servicio' => 'semipermanente'])['servicio'],
        );
    }

    public function test_cuando_hay_dos_versiones_posibles_no_se_adivina(): void
    {
        $this->assertNull(ServicioEnTexto::masEspecifico(
            'semi',
            ['Semipermanente Hombre', 'Pedi - Hombre - Semi'],
        ), 'Si lo del modelo cabe en dos nombres dichos, elegir uno sería adivinar.');
    }

    public function test_lo_dicho_hace_rato_ya_no_manda(): void
    {
        ServicioEnTexto::remember(
            self::PHONE,
            'semipermanente pero de hombre',
            $this->business->id,
        );

        $this->travel(11)->minutes();

        $this->assertSame(
            'semipermanente',
            $this->completar(['servicio' => 'semipermanente'])['servicio'],
        );
    }
}
