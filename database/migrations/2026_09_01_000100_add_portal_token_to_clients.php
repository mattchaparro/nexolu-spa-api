<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El enlace personal de un cliente: "mis citas".
 *
 * POR QUE UN TOKEN Y NO EL TELEFONO.
 *
 * Lo natural seria una pantalla que pida el numero y muestre las citas de ese
 * numero. Es exactamente la fuga que hace inaceptable el
 * `/api/external/*` de Blue Souls: cualquiera prueba numeros y va leyendo
 * nombres, servicios y horarios de clientas ajenas. Un telefono no es un
 * secreto -- esta en la vitrina del local, en Instagram, en un grupo de
 * WhatsApp -- asi que no puede ser lo que autoriza a ver datos.
 *
 * El token si lo es: 48 caracteres aleatorios que solo viajan en el mensaje
 * que el negocio le manda A ESA persona, al numero que ya tenia registrado.
 * Quien lo tiene, lo tiene porque le llego.
 *
 * Es POR CLIENTE y no por cita a proposito: el enlace sirve para "mis citas",
 * no para una sola, y no hay que mandar uno nuevo cada vez. Se puede rotar
 * si hace falta -- ver `ClientPortalService::rotate()`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            /*
             * Nulo hasta que se necesita.
             *
             * No se le genera a todo el mundo por adelantado: un token que
             * nunca se mando es superficie de ataque sin ninguna ventaja. Se
             * crea la primera vez que hay que armarle el enlace.
             *
             * UNICO GLOBAL, no por negocio: el token entra por la URL antes de
             * saber de que negocio es, asi que tiene que identificar por si
             * solo. Con 48 caracteres aleatorios la colision no es un riesgo
             * practico, pero el indice la vuelve imposible en vez de
             * improbable.
             */
            $table->string('portal_token', 64)->nullable()->unique()->after('phone');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropUnique(['portal_token']);
            $table->dropColumn('portal_token');
        });
    }
};
