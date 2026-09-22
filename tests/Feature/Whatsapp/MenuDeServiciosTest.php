<?php

namespace Tests\Feature\Whatsapp;

use App\Models\Business;
use App\Models\ServiceCategory;
use App\Services\WhatsApp\MenuDeServicios;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Scheduling\SchedulingScenario;
use Tests\TestCase;

/**
 * El menú de WhatsApp sale del catálogo, no de un archivo escrito a mano.
 *
 * Por qué importa: el bot estaba ADIVINANDO. La clienta decía "pedicure"
 * y hay cuatro; decía "semipermanente" y hay tres. Elegir entre variantes
 * que solo el negocio conoce no es trabajo de un modelo -- es un botón.
 *
 * Y generado, no mantenido: un menú escrito una vez ofrece, seis meses
 * después, servicios que ya no se prestan.
 */
class MenuDeServiciosTest extends TestCase
{
    use RefreshDatabase, SchedulingScenario;

    private Business $business;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.comms_core.api_key', 'llave-comms');
        config()->set('services.comms_core.base_url', 'http://comms.test');

        $this->business = $this->makeBusiness();
        $this->business->update(['name' => 'Luxury Nails']);
    }

    private function categoria(string $nombre, int $orden = 0): ServiceCategory
    {
        return ServiceCategory::create([
            'business_id' => $this->business->id,
            'name' => $nombre,
            'sort_order' => $orden,
            'is_active' => true,
        ]);
    }

    private function servicio(ServiceCategory $categoria, string $nombre, int $precio = 45000): void
    {
        $recurso = $this->makeResource($this->business, 'Persona '.uniqid());
        $servicio = $this->makeService($this->business, 60, [$recurso], name: $nombre);
        $servicio->update(['service_category_id' => $categoria->id, 'price' => $precio]);
    }

    public function test_cada_categoria_es_una_fila_y_cada_servicio_un_boton(): void
    {
        $manicure = $this->categoria('Manicure');
        $this->servicio($manicure, 'Semipermanente', 45000);
        $this->servicio($manicure, 'Semi + Rubber', 55000);
        $pedicure = $this->categoria('Pedicure', 1);
        $this->servicio($pedicure, 'Pedi - Jellyspa', 30000);

        $definicion = app(MenuDeServicios::class)->definicion($this->business);

        $categorias = collect($definicion['nodes']['categorias']['rows'])->pluck('title');
        $this->assertContains('Manicure', $categorias);
        $this->assertContains('Pedicure', $categorias);

        // Y dentro de la categoría, el servicio EXACTO con su precio: la
        // clienta toca "Semi + Rubber" en vez de escribir "semi" y dejar
        // que el modelo elija cuál de los tres.
        $nodoManicure = $definicion['nodes']['cat_'.$manicure->id];
        $opciones = collect($nodoManicure['rows']);
        $this->assertEqualsCanonicalizing(
            ['Semipermanente', 'Semi + Rubber'],
            $opciones->pluck('title')->all(),
        );
        $this->assertStringContainsString('45.000 COP', $opciones->firstWhere('title', 'Semipermanente')['description']);
    }

    public function test_elegido_el_servicio_el_flujo_se_retira_y_habla_el_bot(): void
    {
        /*
         * Los días posibles no son una lista corta, y "el jueves si puedo
         * antes de las 3" es justo lo que un árbol no lee y el modelo sí.
         * Ahí termina lo estructurado y empieza la conversación.
         */
        $categoria = $this->categoria('Manicure');
        $this->servicio($categoria, 'Semipermanente');

        $definicion = app(MenuDeServicios::class)->definicion($this->business);
        $servicioId = collect($definicion['nodes']['cat_'.$categoria->id]['rows'])->first()['id'];
        $nodo = $definicion['nodes'][$servicioId];

        $this->assertSame('message', $nodo['type']);
        $this->assertStringContainsString('¿Para qué día', $nodo['text']);
        // Sin `next`: el flujo termina y lo siguiente que escriba lo
        // atiende el agente.
        $this->assertArrayNotHasKey('next', $nodo);
    }

    public function test_siempre_hay_salida_hacia_una_persona(): void
    {
        $categoria = $this->categoria('Manicure');
        $this->servicio($categoria, 'Semipermanente');

        $definicion = app(MenuDeServicios::class)->definicion($this->business);

        $this->assertContains(
            'Hablar con alguien',
            collect($definicion['nodes']['categorias']['rows'])->pluck('title')->all(),
        );
        $acciones = collect($definicion['nodes']['aviso_humano']['actions'])->pluck('type');
        $this->assertContains('notify_app', $acciones);
    }

    public function test_un_negocio_sin_servicios_no_publica_un_menu_vacio(): void
    {
        Http::fake();

        $this->assertSame([], app(MenuDeServicios::class)->definicion($this->business));
        $this->assertFalse(app(MenuDeServicios::class)->publicar($this->business));
        Http::assertNothingSent();
    }

    public function test_se_publica_en_connect_por_nombre_para_no_duplicarlo(): void
    {
        Http::fake(['comms.test/*' => Http::response(['id' => 'x', 'name' => 'menu_servicios', 'created' => true])]);

        $categoria = $this->categoria('Manicure');
        $this->servicio($categoria, 'Semipermanente');

        $this->assertTrue(app(MenuDeServicios::class)->publicar($this->business));

        Http::assertSent(function ($request) {
            // PUT por nombre: regenerar el menú no crea un flujo nuevo cada vez.
            return $request->method() === 'PUT'
                && str_contains($request->url(), '/v1/flows/menu_servicios')
                && in_array('menu', $request->data()['trigger_keywords'], true);
        });
    }

    public function test_el_comando_en_seco_muestra_el_menu_sin_publicarlo(): void
    {
        Http::fake();
        $categoria = $this->categoria('Manicure');
        $this->servicio($categoria, 'Semipermanente');

        $this->artisan('connect:menu', ['--dry-run' => true])
            ->expectsOutputToContain('Manicure')
            ->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_el_aviso_del_negocio_sale_arriba_del_menu(): void
    {
        /*
         * "Alejandra no estará el jueves" es justo lo que hay que saber
         * ANTES de elegir, no después de haber elegido. El negocio lo
         * escribe una vez y se entera todo el que escriba.
         */
        $categoria = $this->categoria('Manicure');
        $this->servicio($categoria, 'Semipermanente');
        $this->business->update(['public_profile' => [
            'comunicado' => 'Alejandra no estará el jueves 25',
            'comunicado_hasta' => now()->addDays(7)->format('Y-m-d'),
        ]]);

        $definicion = app(MenuDeServicios::class)->definicion($this->business);

        $this->assertStringContainsString(
            'Alejandra no estará el jueves 25',
            $definicion['nodes']['categorias']['text'],
        );
    }

    public function test_un_aviso_vencido_no_se_muestra(): void
    {
        // Un aviso viejo es peor que no tener aviso: a la semana sigue
        // diciendo que alguien no viene y ya volvió.
        $categoria = $this->categoria('Manicure');
        $this->servicio($categoria, 'Semipermanente');
        $this->business->update(['public_profile' => [
            'comunicado' => 'Cerrado por vacaciones',
            // "Ayer" EN LA ZONA DEL NEGOCIO: con now() en UTC, entre las 7 pm
            // y la medianoche de Bogota "ayer UTC" todavia no ha vencido alla
            // y la prueba fallaba solo a esas horas.
            'comunicado_hasta' => now('America/Bogota')->subDay()->format('Y-m-d'),
        ]]);

        $this->assertStringNotContainsString(
            'vacaciones',
            app(MenuDeServicios::class)->definicion($this->business)['nodes']['categorias']['text'],
        );
    }

    public function test_un_aviso_sin_fecha_se_muestra_siempre(): void
    {
        // Sin fecha es un aviso permanente ("parqueadero en la esquina"):
        // válido, y el negocio lo quita cuando quiera.
        $categoria = $this->categoria('Manicure');
        $this->servicio($categoria, 'Semipermanente');
        $this->business->update(['public_profile' => ['comunicado' => 'Recibimos Nequi']]);

        $this->assertStringContainsString(
            'Recibimos Nequi',
            app(MenuDeServicios::class)->definicion($this->business)['nodes']['categorias']['text'],
        );
    }
}
