<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Product;
use App\Models\ProductSale;
use App\Models\ProductStockMovement;
use App\Services\Products\InventarioService;
use App\Support\ImageStorage;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * El catalogo de producto y su inventario.
 *
 * Vender producto es la otra mitad del ingreso de un salon -- cremas,
 * esmaltes, velas. En el sistema viejo existia pero sus ventas NO entraban en
 * los reportes: se vendio 1.070.000 en año y medio y no aparecia por ningun
 * lado.
 */
class ProductController
{
    public function __construct(private readonly InventarioService $inventario) {}

    public function index(Request $request): JsonResponse
    {
        $productos = Product::query()
            ->when($request->boolean('only_active', true), fn ($q) => $q->where('is_active', true))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $productos->map(fn (Product $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'sku' => $p->sku,
                'description' => $p->description,
                'image_url' => ImageStorage::url($p->image_path),
                'price' => (float) $p->price,
                'cost' => $p->cost === null ? null : (float) $p->cost,
                'stock' => $p->stock,
                'low_stock_at' => $p->low_stock_at,
                'is_low' => $p->isLow(),
                'is_active' => (bool) $p->is_active,
            ])->values(),

            // Lo que hay que reponer, contado aparte: es lo que alguien viene
            // a mirar a esta pantalla.
            'low_stock' => $productos->filter(fn (Product $p) => $p->isLow())->count(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $inicial = (int) ($request->input('initial_stock') ?? 0);

        $producto = Product::create(
            collect($data)->except('image')->all()
            + ['business_id' => $request->user()->business_id, 'stock' => 0]
        );

        if ($request->hasFile('image')) {
            $producto->update(['image_path' => ImageStorage::store($request->file('image'), 'products')]);
        }

        if ($inicial > 0) {
            $this->inventario->ajustar(
                $producto,
                ProductStockMovement::KIND_ENTRADA,
                $inicial,
                $request->user(),
                'Existencia inicial',
            );
        }

        return response()->json(['id' => $producto->id], 201);
    }

    public function update(Request $request, Product $product): JsonResponse
    {
        /*
         * El stock NO se edita desde aca: se mueve. Un campo de stock editable
         * es como el sistema viejo terminaba con un saldo que no cuadraba con
         * sus movimientos y sin forma de saber cual de los dos mentia.
         */
        $product->update(collect($this->validated($request))->except('image')->all());

        if ($request->hasFile('image')) {
            $product->update(['image_path' => ImageStorage::store($request->file('image'), 'products')]);
        }

        return response()->json(['id' => $product->id]);
    }

    public function destroy(Product $product): JsonResponse
    {
        // Se apaga, no se borra: sus ventas viejas siguen siendo ingresos.
        $product->update(['is_active' => false]);

        return response()->json(['deactivated' => true]);
    }

    /** Entrada de mercancia, conteo o rotura. */
    public function adjust(Request $request, Product $product): JsonResponse
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in([
                ProductStockMovement::KIND_ENTRADA,
                ProductStockMovement::KIND_AJUSTE,
            ])],
            // Con signo: un ajuste puede restar.
            'quantity' => ['required', 'integer', 'not_in:0', 'min:-9999', 'max:9999'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $this->inventario->ajustar(
            $product,
            $data['kind'],
            (int) $data['quantity'],
            $request->user(),
            $data['note'] ?? null,
        );

        return response()->json(['stock' => $product->fresh()->stock]);
    }

    /** Vender. Desde el cobro de una cita, o suelto. */
    public function sell(Request $request, Product $product): JsonResponse
    {
        $data = $request->validate([
            'quantity' => ['required', 'integer', 'min:1', 'max:999'],
            'appointment_id' => ['nullable', 'integer'],
            'client_id' => ['nullable', 'integer'],
            'payment_method_id' => ['nullable', 'integer'],
            // Se puede cobrar distinto del precio de carta, igual que un
            // servicio: lo que queda escrito es lo que de verdad se cobro.
            'unit_price' => ['nullable', 'numeric', 'min:0'],
        ]);

        try {
            $venta = $this->inventario->vender(
                $product,
                (int) $data['quantity'],
                $data,
                $request->user(),
            );
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'id' => $venta->id,
            'total' => (float) $venta->total,
            'stock' => $product->fresh()->stock,
        ], 201);
    }

    /** Las ventas de producto de un rango. */
    public function sales(Request $request): JsonResponse
    {
        $tz = $request->user()->business->businessTimezone();

        $desde = CarbonImmutable::parse((string) $request->query('from', 'today'), $tz)->startOfDay();
        $hasta = CarbonImmutable::parse((string) $request->query('to', $desde->toDateString()), $tz)->endOfDay();

        $ventas = ProductSale::with(['product', 'paymentMethod'])
            ->whereBetween('sold_at', [$desde->utc(), $hasta->utc()])
            ->orderByDesc('sold_at')
            ->get();

        return response()->json([
            'data' => $ventas->map(fn (ProductSale $v) => [
                'id' => $v->id,
                'product' => $v->product?->name,
                'quantity' => $v->quantity,
                'total' => (float) $v->total,
                'margin' => $v->margin(),
                'payment_method' => $v->paymentMethod?->name,
                'sold_at' => $v->sold_at?->setTimezone($tz)->toIso8601String(),
            ])->values(),
            'total' => round((float) $ventas->sum('total'), 2),
            'units' => (int) $ventas->sum('quantity'),
        ]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'sku' => ['nullable', 'string', 'max:60'],
            'description' => ['nullable', 'string', 'max:2000'],
            'price' => ['required', 'numeric', 'min:0'],
            'cost' => ['nullable', 'numeric', 'min:0'],
            'low_stock_at' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'is_active' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'image' => ImageStorage::rules(),
        ]);
    }
}
