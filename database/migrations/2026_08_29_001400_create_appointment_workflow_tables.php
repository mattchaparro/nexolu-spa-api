<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Flujos de estado de una cita, con acciones al entrar a cada etapa.
 *
 * Mismo patron que `service_workflows` en nexolu-pos-api -- plantillas de
 * plataforma, cada negocio elige una -- con una diferencia que aca es
 * obligatoria: **la etapa NO es el estado**.
 *
 * En el POS la etapa es el estado, y funciona porque una orden de servicio no
 * alimenta ningun calculo. Aca el estado de una cita decide si el recurso sigue
 * ocupado, si el cobro entra al cierre del dia y si la comision entra a la
 * nomina. Si un negocio pudiera inventar estados nucleo desde una pantalla de
 * configuracion, podria descuadrar su propia caja sin tocar una linea de
 * codigo. Por eso cada etapa apunta a uno de los seis estados nucleo con
 * `maps_to_status`: el negocio elige el nombre, el color, el orden y lo que se
 * dispara; el nucleo sigue siendo el mismo para todos.
 *
 * Eso ademas permite lo que un spa si necesita: dos etapas que apuntan al mismo
 * estado ("Confirmada por WhatsApp" y "Confirmada por telefono"), cada una con
 * su propia accion.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appointment_workflows', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('description')->nullable();
            // El que se le asigna a un negocio nuevo. Sin esto habria que
            // acordarse de elegir uno en cada alta, y el que se olvide queda
            // sin etapas y sin automatizaciones.
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('appointment_workflow_stages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_id')->constrained('appointment_workflows')->cascadeOnDelete();

            // Estable aunque cambie la etiqueta: es lo que referencian las
            // automatizaciones y lo que sobrevive a un cambio de nombre.
            $table->string('key', 40);
            $table->string('label');
            $table->string('color', 9)->default('#64748b');
            $table->unsignedInteger('sort_order')->default(0);

            // A cual de los seis estados nucleo corresponde. Es la bisagra
            // entre lo que el negocio configura y lo que el sistema calcula.
            $table->string('maps_to_status', 24);

            $table->boolean('is_initial')->default(false);

            /*
             * Que se dispara AL ENTRAR a esta etapa. Lista de
             * {type, config}. JSON y no tabla propia porque no se consulta
             * por accion: siempre se leen todas las de una etapa, juntas.
             */
            $table->json('actions')->nullable();

            $table->timestamps();

            $table->unique(['workflow_id', 'key']);
            $table->index(['workflow_id', 'sort_order']);
        });

        Schema::table('businesses', function (Blueprint $table) {
            $table->foreignId('appointment_workflow_id')->nullable()->after('feature_flags')
                ->constrained('appointment_workflows')->nullOnDelete();
        });

        Schema::table('appointments', function (Blueprint $table) {
            // Nula mientras el negocio no use flujos. El estado nucleo siempre
            // existe; la etapa es la lectura del negocio encima de el.
            $table->foreignId('stage_id')->nullable()->after('status')
                ->constrained('appointment_workflow_stages')->nullOnDelete();
        });

        /*
         * Bitacora: quien movio que cita, de donde a donde, y que se disparo.
         *
         * Vale por si sola aunque no hubiera acciones. Hoy la unica huella de
         * un cambio de estado es el estado nuevo: si una cita aparece cancelada
         * nadie sabe quien ni cuando. Con automatizaciones encima es
         * indispensable -- "¿le llego el mensaje?" tiene que tener respuesta.
         */
        Schema::create('appointment_stage_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('appointment_id')->constrained()->cascadeOnDelete();

            $table->string('from_status', 24)->nullable();
            $table->string('to_status', 24);
            $table->foreignId('from_stage_id')->nullable()
                ->constrained('appointment_workflow_stages')->nullOnDelete();
            $table->foreignId('to_stage_id')->nullable()
                ->constrained('appointment_workflow_stages')->nullOnDelete();

            // Quien lo movio. Nulo cuando lo movio el sistema: el agente de
            // WhatsApp, un recordatorio, una tarea programada.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('actor', 32)->default('user'); // user | system | agent | client

            /*
             * Resultado de cada accion: [{type, status, detail}]. `status` es
             * ok | failed | skipped. Se guarda el fallo, no se descarta: una
             * accion que fallo en silencio es peor que uno que no existio.
             */
            $table->json('actions')->nullable();

            $table->timestamps();

            $table->index(['appointment_id', 'created_at']);
            $table->index(['business_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointment_stage_events');

        Schema::table('appointments', function (Blueprint $table) {
            $table->dropForeign(['stage_id']);
            $table->dropColumn('stage_id');
        });

        Schema::table('businesses', function (Blueprint $table) {
            $table->dropForeign(['appointment_workflow_id']);
            $table->dropColumn('appointment_workflow_id');
        });

        Schema::dropIfExists('appointment_workflow_stages');
        Schema::dropIfExists('appointment_workflows');
    }
};
