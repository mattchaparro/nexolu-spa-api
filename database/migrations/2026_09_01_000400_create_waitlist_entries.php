<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La lista de espera: quien queria un cupo que no habia.
 *
 * EL DISEÑO EN UNA FRASE: cuando se libera un cupo se les avisa A TODOS los
 * que encajan, y se lo queda quien lo tome primero.
 *
 * Se descarto la alternativa clasica -- avisar de a uno con reserva temporal --
 * a proposito. El momento de la cancelacion es la hora de oro: una cancelacion
 * a las 8pm para un cupo de manana a las 10am no alcanza a recorrer una fila
 * de a 30 minutos por persona. Y el arbitro del empate ya existe: el indice
 * unico de `resource_occupancy` garantiza que solo una reserva gana, sin
 * importar cuantas lo intenten a la vez. La condicion es honestidad en el
 * mensaje -- "es para quien lo tome primero" -- para que llegar tarde no sea
 * una promesa rota.
 *
 * LA PARTE QUE DIFERENCIA: si quien toma el cupo ya tenia una cita del mismo
 * servicio, no se le crea otra -- SE LE MUEVE la que tiene. Y como mover una
 * cita libera su hueco viejo, y liberar un hueco es el disparador de esta
 * lista, la cascada de mejoras emerge sola del mismo mecanismo. No hay codigo
 * de cascada: cada paso cierra una entrada, asi que tampoco puede ciclar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('waitlist_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();

            // Nulo = cualquier sede. Quien entro por el enlace de una sede
            // espera en esa; quien entro por el del negocio, en la que sea.
            $table->foreignId('location_id')->nullable()->constrained()->nullOnDelete();

            $table->foreignId('client_id')->constrained()->cascadeOnDelete();

            /*
             * El telefono se CONGELA aca ademas de vivir en la ficha: el aviso
             * va al numero con el que la persona pidio que le avisaran, aunque
             * la ficha cambie despues.
             */
            $table->string('phone', 32);

            $table->foreignId('service_id')->constrained()->cascadeOnDelete();

            // "Con Maria o con nadie" es una preferencia real y frecuente.
            // Nulo = con quien sea.
            $table->foreignId('preferred_resource_id')->nullable()
                ->constrained('resources')->nullOnDelete();

            // El rango que le sirve. "Esta semana", "antes del 15".
            $table->date('date_from');
            $table->date('date_to');

            // La franja del dia, opcional: "solo en la manana".
            $table->time('time_from')->nullable();
            $table->time('time_to')->nullable();

            /*
             * Por donde entra al enlace de "tomar el cupo". Mismo criterio que
             * el portal de citas: 48 caracteres aleatorios que solo viajan en
             * el mensaje que se le mando a esa persona. Un id secuencial aca
             * dejaria adivinar entradas ajenas.
             */
            $table->string('token', 64)->unique();

            $table->string('status', 16)->default('open');

            /*
             * El freno de spam. Broadcast sin freno significa que una noche de
             * tres cancelaciones son tres mensajes a la misma persona -- y eso
             * quema la disposicion a leer el cuarto, que es lo unico que esta
             * lista tiene. Ademas los avisos son opt-in (la persona los pidio),
             * que es lo que los mantiene sanos ante el quality rating de Meta.
             */
            $table->dateTime('last_notified_at')->nullable();

            // Para poder decirle al negocio "la lista de espera te recupero
            // N citas": el dato que justifica la funcion.
            $table->foreignId('fulfilled_appointment_id')->nullable()
                ->constrained('appointments')->nullOnDelete();

            $table->timestamps();

            $table->index(['business_id', 'status', 'date_to']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('waitlist_entries');
    }
};
