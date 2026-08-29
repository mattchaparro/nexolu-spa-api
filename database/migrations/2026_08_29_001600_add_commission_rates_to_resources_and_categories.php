<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Los dos escalones de comision que faltaban.
 *
 * Hasta ahora solo existian el porcentaje del SERVICIO y un acuerdo puntual
 * por (persona, servicio). Eso deja mal resueltos los dos casos mas comunes:
 *
 * - "Ana va al 50% en todo." Habia que ponerle el acuerdo puntual en CADA
 *   servicio, uno por uno, y acordarse de hacerlo otra vez cada vez que
 *   entrara un servicio nuevo al catalogo -- que es exactamente el momento en
 *   que nadie se acuerda, y Ana empieza a cobrar de menos sin que nadie lo note.
 *
 * - "Todo lo de pestañas paga 40%." Con 20 servicios de manicure y 8 de
 *   pestañas, cambiar el porcentaje de una familia era editar 28 fichas.
 *
 * Nullable a proposito: null significa "no configurado, pregunta mas abajo en
 * la cascada", que es distinto de 0 -- "este no paga comision". Ver
 * App\Support\Money\CommissionResolver.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('resources', function (Blueprint $table) {
            $table->decimal('commission_rate', 5, 4)->nullable()->after('payroll_mode');
        });

        Schema::table('service_categories', function (Blueprint $table) {
            $table->decimal('commission_rate', 5, 4)->nullable()->after('name');
            // Para poder apagar una familia sin borrarla ni perder el
            // historial de los servicios que colgaban de ella.
            $table->boolean('is_active')->default(true)->after('sort_order');
        });
    }

    public function down(): void
    {
        Schema::table('resources', function (Blueprint $table) {
            $table->dropColumn('commission_rate');
        });

        Schema::table('service_categories', function (Blueprint $table) {
            $table->dropColumn(['commission_rate', 'is_active']);
        });
    }
};
