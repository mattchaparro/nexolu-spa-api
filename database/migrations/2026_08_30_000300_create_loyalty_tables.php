<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tarjeta de sellos.
 *
 * El diseño sale de mirar como fallo el sistema viejo. Blue Souls tenia un
 * comando `gamification:recalculate` con tres opciones, y cada una es un bug
 * que aca no puede ocurrir:
 *
 *   --create-cards    Clientes con servicios pero SIN tarjeta: sus visitas no
 *                     contaban hasta que alguien corriera el comando. Aca NO
 *                     hay entidad tarjeta: un sello es una fila atada a la
 *                     cita, y el saldo es la suma. No existe la tarjeta que
 *                     falte.
 *
 *   (recalcular)      `loyalty_cards.stamps` era un contador guardado que se
 *                     desincronizaba del conteo real. Aca no hay contador que
 *                     desincronizar: el saldo SE CUENTA.
 *
 *   --link-orphans    Clientes duplicados que habia que unir por telefono.
 *                     Eso ya lo resuelve `ClientResolver` al identificar por
 *                     telefono normalizado antes de crear ficha.
 *
 * Y una cuarta diferencia: alla los premios eran acumulativos y no se
 * reiniciaban (`required_stamps <= total`), asi que una clienta de tres anos
 * habia desbloqueado todo hace rato y el programa dejaba de motivarla. Aca la
 * tarjeta SE REINICIA: los sellos que pagaron un premio quedan marcados como
 * consumidos por el.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loyalty_programs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('terms')->nullable();

            $table->unsignedSmallInteger('stamps_required');

            // Que se gana. El servicio gratis apunta al catalogo; los otros
            // dos llevan valor.
            $table->string('reward_type');
            $table->decimal('reward_value', 12, 2)->nullable();
            $table->foreignId('reward_service_id')->nullable()
                ->constrained('services')->nullOnDelete();

            /*
             * Visita minima para ganar sello. 0 = toda visita cuenta.
             *
             * En el sistema viejo un retoque de 25.000 daba el mismo sello que
             * un juego de acrilicas de 180.000: la tarjeta se llenaba con lo
             * barato y el premio salia del margen de lo caro.
             */
            $table->decimal('min_ticket', 12, 2)->default(0);

            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['business_id', 'is_active']);
        });

        Schema::create('loyalty_stamps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('program_id')->constrained('loyalty_programs')->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('appointment_id')->constrained()->cascadeOnDelete();

            $table->timestamp('earned_at');
            // Que premio se llevo estos sellos. Nulo = todavia cuentan para el
            // saldo. Asi el reinicio queda auditado en vez de restar de un
            // contador y perder la historia.
            $table->foreignId('consumed_by_reward_id')->nullable();

            $table->timestamps();

            /*
             * Una visita da UN sello y nunca dos.
             *
             * Es la garantia que hace innecesario cualquier comando de
             * reparacion: deshacer un cobro y volver a cobrarlo, o dos
             * peticiones a la vez, no pueden duplicar el sello. La base lo
             * rechaza.
             */
            $table->unique(['program_id', 'appointment_id']);
            $table->index(['business_id', 'client_id', 'consumed_by_reward_id'], 'loyalty_stamps_saldo_idx');
        });

        Schema::create('loyalty_rewards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('program_id')->constrained('loyalty_programs')->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();

            $table->string('status')->default('available');
            $table->timestamp('unlocked_at');
            $table->timestamp('used_at')->nullable();
            $table->foreignId('used_on_appointment_id')->nullable()
                ->constrained('appointments')->nullOnDelete();

            /*
             * El premio se CONGELA al desbloquearse, igual que el precio y la
             * comision de una cita cobrada. Si el negocio cambia el programa
             * manana, a quien ya lleno su tarjeta se le entrega lo que decia
             * la tarjeta el dia que la lleno.
             */
            $table->string('reward_type');
            $table->decimal('reward_value', 12, 2)->nullable();
            $table->foreignId('reward_service_id')->nullable()
                ->constrained('services')->nullOnDelete();

            $table->timestamps();

            $table->index(['business_id', 'client_id', 'status']);
        });

        Schema::table('loyalty_stamps', function (Blueprint $table) {
            $table->foreign('consumed_by_reward_id')
                ->references('id')->on('loyalty_rewards')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('loyalty_stamps', function (Blueprint $table) {
            $table->dropForeign(['consumed_by_reward_id']);
        });

        Schema::dropIfExists('loyalty_stamps');
        Schema::dropIfExists('loyalty_rewards');
        Schema::dropIfExists('loyalty_programs');
    }
};
