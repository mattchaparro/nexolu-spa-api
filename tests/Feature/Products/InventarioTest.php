<?php

namespace Tests\Feature\Products;

use App\Models\Business;
use App\Models\Product;
use App\Models\ProductStockMovement;
use App\Models\User;
use App\Support\PermissionCatalog;
use App\Services\Products\InventarioService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Scheduling\SchedulingScenario;
use Tests\TestCase;

/**
 * Vender producto sin que el inventario se descuadre.
 *
 * El sistema viejo tenia el stock en una columna que se editaba por un lado y
 * los movimientos por otro, y terminaban diciendo cosas distintas. Aca el
 * saldo SALE de los movimientos, asi que no puede contradecirlos.
 */
class InventarioTest extends TestCase
{
    use RefreshDatabase, SchedulingScenario;

    private Business $business;

    private Product $crema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = $this->makeBusiness();

        $this->crema = Product::create([
            'business_id' => $this->business->id,
            'name' => 'Crema Hidratante - Coco',
            'price' => 20000,
            'cost' => 11000,
            'stock' => 0,
            'is_active' => true,
        ]);
    }

    private function inventario(): InventarioService
    {
        return $this->app->make(InventarioService::class);
    }

    public function test_una_entrada_sube_el_saldo(): void
    {
        $this->inventario()->ajustar($this->crema, ProductStockMovement::KIND_ENTRADA, 12);

        $this->assertSame(12, $this->crema->fresh()->stock);
    }

    public function test_vender_descuenta_y_congela_el_precio(): void
    {
        $this->inventario()->ajustar($this->crema, ProductStockMovement::KIND_ENTRADA, 12);

        $venta = $this->inventario()->vender($this->crema, 2, []);

        $this->assertSame(10, $this->crema->fresh()->stock);
        $this->assertEqualsWithDelta(40000, (float) $venta->total, 0.01);

        // Subir el precio despues NO cambia lo que se cobro.
        $this->crema->update(['price' => 25000]);

        $this->assertEqualsWithDelta(20000, (float) $venta->fresh()->unit_price, 0.01);
        $this->assertEqualsWithDelta(40000, (float) $venta->fresh()->total, 0.01);
    }

    public function test_no_se_vende_lo_que_no_hay(): void
    {
        $this->inventario()->ajustar($this->crema, ProductStockMovement::KIND_ENTRADA, 1);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Solo quedan 1');

        $this->inventario()->vender($this->crema, 2, []);
    }

    public function test_el_saldo_sale_de_los_movimientos_y_no_de_la_columna(): void
    {
        /*
         * Si alguien deja el espejo torcido -- una escritura suelta, una
         * migracion a medias -- el siguiente movimiento lo endereza. En el
         * sistema viejo ese desfase se quedaba para siempre.
         */
        $this->inventario()->ajustar($this->crema, ProductStockMovement::KIND_ENTRADA, 10);

        $this->crema->forceFill(['stock' => 999])->save();

        $this->inventario()->ajustar($this->crema, ProductStockMovement::KIND_AJUSTE, -1, nota: 'Se rompió');

        $this->assertSame(9, $this->crema->fresh()->stock);
    }

    public function test_anular_una_venta_devuelve_el_producto(): void
    {
        $this->inventario()->ajustar($this->crema, ProductStockMovement::KIND_ENTRADA, 5);
        $venta = $this->inventario()->vender($this->crema, 2, []);

        $this->inventario()->anular($venta);

        $this->assertSame(5, $this->crema->fresh()->stock);

        // Y la historia se conserva: no se borra el movimiento, se compensa.
        $this->assertSame(
            3,
            ProductStockMovement::withoutGlobalScope('business')->where('product_id', $this->crema->id)->count(),
        );
    }

    public function test_la_venta_de_producto_entra_en_el_cierre_del_dia(): void
    {
        /*
         * ESTO es lo que faltaba en el sistema viejo. Alli se vendio 1.070.000
         * en producto en año y medio y no aparecia en ningun reporte: un
         * cierre que solo cuenta servicios dice que entro menos plata de la
         * que entro.
         *
         * Va APARTE de los totales de servicio, no sumado adentro: quien mira
         * el cierre necesita saber cuanto entro por cada cosa, que son dos
         * negocios con margenes distintos.
         */
        PermissionCatalog::sync();

        $admin = User::create([
            'business_id' => $this->business->id, 'name' => 'Ana',
            'email' => 'ana.productos@prueba.test', 'password' => Hash::make('password123'),
            'is_active' => true,
        ]);
        PermissionCatalog::applyRole($admin, PermissionCatalog::ROLE_ADMIN);
        Sanctum::actingAs($admin->fresh());

        $this->inventario()->ajustar($this->crema, ProductStockMovement::KIND_ENTRADA, 5);
        $this->inventario()->vender($this->crema, 2, []);

        $hoy = now($this->business->businessTimezone())->toDateString();

        $this->getJson("/api/v1/daily-summary?date={$hoy}")
            ->assertOk()
            ->assertJsonPath('products.sales', 1)
            ->assertJsonPath('products.units', 2)
            ->assertJsonPath('products.charged', 40000);
    }

    public function test_avisa_cuando_queda_poco(): void
    {
        $this->crema->update(['low_stock_at' => 2]);
        $this->inventario()->ajustar($this->crema, ProductStockMovement::KIND_ENTRADA, 5);

        $this->assertFalse($this->crema->fresh()->isLow());

        $this->inventario()->vender($this->crema, 3, []);

        $this->assertTrue($this->crema->fresh()->isLow());
    }
}
