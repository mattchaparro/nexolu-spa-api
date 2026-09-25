<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Por qué número del salón escribió la persona la última vez.
 *
 * La ventana de 24 horas de WhatsApp es entre la persona y UN número del
 * negocio, no entre la persona y el negocio. El sistema la llevaba por
 * negocio, y el día que Luxury cambió de número todas las ventanas que creía
 * abiertas eran falsas: Marcela le había escrito al número de la mañana, el
 * aviso de su cita salió como texto desde el número de la tarde -- con el que
 * ella nunca habló -- y Meta lo rechazó (131047).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_conversations', function (Blueprint $table) {
            $table->string('last_inbound_phone_number_id', 64)->nullable()->after('last_inbound_at');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_conversations', function (Blueprint $table) {
            $table->dropColumn('last_inbound_phone_number_id');
        });
    }
};
