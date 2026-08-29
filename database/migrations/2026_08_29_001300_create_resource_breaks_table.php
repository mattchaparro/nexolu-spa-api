<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Almuerzos y descansos: las horas del dia laboral en las que no se atiende.
 *
 * Existe como tabla propia y no como una `schedule_exception` mas porque son
 * dos cosas distintas:
 *
 *   - Una excepcion es un HECHO con fecha: "el 12 de septiembre Maria no viene".
 *     Una por dia. El almuerzo de todos los dias serian 365 filas al año.
 *   - Un descanso es una REGLA recurrente: "de 13:00 a 14:00, todos los dias".
 *     Una fila, para siempre.
 *
 * Tampoco se resuelve partiendo el horario en dos tramos (09:00-13:00 y
 * 14:00-18:00). Se puede -- el esquema lo permite -- pero entonces el almuerzo
 * deja de existir como cosa: nadie puede preguntarle al sistema a que hora
 * almuerza Maria, la pantalla no lo puede mostrar distinto del resto, y el dia
 * que el negocio corra el almuerzo media hora hay que reeditar cada tramo de
 * cada profesional a mano.
 *
 * NO se puede pasar por encima. Las horas extra (`extra_hours`) suman antes de
 * que los cortes resten, asi que ninguna excepcion puede reabrir un descanso, y
 * agendar dentro de uno se rechaza aunque quien agende sea el dueño. Si de
 * verdad hay que trabajar en esa franja, se cambia el descanso -- que es una
 * decision visible y con fecha -- y no se cuela una cita por debajo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resource_breaks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();

            // Nulo = de todo el negocio. El local que cierra a mediodia lo
            // configura una vez, no una por profesional.
            $table->foreignId('resource_id')->nullable()->constrained()->cascadeOnDelete();

            // Nulo = todos los dias. La mayoria de los almuerzos son iguales
            // toda la semana; obligar a crear siete filas es como se termina
            // con seis bien y una mal.
            $table->unsignedTinyInteger('weekday')->nullable(); // 1 = lunes ... 7 = domingo

            $table->time('start_time');
            $table->time('end_time');
            $table->string('label')->default('Almuerzo');

            // Con vigencia, porque el horario de almuerzo cambia y lo que ya
            // se agendo bajo el anterior tiene que seguir explicandose.
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->index(['business_id', 'weekday']);
            $table->index(['resource_id', 'weekday']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resource_breaks');
    }
};
