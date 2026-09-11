<?php

namespace App\Services\Products;

use App\Models\Product;
use App\Models\ProductSale;
use App\Models\ProductStockMovement;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Vender producto y mover inventario.
 *
 * TODO PASA POR ACA. El stock de `products` es un espejo: la verdad son los
 * movimientos, y el espejo solo se toca desde este servicio. En el sistema
 * viejo el stock era una columna que se editaba por un lado y los movimientos
 * se anotaban por otro, y terminaban diciendo cosas distintas.
 */
class InventarioService
{
    /**
     * Vender. Descuenta stock y deja el movimiento, en una sola transaccion.
     *
     * @param  array<string, mixed>  $datos
     */
    public function vender(Product $producto, int $cantidad, array $datos, ?User $quien = null): ProductSale
    {
        if ($cantidad < 1) {
            throw new \DomainException('La cantidad tiene que ser al menos 1.');
        }

        return DB::transaction(function () use ($producto, $cantidad, $datos, $quien) {
            /*
             * Se relee con bloqueo. Dos personas cobrando a la vez la ultima
             * crema leerian el mismo "queda 1" y las dos venderian: el stock
             * quedaria en -1 y una clienta se iria sin producto.
             */
            $fresco = Product::withoutGlobalScope('business')->lockForUpdate()->findOrFail($producto->id);

            if ($fresco->stock < $cantidad) {
                throw new \DomainException(
                    $fresco->stock === 0
                        ? "No queda {$fresco->name}."
                        : "Solo quedan {$fresco->stock} de {$fresco->name}.",
                );
            }

            /*
             * Precio y costo CONGELADOS. Si no se manda un precio, se toma el
             * de la carta de HOY -- pero queda escrito en la venta, no leido
             * despues.
             */
            $precio = isset($datos['unit_price']) ? (float) $datos['unit_price'] : (float) $fresco->price;

            $venta = ProductSale::create([
                'business_id' => $fresco->business_id,
                'product_id' => $fresco->id,
                'appointment_id' => $datos['appointment_id'] ?? null,
                'client_id' => $datos['client_id'] ?? null,
                /*
                 * La sede donde se vendio. Si no la mandan, la principal del
                 * negocio: una venta sin sede se cae de cualquier reporte que
                 * filtre por sede, y desaparecer del cierre es peor que
                 * atribuirla al local que casi seguro la hizo.
                 */
                'location_id' => $datos['location_id'] ?? $fresco->business?->primaryLocation()?->id,
                'quantity' => $cantidad,
                'unit_price' => $precio,
                'unit_cost' => $fresco->cost,
                'total' => round($precio * $cantidad, 2),
                'payment_method_id' => $datos['payment_method_id'] ?? null,
                'sold_by_user_id' => $quien?->id,
                /*
                 * En UTC, como todo lo que se guarda. Los reportes comparan
                 * contra `checked_out_at`, que tambien esta en UTC: si esta
                 * quedara en hora local, la venta de las 6 de la tarde caeria
                 * en el cierre del dia siguiente.
                 */
                'sold_at' => isset($datos['sold_at'])
                    ? CarbonImmutable::parse($datos['sold_at'])->utc()
                    : CarbonImmutable::now()->utc(),
            ]);

            $this->mover($fresco, ProductStockMovement::KIND_VENTA, -$cantidad, $quien, null, $venta->id);

            return $venta;
        });
    }

    /**
     * Deshacer una venta: devuelve el producto al inventario.
     *
     * Se usa al anular un cobro. No se borra el movimiento anterior: se anota
     * uno nuevo en sentido contrario, porque borrar historia deja un
     * inventario que cuadra pero no se puede explicar.
     */
    public function anular(ProductSale $venta, ?User $quien = null): void
    {
        DB::transaction(function () use ($venta, $quien) {
            $producto = Product::withoutGlobalScope('business')->lockForUpdate()->find($venta->product_id);

            if ($producto !== null) {
                $this->mover(
                    $producto,
                    ProductStockMovement::KIND_AJUSTE,
                    $venta->quantity,
                    $quien,
                    'Venta anulada',
                );
            }

            $venta->delete();
        });
    }

    /** Entrada de mercancia, o un ajuste por conteo o rotura. */
    public function ajustar(Product $producto, string $tipo, int $cantidad, ?User $quien = null, ?string $nota = null): ProductStockMovement
    {
        return DB::transaction(function () use ($producto, $tipo, $cantidad, $quien, $nota) {
            $fresco = Product::withoutGlobalScope('business')->lockForUpdate()->findOrFail($producto->id);

            return $this->mover($fresco, $tipo, $cantidad, $quien, $nota);
        });
    }

    /**
     * Anota el movimiento y deja el espejo al dia.
     *
     * El saldo se RECALCULA de los movimientos en vez de sumarle al anterior:
     * asi un espejo que quedo torcido por cualquier motivo se endereza solo en
     * el siguiente movimiento.
     */
    private function mover(Product $producto, string $tipo, int $cantidad, ?User $quien, ?string $nota = null, ?int $ventaId = null): ProductStockMovement
    {
        $movimiento = ProductStockMovement::create([
            'business_id' => $producto->business_id,
            'product_id' => $producto->id,
            'kind' => $tipo,
            'quantity' => $cantidad,
            'product_sale_id' => $ventaId,
            'created_by_user_id' => $quien?->id,
            'note' => $nota,
        ]);

        $producto->forceFill([
            'stock' => (int) ProductStockMovement::withoutGlobalScope('business')
                ->where('product_id', $producto->id)
                ->sum('quantity'),
        ])->save();

        return $movimiento;
    }
}
