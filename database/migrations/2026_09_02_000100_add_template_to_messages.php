<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Como sale un mensaje que INICIA el negocio.
 *
 * WhatsApp solo entrega texto libre dentro de las 24 horas siguientes a que
 * la clienta escribio. Un recordatorio de la cita de manana, un aviso de que
 * se libero un cupo o una encuesta son justo lo contrario: los inicia el
 * negocio hacia alguien que no ha escrito. Meta los RECHAZA como texto y
 * exige una plantilla aprobada.
 *
 * Y por eso son dos cosas distintas, no una:
 *
 *  - `body` es lo que un humano manda desde su propio WhatsApp en modo
 *    manual, y lo que se lee en la bandeja de salida. Texto plano, ya armado.
 *  - `template_name` + `template_params` es como se manda SOLO. Meta no
 *    quiere el texto: quiere el nombre de la plantilla y sus variables.
 *
 * Guardar solo el texto renderizado dejaria el envio automatico imposible
 * -- de "Hola Carolina, tu cita del jueves..." no se sacan de vuelta las
 * variables sin adivinar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->string('template_name', 128)->nullable()->after('body');
            $table->string('template_language', 12)->nullable()->after('template_name');

            /** @var list<string> Las variables, en el orden de la plantilla. */
            $table->json('template_params')->nullable()->after('template_language');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn(['template_name', 'template_language', 'template_params']);
        });
    }
};
