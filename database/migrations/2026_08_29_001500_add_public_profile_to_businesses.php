<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lo que el negocio dice de si mismo en su pagina publica.
 *
 * Una sola columna JSON y no una tabla de bloques. El constructor de landings
 * se esta haciendo aparte (en el POS nuevo) y se va a reutilizar aca; inventar
 * ahora un esquema de bloques seria adivinar el suyo y despues migrarlo.
 *
 * Mientras tanto esto no es un placeholder vacio: es lo minimo para que la
 * pagina sea una pagina y no un formulario suelto -- una frase, un parrafo, y
 * como escribirle al negocio. Cuando llegue el constructor, sus bloques se
 * suman y esto queda como el modo simple para quien no quiere armar nada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->json('public_profile')->nullable()->after('cover_path');
        });
    }

    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->dropColumn('public_profile');
        });
    }
};
