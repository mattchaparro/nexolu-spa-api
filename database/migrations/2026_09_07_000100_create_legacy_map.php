<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El puente entre el id viejo y el nuevo.
 *
 * Existe por una razon concreta: la app legacy de Luxury NO se apaga el dia
 * de la migracion. Sigue atendiendo clientas mientras el equipo se acostumbra
 * al sistema nuevo, asi que la importacion se corre HOY con todo el historico
 * y se vuelve a correr manana con lo que paso hoy, y pasado con lo de manana.
 *
 * Sin esta tabla, la segunda corrida duplicaria las 767 clientas y las 3.329
 * atenciones. Con ella, cada fila del legacy se importa UNA vez: si ya esta
 * mapeada se salta o se actualiza, y si no, se crea.
 *
 * `fingerprint` es un hash de los campos que importan de la fila origen. En
 * una corrida diaria la enorme mayoria de filas no cambio; comparar el hash
 * evita tocar la base por 3.300 filas para descubrir que nada se movio.
 *
 * NO tiene clave foranea contra la fila nueva a proposito: apunta a ocho
 * tablas distintas segun `entity`, y una FK polimorfica no existe. La
 * integridad se cuida al reves -- si la fila nueva desaparece, la importacion
 * la vuelve a crear, que es exactamente lo que se quiere.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legacy_map', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();

            // Que se importo: client, service, resource, appointment, expense...
            $table->string('entity', 40);

            $table->unsignedBigInteger('legacy_id');
            $table->unsignedBigInteger('new_id');

            $table->string('fingerprint', 64)->nullable();

            $table->timestamps();

            /*
             * La garantia de idempotencia, en el motor y no en el codigo: dos
             * corridas simultaneas del comando no pueden crear dos veces la
             * misma clienta, porque la segunda insercion choca aqui.
             */
            $table->unique(['business_id', 'entity', 'legacy_id'], 'legacy_map_origen_unico');

            // Para el camino inverso: "¿esta cita de donde salio?"
            $table->index(['business_id', 'entity', 'new_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legacy_map');
    }
};
