<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // El catalogo global va primero: los negocios eligen de el.
        $this->call(PlatformPaymentMethodSeeder::class);
        $this->call(DevelopmentSeeder::class);
    }
}
