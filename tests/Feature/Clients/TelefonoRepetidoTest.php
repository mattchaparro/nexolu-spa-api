<?php

namespace Tests\Feature\Clients;

use App\Models\Business;
use App\Models\Client;
use App\Services\ClientResolver;
use App\Support\BusinessFeaturePresets;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * El mismo número escrito de otra forma sigue siendo la misma persona.
 *
 * Apareció probando a mano: identificar a una clienta por su teléfono decía
 * "es Gisel M." y la visita terminaba en una ficha NUEVA -- sin su historial,
 * sin sus sellos y sin su encuesta. La ficha vieja tenía "+573002223344" y la
 * búsqueda preguntaba por "573002223344".
 *
 * Importa especialmente el día de la migración: las fichas que vienen del
 * sistema viejo traen el "+", y las que cree el sistema nuevo no. Sin esto,
 * cada clienta migrada se duplicaba la primera vez que volviera.
 */
class TelefonoRepetidoTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create([
            'name' => 'Luxury Nails',
            'slug' => 'luxury-'.uniqid(),
            'vertical' => BusinessFeaturePresets::VERTICAL_SPA_UNAS,
            'timezone' => 'America/Bogota',
            'country_code' => 'CO',
            'currency' => 'COP',
            'subscription_plan' => BusinessFeaturePresets::PLAN_FULL,
            'feature_flags' => BusinessFeaturePresets::full(),
            'is_active' => true,
        ]);
    }

    private function ficha(string $telefono): Client
    {
        return Client::create([
            'business_id' => $this->business->id,
            'name' => 'Gisel',
            'last_name' => 'Muñoz',
            'phone' => $telefono,
            'is_active' => true,
        ]);
    }

    private function resolver(string $telefono): ?Client
    {
        return app(ClientResolver::class)->resolve(
            $this->business->id,
            null,
            'Gisel M.',
            $telefono,
        );
    }

    public function test_reconoce_la_ficha_guardada_con_mas(): void
    {
        // Como la trae el sistema viejo.
        $gisel = $this->ficha('+573002223344');

        $this->assertSame($gisel->id, $this->resolver('573002223344')?->id);
        $this->assertSame(1, Client::withoutGlobalScopes()->count());
    }

    public function test_reconoce_la_ficha_guardada_sin_indicativo(): void
    {
        // Como la escribe quien la anota a mano en el mostrador.
        $gisel = $this->ficha('3002223344');

        $this->assertSame($gisel->id, $this->resolver('573002223344')?->id);
        $this->assertSame(1, Client::withoutGlobalScopes()->count());
    }

    public function test_un_numero_distinto_sigue_siendo_otra_persona(): void
    {
        // La red no puede ser tan ancha que junte a dos clientas distintas.
        $this->ficha('+573002223344');

        $otra = $this->resolver('573009998877');

        $this->assertNotNull($otra);
        $this->assertSame(2, Client::withoutGlobalScopes()->count());
    }
}
