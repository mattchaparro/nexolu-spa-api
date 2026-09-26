<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Un solo «Efectivo» por negocio.
 *
 * Al habilitar Efectivo desde el catálogo (26-sep) se creó uno nuevo, vacío,
 * al lado del importado del sistema anterior -- el que tiene los 1.700
 * cobros --, porque el importado no apuntaba al catálogo. Los reportes por
 * medio de pago lo mostraban dos veces. Ya no pasa (PaymentMethodProvisioner
 * liga el del mismo nombre); esto arregla lo que quedó.
 *
 * Solo se toca el duplicado que NADIE referencia: si el nuevo ya tiene un
 * cobro, un gasto o una liquidación, se deja como está.
 */
return new class extends Migration
{
    public function up(): void
    {
        $columnas = [
            ['appointments', 'payment_method_id'],
            ['appointments', 'deposit_payment_method_id'],
            ['expenses', 'payment_method_id'],
            ['recurring_expenses', 'payment_method_id'],
            ['payroll_settlements', 'payment_method_id'],
            ['product_sales', 'payment_method_id'],
        ];

        $ligados = DB::table('payment_methods')->whereNotNull('platform_payment_method_id')->get();

        foreach ($ligados as $nuevo) {
            $viejo = DB::table('payment_methods')
                ->where('business_id', $nuevo->business_id)
                ->whereNull('platform_payment_method_id')
                ->where('name', $nuevo->name)
                ->where('id', '<', $nuevo->id)
                ->orderBy('id')
                ->first();

            if ($viejo === null) {
                continue;
            }

            foreach ($columnas as [$tabla, $columna]) {
                if (DB::getSchemaBuilder()->hasColumn($tabla, $columna)
                    && DB::table($tabla)->where($columna, $nuevo->id)->exists()) {
                    continue 2;
                }
            }

            DB::transaction(function () use ($nuevo, $viejo) {
                DB::table('payment_methods')->where('id', $nuevo->id)->delete();
                DB::table('payment_methods')->where('id', $viejo->id)->update([
                    'platform_payment_method_id' => $nuevo->platform_payment_method_id,
                    'is_active' => $nuevo->is_active,
                    'sort_order' => $nuevo->sort_order,
                    'counts_as_cash' => $nuevo->counts_as_cash,
                    'updated_at' => now(),
                ]);
            });
        }
    }

    public function down(): void
    {
        // Nada: el duplicado estaba vacío.
    }
};
