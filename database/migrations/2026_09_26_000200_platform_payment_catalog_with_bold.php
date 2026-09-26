<?php

use Database\Seeders\PlatformPaymentMethodSeeder;
use Illuminate\Database\Migrations\Migration;

/*
 * El catálogo global de medios de pago, con Bold.
 *
 * En producción nunca se sembró: la pantalla «Medios de pago» salía vacía y
 * no había cómo elegir. El seeder es idempotente (updateOrCreate por `key`),
 * así que correrlo aquí no duplica nada ni toca lo que cada negocio tiene
 * habilitado.
 */
return new class extends Migration
{
    public function up(): void
    {
        (new PlatformPaymentMethodSeeder)->run();
    }

    public function down(): void
    {
        // Nada: borrar el catálogo dejaría sin nombre los cobros que lo usan.
    }
};
