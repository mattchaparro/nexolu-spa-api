<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Cuándo nos dio (o confirmó) la clienta su nombre.
 *
 * Muchas fichas vienen de ManyChat o del perfil de WhatsApp con un apodo
 * que parece nombre («Claus»), y a esas el bot nunca les preguntaba. Ahora
 * se le pregunta a todas UNA vez; con esta fecha puesta no se le vuelve a
 * preguntar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->timestamp('name_confirmed_at')->nullable()->after('last_name');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('name_confirmed_at');
        });
    }
};
