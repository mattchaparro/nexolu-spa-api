<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            // Ruta dentro del disco, no URL completa. La URL la arma el
            // Resource al serializar: si manana cambia el CDN o el bucket, se
            // cambia en un solo lugar y no hay que reescribir la base.
            $table->string('image_path')->nullable()->after('description');
        });

        Schema::table('resources', function (Blueprint $table) {
            $table->string('photo_path')->nullable()->after('color');
        });

        Schema::table('businesses', function (Blueprint $table) {
            $table->string('cover_path')->nullable()->after('logo_path');
        });
    }

    public function down(): void
    {
        Schema::table('businesses', fn (Blueprint $t) => $t->dropColumn('cover_path'));
        Schema::table('resources', fn (Blueprint $t) => $t->dropColumn('photo_path'));
        Schema::table('services', fn (Blueprint $t) => $t->dropColumn('image_path'));
    }
};
