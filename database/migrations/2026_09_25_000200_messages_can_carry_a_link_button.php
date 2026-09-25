<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Un mensaje puede llevar un botón que abre un enlace.
 *
 * La confirmación de la cita lleva el Instagram del salón. El bot ya lo
 * mandaba como botón «Seguir en Instagram», pero la que sale por la bandeja
 * de salida --la de la web y la del panel-- solo sabía mandar texto, así que
 * llegaba como un enlace largo pegado al final. Con estas dos columnas el
 * mensaje recuerda su botón y lo manda como tal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->string('link_url', 500)->nullable()->after('template_params');
            $table->string('link_title', 40)->nullable()->after('link_url');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn(['link_url', 'link_title']);
        });
    }
};
