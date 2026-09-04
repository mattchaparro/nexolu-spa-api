<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Una publicacion tiene IMAGENES, no una imagen.
 *
 * Un carrusel no es un lujo en este negocio: un trabajo de unas se muestra de
 * frente, de lado y con la mano cerrada, y son tres fotos de lo mismo. Con una
 * sola columna, el negocio elige cual de las tres y descarta las otras dos.
 *
 * SE QUITAN LAS COLUMNAS VIEJAS, no se dejan conviviendo. Un `image_path` que
 * sigue ahi "por compatibilidad" al lado de una tabla de imagenes es dos
 * fuentes de verdad para la misma pregunta, y en algun camino -- el que nadie
 * toco al migrar -- una publicacion va a salir con la imagen equivocada. Lo
 * que habia se copia a la tabla nueva antes de borrarlas.
 *
 * EL ORDEN IMPORTA Y POR ESO ES UNA COLUMNA. La primera imagen es la portada:
 * es la unica que se ve en la cuadricula del perfil, y es la que decide si
 * alguien abre la publicacion. Confiar en el orden de insercion seria dejar
 * esa decision al azar del orden en que alguien tocó las fotos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_post_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('social_post_id')->constrained()->cascadeOnDelete();

            /*
             * Una foto de la ficha, o una imagen suelta. Exactamente una de
             * las dos, y la diferencia es de quien es: la de la ficha es de
             * una clienta y solo llega hasta aca con su permiso; la suelta
             * -- la vitrina, el equipo, un flyer -- no es de nadie.
             *
             * `nullOnDelete` y no cascada: si la foto se borra de la ficha, la
             * publicacion pierde ESA imagen y sigue existiendo. Borrarle la
             * publicacion entera al negocio porque alguien limpio una ficha
             * seria un efecto que nadie espera.
             */
            $table->foreignId('client_photo_id')->nullable()->constrained()->nullOnDelete();
            $table->string('image_path')->nullable();

            // La portada es la 0. Ver el comentario de arriba.
            $table->unsignedTinyInteger('position')->default(0);

            $table->timestamps();

            $table->index(['social_post_id', 'position']);
        });

        /*
         * Lo que ya existia se copia. Es una migracion de una tabla que hoy
         * casi no tiene filas, pero escribirla igual es lo que permite
         * desplegarla sin coordinar con nadie.
         */
        DB::table('social_posts')
            ->select('id', 'business_id', 'client_photo_id', 'image_path')
            ->where(function ($q) {
                $q->whereNotNull('client_photo_id')->orWhereNotNull('image_path');
            })
            ->orderBy('id')
            ->chunk(200, function ($posts) {
                $rows = [];

                foreach ($posts as $post) {
                    $rows[] = [
                        'business_id' => $post->business_id,
                        'social_post_id' => $post->id,
                        'client_photo_id' => $post->client_photo_id,
                        'image_path' => $post->image_path,
                        'position' => 0,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }

                DB::table('social_post_images')->insert($rows);
            });

        Schema::table('social_posts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('client_photo_id');
            $table->dropColumn('image_path');
        });
    }

    public function down(): void
    {
        Schema::table('social_posts', function (Blueprint $table) {
            $table->foreignId('client_photo_id')->nullable()->after('subject_date')
                ->constrained()->nullOnDelete();
            $table->string('image_path')->nullable()->after('client_photo_id');
        });

        // Se devuelve la PORTADA. Las demas no caben, y esa es justamente la
        // razon por la que esta tabla existe.
        DB::table('social_post_images')
            ->where('position', 0)
            ->orderBy('id')
            ->chunk(200, function ($images) {
                foreach ($images as $image) {
                    DB::table('social_posts')->where('id', $image->social_post_id)->update([
                        'client_photo_id' => $image->client_photo_id,
                        'image_path' => $image->image_path,
                    ]);
                }
            });

        Schema::dropIfExists('social_post_images');
    }
};
