<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vender producto: cremas, esmaltes, velas.
 *
 * El sistema viejo lo tenia y el dueño lo describio como "superparcheado":
 * una tabla de ventas suelta, con el stock en una columna del producto y
 * movimientos que no cuadraban con ella. Y lo que mas duele: esas ventas --
 * 50 en año y medio, 1.070.000 -- NO entraban en los reportes de ingresos.
 * Se vendia y no aparecia.
 *
 * Aca se hace al reves: la venta es lo que manda y el stock se deduce de los
 * movimientos. Asi el inventario no puede "descuadrarse" respecto a las
 * ventas, porque sale de ellas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->string('sku')->nullable();
            $table->text('description')->nullable();
            $table->string('image_path')->nullable();

            $table->decimal('price', 12, 2);

            /*
             * Lo que le costo al negocio. Opcional y aparte del precio: sin
             * esto "vendi 1.070.000 en producto" no dice si se gano o se
             * perdio plata.
             */
            $table->decimal('cost', 12, 2)->nullable();

            /*
             * El stock vive aca como SALDO, pero se calcula de los
             * movimientos: es un espejo para no sumar toda la historia en cada
             * consulta. La verdad esta en `product_stock_movements`.
             */
            $table->integer('stock')->default(0);

            // Desde cuantas unidades avisar. Nulo = no avisar.
            $table->unsignedInteger('low_stock_at')->nullable();

            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['business_id', 'is_active']);
        });

        Schema::create('product_sales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained();

            /*
             * A que visita se colgo, si se vendio mientras se cobraba un
             * servicio. Nulo es legitimo: alguien puede entrar solo a comprar
             * una crema.
             */
            $table->foreignId('appointment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('location_id')->nullable()->constrained()->nullOnDelete();

            $table->unsignedInteger('quantity');

            /*
             * El precio SE CONGELA en la venta, no se lee del producto.
             *
             * Subir el precio de la crema en octubre no puede cambiar lo que
             * se cobro en septiembre: eso es reescribir una venta cerrada, y
             * es justo lo que hace que los reportes de un mes ya cerrado
             * cambien solos.
             */
            $table->decimal('unit_price', 12, 2);
            $table->decimal('unit_cost', 12, 2)->nullable();
            $table->decimal('total', 12, 2);

            $table->foreignId('payment_method_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('sold_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            /*
             * `datetime` y no `timestamp`, igual que `appointments.checked_out_at`.
             *
             * Las columnas `timestamp` las convierte MySQL segun la timezone
             * de la sesion; las `datetime` se guardan tal cual. Los reportes
             * comparan contra `checked_out_at`, asi que si esta se guardara
             * distinto, la venta de las 6 de la tarde caeria en el cierre del
             * dia siguiente.
             */
            $table->dateTime('sold_at');

            $table->timestamps();
            $table->softDeletes();

            $table->index(['business_id', 'sold_at']);
        });

        Schema::create('product_stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();

            /*
             * `entrada` compra o reposicion; `venta` lo que salio vendido;
             * `ajuste` el conteo fisico o lo que se rompio.
             *
             * La cantidad va CON SIGNO: +12 entraron, -1 se vendio. Sumar la
             * columna da el saldo, sin tener que saber que tipo resta.
             */
            $table->string('kind');
            $table->integer('quantity');

            $table->foreignId('product_sale_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note')->nullable();

            $table->timestamps();

            $table->index(['business_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_stock_movements');
        Schema::dropIfExists('product_sales');
        Schema::dropIfExists('products');
    }
};
