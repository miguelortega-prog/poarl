<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('data_source_bascar', function (Blueprint $table) {
            $table->integer('cantidad_trabajadores')->nullable()->after('composite_key');
            $table->text('observacion_trabajadores')->nullable()->after('cantidad_trabajadores');

            // Índice para búsquedas por run_id y num_tomador
            $table->index(['run_id', 'num_tomador']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('data_source_bascar', function (Blueprint $table) {
            $table->dropIndex(['run_id', 'num_tomador']);
            $table->dropColumn(['cantidad_trabajadores', 'observacion_trabajadores']);
        });
    }
};
