<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cerrar el servicio: la foto del trabajo y el comprobante de lo que entro.
 *
 * EL PROBLEMA QUE RESUELVE es un ritual que hoy pasa por fuera del sistema.
 * La manicurista termina, le toma foto a las unas, le toma foto a la
 * transferencia, y manda las dos al grupo de WhatsApp escribiendo "unas
 * semipermanente de cuarenta mil". Lo ultimo -- el que, el cuanto, el quien --
 * YA ESTA EN ESTA BASE. Lo unico que falta son las dos imagenes, y por eso
 * ese grupo sigue existiendo.
 *
 * DOS COLUMNAS Y NADA MAS:
 *
 * - `services.requires_photo` porque no todo servicio produce algo que valga
 *   la pena fotografiar. Un semipermanente transparente no; un degradado si.
 *   Pedirla siempre entrena a la gente a subir cualquier cosa para poder
 *   cobrar.
 *
 * - `appointments.payment_proof_path` porque una transferencia es una promesa
 *   hasta que alguien la ve. Con el comprobante colgado de la cita, el cierre
 *   del dia deja de cuadrar contra lo que alguien reporto y pasa a cuadrar
 *   contra lo que se puede mirar.
 *
 * LO QUE NO SE AGREGA: una tabla de "servicio cerrado". Ya existe -- se llama
 * `checked_out_at`. Un estado paralelo que dijera lo mismo se desincroniza el
 * dia que alguien cobre por un camino que no lo actualice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            /*
             * Arranca en `true`, y no cambia nada para nadie: el negocio no
             * pide fotos hasta que enciende `service_photo_policy`, que viene
             * apagado. Cuando lo encienda, todos sus servicios piden foto y
             * apaga los que no la necesiten -- que son pocos y los conoce.
             *
             * Al reves (arrancar en false) el negocio enciende la politica, no
             * pasa nada, y concluye que la funcion no sirve.
             */
            $table->boolean('requires_photo')->default(true)->after('is_active');
        });

        Schema::table('appointments', function (Blueprint $table) {
            /*
             * El comprobante de lo que entro por fuera de la caja.
             *
             * En la CITA y no en el item: se cobra la cita completa con un
             * medio de pago, no servicio por servicio. Colgarlo del item
             * obligaria a elegir de cual de los tres es la misma transferencia.
             */
            $table->string('payment_proof_path')->nullable()->after('payment_method_id');
        });
    }

    public function down(): void
    {
        Schema::table('appointments', fn (Blueprint $t) => $t->dropColumn('payment_proof_path'));
        Schema::table('services', fn (Blueprint $t) => $t->dropColumn('requires_photo'));
    }
};
