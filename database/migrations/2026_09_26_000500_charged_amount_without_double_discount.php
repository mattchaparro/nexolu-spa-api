<?php

use App\Support\Money\DiscountAllocator;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Lo cobrado por línea, otra vez, sin restar dos veces el descuento.
 *
 * El relleno de 000400 repartía el descuento de la cita sobre `final_price`.
 * Pero en lo importado del sistema anterior `final_price` YA venía con el
 * descuento restado (60.000 − 6.000 = 54.000 en la línea), así que quedaba
 * en 48.000: 645.250 de menos entre los 94 cobros con descuento. En lo que
 * se cobró en el sistema nuevo, `final_price` es antes del descuento.
 *
 * La regla que sirve para los dos: se reparte solo lo que FALTA restar,
 * suma de las líneas − total de la cita. En lo importado eso es 0; en lo
 * nuevo es el descuento entero.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('appointments')
            ->whereNotNull('checked_out_at')
            ->select('id', 'total')
            ->orderBy('id')
            ->chunk(500, function ($citas) {
                foreach ($citas as $cita) {
                    $items = DB::table('appointment_items')
                        ->where('appointment_id', $cita->id)
                        ->orderBy('sort_order')
                        ->get(['id', 'final_price', 'price']);

                    if ($items->isEmpty()) {
                        continue;
                    }

                    $precios = $items->map(fn ($i) => (float) ($i->final_price ?? $i->price ?? 0))->all();
                    $falta = $cita->total === null ? 0.0 : max(0.0, array_sum($precios) - (float) $cita->total);

                    try {
                        $cobrado = DiscountAllocator::allocate($precios, min($falta, array_sum($precios)));
                    } catch (\InvalidArgumentException) {
                        $cobrado = $precios;
                    }

                    foreach ($items->values() as $i => $item) {
                        DB::table('appointment_items')->where('id', $item->id)
                            ->update(['charged_amount' => $cobrado[$i]]);
                    }
                }
            });
    }

    public function down(): void
    {
        // Nada: 000400 deja la columna; esto solo corrige sus valores.
    }
};
