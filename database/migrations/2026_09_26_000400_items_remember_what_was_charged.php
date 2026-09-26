<?php

use App\Support\Money\DiscountAllocator;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Lo que de verdad se cobró por cada servicio, con el descuento ya repartido.
 *
 * `final_price` es el precio de la línea ANTES del descuento de la cuenta
 * (la carta, o lo que quien cobra escribió). Ventas, el Resumen, el Cierre,
 * Mi día y la Nómina sumaban `final_price` como "cobrado": un combo de
 * 100.000 con 15.000 de rebaja entraba a la caja como 85.000 y a los
 * reportes como 100.000. En Luxury eran 94 cobros con descuento.
 *
 * El reparto es el mismo del cobro (DiscountAllocator), así que lo que se
 * rellena para atrás es exactamente lo que se habría guardado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointment_items', function (Blueprint $table) {
            $table->decimal('charged_amount', 12, 2)->nullable()->after('final_price');
        });

        DB::table('appointments')
            ->whereNotNull('checked_out_at')
            ->select('id', 'discount_amount')
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
                    $descuento = min((float) $cita->discount_amount, array_sum($precios));

                    try {
                        $cobrado = DiscountAllocator::allocate($precios, $descuento);
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
        Schema::table('appointment_items', function (Blueprint $table) {
            $table->dropColumn('charged_amount');
        });
    }
};
