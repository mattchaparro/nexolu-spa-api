<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('businesses', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('vertical')->default('spa_unas'); // spa_unas | barberia | estetica
            $table->string('timezone')->default('America/Bogota');
            $table->string('country_code', 2)->default('CO');
            $table->string('currency', 3)->default('COP');
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('address')->nullable();
            $table->string('logo_path')->nullable();

            // Resuelto siempre via Business::resolvedFeatureFlags(); el front
            // lee el resultado, nunca reimplementa la resolucion.
            $table->json('feature_flags')->nullable();
            $table->string('subscription_plan')->nullable();

            // Overrides de config/spa.php por negocio. Ningun Service lee
            // config('spa.defaults') directo: pasa siempre por aca.
            $table->json('scheduling_settings')->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('businesses');
    }
};
