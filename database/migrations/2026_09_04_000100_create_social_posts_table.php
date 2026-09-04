<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Las publicaciones del negocio en sus redes.
 *
 * POR QUE ESTO VIVE EN LA API DE AGENDA Y NO EN UNA HERRAMIENTA APARTE: hay
 * cien programadores de publicaciones y todos empiezan con una hoja en
 * blanco. Este no. Este sabe que la clienta de ayer salio feliz con un
 * degradado, que el martes a las 3pm no hay nadie agendado y que el spa no
 * vende un pedicure spa hace tres semanas. La hoja en blanco es el problema
 * -- no la falta de un calendario -- y el dato para llenarla ya esta en esta
 * base.
 *
 * EL ESTADO ES LA COLUMNA VERTEBRAL. Una publicacion nace como propuesta
 * (`draft`), alguien la aprueba con fecha (`scheduled`), llega su hora y
 * queda lista (`ready`), y termina publicada (`published`). Nada salta de
 * propuesta a publicada: entre el modelo que escribio el texto y la vitrina
 * del negocio hay SIEMPRE una persona. Es deliberado -- ver docs/publicaciones.md.
 *
 * LO QUE NO ES: una cola de mensajes. Un mensaje se manda una vez y se acabo;
 * una publicacion se reescribe cuatro veces antes de gustar. Por eso el copy
 * vive en la fila y se edita en el lugar, en vez de crearse una fila nueva
 * por cada intento.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();

            // Nulo = habla del negocio entero. Con varias sedes importa: la
            // promo de un hueco de la sede de Cedritos no la publica quien
            // solo maneja Chapinero.
            $table->foreignId('location_id')->nullable()->constrained()->nullOnDelete();

            $table->string('status', 16)->default('draft');

            /*
             * De donde salio la idea. `auto` la propuso el planificador
             * leyendo la agenda; `manual` la escribio una persona.
             *
             * Se guarda porque cambia como se trata: una propuesta
             * automatica que nadie aprobo en una semana se descarta sola
             * (ver PostPlanner), y una que escribio la duena no se toca nunca.
             */
            $table->string('source', 16)->default('manual');

            /*
             * El ANGULO: por que existe esta publicacion. No es decorativo --
             * es lo primero que lee el modelo al escribir el texto, y es lo
             * que hace que un hueco de agenda suene distinto a una foto de un
             * trabajo. Sin esto todas las publicaciones salen iguales.
             */
            $table->string('angle', 24)->default('libre');

            /*
             * La huella de la idea, para el planificador: "el hueco del 8 de
             * septiembre en la sede 1", "la foto 412".
             *
             * Unica por negocio, y esa restriccion ES el mecanismo de
             * idempotencia. El planificador corre a diario sobre una ventana
             * abierta -- los proximos N dias -- asi que sin esto cada corrida
             * volveria a proponer el mismo hueco. Una bandera "ya propuesto"
             * se desincroniza; un indice unico no. Misma leccion que los
             * recordatorios.
             *
             * Nula en lo que escribe una persona: dos publicaciones a mano
             * sobre lo mismo son una decision, no un duplicado.
             */
            $table->string('idea_key', 120)->nullable();

            /*
             * De que habla. Los tres son opcionales y no se excluyen: una
             * publicacion puede mostrar la foto de un trabajo (`client_photo_id`)
             * Y decir que servicio es (`service_id`).
             */
            $table->foreignId('service_id')->nullable()->constrained()->nullOnDelete();

            /*
             * El dia del que HABLA la publicacion, que casi nunca es el dia en
             * que se publica: "el jueves tenemos cupo" se publica el lunes.
             *
             * Sin esta columna el dato solo viviria dentro de `idea_key`, y el
             * redactor tendria que partir una cadena para saber de que jueves
             * habla. Un dato que alguien va a leer no se guarda como parte de
             * un identificador.
             */
            $table->date('subject_date')->nullable();

            /*
             * La foto sale de la ficha de la clienta. NUNCA se llega aca sin
             * pasar por el consentimiento de mas abajo: la referencia es la
             * conveniencia, el permiso es lo que autoriza.
             *
             * `nullOnDelete` y no cascada: si la foto se borra de la ficha, la
             * publicacion sobrevive sin imagen en vez de desaparecer del
             * calendario y dejar un hueco que nadie entiende.
             */
            $table->foreignId('client_photo_id')->nullable()->constrained()->nullOnDelete();

            // Imagen subida directo al modulo: la vitrina, el equipo, un
            // flyer. No es de nadie, no necesita consentimiento.
            $table->string('image_path')->nullable();

            /*
             * El texto y las etiquetas, separados. Van aparte porque se editan
             * aparte: el negocio reescribe el texto y deja los hashtags, o al
             * reves. Pegados en un solo campo cada arreglo obliga a repasar
             * los dos.
             */
            $table->text('caption')->nullable();
            $table->json('hashtags')->nullable();

            // Cuando el modelo escribio el texto. Nula si lo escribio una
            // persona -- y eso es justo lo que distingue una de otra en
            // pantalla.
            $table->dateTime('composed_at')->nullable();

            /*
             * La hora a la que toca. Nula mientras sea una propuesta sin
             * aprobar: una idea no tiene fecha, un plan si.
             */
            $table->dateTime('scheduled_for')->nullable();

            $table->dateTime('published_at')->nullable();

            /*
             * El identificador que devuelve la red cuando el dia que haya un
             * canal automatico. Hoy nadie lo escribe y esta bien: la columna
             * existe para que conectar ese canal no sea una migracion sobre
             * una tabla con datos de todos los negocios.
             */
            $table->string('external_ref', 191)->nullable();

            /*
             * Por que no salio. Hoy lo llena el reloj cuando a una programada
             * le llega la hora sin material -- alguien borro la foto de la
             * ficha entre que se programo y que toco -- y devolverla a
             * propuesta sin decir por que la deja pareciendo un misterio.
             * Cuando exista un canal automatico llevaria ademas el error del
             * proveedor.
             */
            $table->text('error')->nullable();

            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            // Quien la aprobo. Es el registro de que una persona la miro
            // antes de que saliera, que es toda la garantia que este modulo
            // ofrece contra publicar una tonteria escrita por un modelo.
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // El calendario: lo que viene, por negocio y por estado.
            $table->index(['business_id', 'status', 'scheduled_for']);

            $table->unique(['business_id', 'idea_key']);
        });

        Schema::table('client_photos', function (Blueprint $table) {
            /*
             * EL PERMISO PARA PUBLICAR. Sin esta marca la foto no sale del
             * negocio, y no hay forma de saltarse el paso: el modulo filtra
             * por esta columna, no por una casilla del formulario.
             *
             * La foto de las unas de una clienta es SUYA. Que este en la
             * ficha porque la profesional la tomo para acordarse del color no
             * la convierte en material de marketing -- ese es exactamente el
             * error que un modulo de publicaciones invita a cometer, porque
             * las fotos ya estan ahi y se ven muy bien. Se pide, se anota
             * quien lo pidio y cuando, y se puede retirar poniendo la fecha
             * en nulo.
             */
            $table->dateTime('marketing_consent_at')->nullable()->after('caption');
            $table->foreignId('marketing_consent_by_user_id')->nullable()->after('marketing_consent_at')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('client_photos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('marketing_consent_by_user_id');
            $table->dropColumn('marketing_consent_at');
        });

        Schema::dropIfExists('social_posts');
    }
};
