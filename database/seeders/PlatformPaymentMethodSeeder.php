<?php

namespace Database\Seeders;

use App\Models\PlatformPaymentMethod;
use Illuminate\Database\Seeder;

/**
 * Catalogo global de medios de pago.
 *
 * Idempotente: correrlo de nuevo actualiza etiquetas sin duplicar ni pisar
 * lo que cada negocio tenga habilitado.
 */
class PlatformPaymentMethodSeeder extends Seeder
{
    public function run(): void
    {
        // counts_as_cash es lo unico que decide si algo entra al cajon, y por
        // eso vive aca y no en cada negocio.
        $methods = [
            ['efectivo', 'Efectivo', true],
            ['datafono', 'Datáfono', false],
            ['transferencia', 'Transferencia', false],
            ['nequi', 'Nequi', false],
            ['daviplata', 'Daviplata', false],
            ['bancolombia', 'Bancolombia', false],
            ['bono', 'Bono regalo', false],
        ];

        foreach ($methods as $i => [$key, $label, $cash]) {
            PlatformPaymentMethod::updateOrCreate(
                ['key' => $key],
                ['label' => $label, 'counts_as_cash' => $cash, 'is_active' => true, 'sort_order' => $i],
            );
        }
    }
}
