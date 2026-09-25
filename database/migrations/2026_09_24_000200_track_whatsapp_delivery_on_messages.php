<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Lo que pasó con un mensaje DESPUÉS de que Meta lo aceptó.
 *
 * `enviado` quería decir «Meta lo recibió», no «le llegó». Meta acepta y
 * rechaza segundos después, por un aviso aparte: los dos avisos a Marcela
 * figuraban como enviados mientras Meta los había rechazado, y el panel no
 * tenía cómo decirlo. Con el identificador que da Meta al aceptar se puede
 * emparejar ese aviso con su mensaje.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->string('provider_message_id', 128)->nullable()->after('status');
            $table->dateTime('delivered_at')->nullable()->after('sent_at');
            $table->dateTime('read_at')->nullable()->after('delivered_at');

            $table->index('provider_message_id');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropIndex(['provider_message_id']);
            $table->dropColumn(['provider_message_id', 'delivered_at', 'read_at']);
        });
    }
};
