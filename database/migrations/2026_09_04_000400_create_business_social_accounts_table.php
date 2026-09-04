<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La cuenta de Instagram de CADA negocio.
 *
 * POR NEGOCIO Y NO EN `.env`, por las mismas razones que ya estan escritas en
 * docs/whatsapp-numero-por-negocio.md y que aca aplican igual:
 *
 * - La cuenta es SUYA. La clienta sigue al spa, no a Nexolu, y publicar desde
 *   una cuenta de la plataforma no significaria nada para nadie.
 * - El limite de publicaciones lo pone Meta POR CUENTA. Compartir una cuenta
 *   seria que un negocio que publica de mas deje sin publicar a los demas.
 * - La responsabilidad del contenido es del negocio. Es su vitrina.
 *
 * Un token en configuracion global habria construido esto para UN spa -- el
 * nuestro -- y el dia que entre el segundo hay que rehacerlo.
 *
 * EL TOKEN VA CIFRADO. Es la llave para publicar como ese negocio durante
 * sesenta dias: un `select *` de soporte, un backup que alguien copia a su
 * portatil, o un volcado para depurar no pueden dejarla legible. Lo hace el
 * cast `encrypted` de Eloquent con la APP_KEY que ya existe.
 *
 * `provider` existe desde el primer dia aunque hoy solo haya uno. Agregar
 * TikTok o Facebook despues seria, si no, o una tabla nueva casi identica o
 * una columna que hay que retro-llenar en produccion.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_social_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();

            $table->string('provider', 24)->default('instagram');

            /*
             * El id de la cuenta segun Meta, que NO es el @usuario: el arroba
             * se puede cambiar desde el telefono y el id no. Guardar el arroba
             * como identificador seria perder la cuenta el dia que alguien la
             * renombre.
             */
            $table->string('external_id', 64);

            // Solo para mostrar en pantalla "conectado como @luxurynails".
            $table->string('username', 191)->nullable();

            $table->text('access_token');

            /*
             * Cuando vence. Meta da tokens de sesenta dias, y el sintoma de
             * uno vencido es que las publicaciones dejan de salir EN SILENCIO
             * -- nadie mira una cuenta que "funcionaba". Con la fecha aca, el
             * panel puede avisar antes y el comando de refresco sabe a cual
             * renovarle.
             */
            $table->dateTime('token_expires_at')->nullable();

            $table->dateTime('last_published_at')->nullable();

            /*
             * Se puede apagar sin desconectar. Un negocio que quiere dejar de
             * publicar por un mes no deberia tener que volver a pasar por Meta
             * para volver.
             */
            $table->boolean('is_active')->default(true);

            // Quien la conecto, para poder preguntarle.
            $table->foreignId('connected_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // Una cuenta por red y por negocio: dos filas activas de Instagram
            // para el mismo spa es una publicacion que sale dos veces.
            $table->unique(['business_id', 'provider']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_social_accounts');
    }
};
