<?php

namespace App\Services;

use App\Models\Business;
use App\Models\PaymentMethod;
use App\Models\PlatformPaymentMethod;
use Illuminate\Support\Facades\DB;

/**
 * Traduce el catalogo global a las filas de un negocio.
 *
 * El negocio elige QUE medios usa; no inventa medios ni decide si algo cuenta
 * como efectivo -- eso es propiedad del medio y viene del catalogo. Sin esa
 * separacion, alguien podria marcar el datafono como efectivo y descuadrar
 * todos los cierres sin que nada lo impida.
 */
class PaymentMethodProvisioner
{
    /**
     * Deja al negocio con exactamente los medios indicados.
     *
     * Los que se quitan se DESACTIVAN, no se borran: los cobros historicos
     * los referencian y un cierre de hace tres meses tiene que seguir
     * mostrando con que se pago.
     *
     * @param  list<int>  $platformMethodIds
     */
    public function sync(Business $business, array $platformMethodIds): void
    {
        $catalog = PlatformPaymentMethod::whereIn('id', $platformMethodIds)
            ->where('is_active', true)
            ->get();

        DB::transaction(function () use ($business, $catalog) {
            $keep = [];

            foreach ($catalog as $method) {
                $row = PaymentMethod::withoutGlobalScope('business')
                    ->where('business_id', $business->id)
                    ->where('platform_payment_method_id', $method->id)
                    ->first();

                if ($row === null) {
                    $row = PaymentMethod::create([
                        'business_id' => $business->id,
                        'platform_payment_method_id' => $method->id,
                        'name' => $method->label,
                        'counts_as_cash' => $method->counts_as_cash,
                        'is_active' => true,
                        'sort_order' => $method->sort_order,
                    ]);
                } else {
                    // El nombre y el "cuenta como efectivo" se refrescan desde
                    // el catalogo: si la plataforma corrige un medio, la
                    // correccion llega a todos los negocios.
                    $row->update([
                        'name' => $method->label,
                        'counts_as_cash' => $method->counts_as_cash,
                        'is_active' => true,
                    ]);
                }

                $keep[] = $row->id;
            }

            PaymentMethod::withoutGlobalScope('business')
                ->where('business_id', $business->id)
                ->whereNotIn('id', $keep)
                ->update(['is_active' => false]);
        });
    }

    /**
     * Medios con los que arranca un negocio nuevo.
     *
     * Efectivo siempre: ningun negocio empieza sin poder cobrar en efectivo,
     * y quedarse sin ningun medio habilitado deja la caja inoperante.
     */
    public function provisionDefaults(Business $business): void
    {
        $defaults = PlatformPaymentMethod::where('is_active', true)
            ->whereIn('key', ['efectivo', 'datafono', 'transferencia', 'nequi'])
            ->pluck('id')
            ->all();

        if ($defaults === []) {
            return;
        }

        $this->sync($business, $defaults);
    }
}
