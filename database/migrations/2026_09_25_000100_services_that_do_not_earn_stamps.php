<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Hay servicios que no suman sello en la tarjeta.
 *
 * Un retiro no es una visita que el salón quiera premiar: es el paso previo
 * al servicio de verdad. Contarlo le daba sello a quien solo vino a
 * quitarse el esmalte, y a quien se hacía retiro + servicio le daba dos.
 * Lo decide cada negocio servicio por servicio; por defecto todos suman,
 * que es como funcionaba hasta hoy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->boolean('earns_stamps')->default(true)->after('is_bookable_online');
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn('earns_stamps');
        });
    }
};
