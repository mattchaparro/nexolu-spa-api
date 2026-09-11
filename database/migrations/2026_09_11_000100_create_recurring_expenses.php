<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Los gastos que se repiten todos los meses.
 *
 * El sistema viejo los tenia como una lista de nombres y montos, y alguien
 * los copiaba a mano el primero de cada mes. Funciono ocho veces al mes,
 * puntual, desde 2025 -- y en mayo de 2026 se corto. Cinco meses seguidos sin
 * registrar ~1,2 millones cada uno.
 *
 * Eso no es descuido de nadie: un paso manual que hay que acordarse de dar
 * cada treinta dias se deja de dar. El arriendo se causa igual, asi que la
 * unica version que funciona es la que se genera sola.
 *
 * EL MONTO ES UNA SUGERENCIA, no una verdad. En el legacy la plantilla decia
 * "Servidor 80.000" y el gasto real de abril fueron 36.000; los insumos decian
 * 210.000 y fueron 100.000 en abril y 360.000 en marzo. Asi que lo generado es
 * un gasto normal y corriente: se edita y se borra como cualquier otro.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recurring_expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('expense_type_id')->constrained();

            $table->string('description');
            $table->decimal('value', 12, 2);

            /*
             * Que dia del mes se causa. 1 por defecto.
             *
             * Un dia 31 en febrero se recorta al ultimo dia del mes en vez de
             * saltarse: el arriendo de febrero existe aunque febrero no tenga
             * 31.
             */
            $table->unsignedTinyInteger('day_of_month')->default(1);

            // Opcionales, igual que en un gasto suelto.
            $table->foreignId('payment_method_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('location_id')->nullable()->constrained()->nullOnDelete();
            $table->string('scope')->default('operational');

            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['business_id', 'is_active']);
        });

        Schema::table('expenses', function (Blueprint $table) {
            /*
             * De que plantilla salio, y de que mes.
             *
             * El par (plantilla, periodo) es UNICO: es lo que impide que
             * correr la generacion dos veces -- o que el cron se dispare dos
             * veces tras un reinicio -- deje el arriendo cobrado dos veces.
             * Sin eso, la forma de enterarse seria que el mes cierre con
             * 840.000 de mas.
             */
            $table->foreignId('recurring_expense_id')->nullable()->after('expense_type_id')
                ->constrained()->nullOnDelete();
            $table->string('recurring_period', 7)->nullable()->after('recurring_expense_id');

            $table->unique(['recurring_expense_id', 'recurring_period'], 'expenses_recurring_unique');
        });
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            /*
             * En este orden y no en otro: MySQL cuelga la clave foranea del
             * indice unico, asi que borrar el indice primero falla con un
             * error de PDO que no dice por que. Primero la foranea, despues su
             * indice, y al final las columnas.
             */
            $table->dropForeign(['recurring_expense_id']);
            $table->dropUnique('expenses_recurring_unique');
            $table->dropColumn(['recurring_expense_id', 'recurring_period']);
        });

        Schema::dropIfExists('recurring_expenses');
    }
};
