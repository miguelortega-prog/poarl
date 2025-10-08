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
        Schema::table('data_source_dettra', function (Blueprint $table) {
            $table->text('actividad_empresa')->nullable()->after('acti_ries');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('data_source_dettra', function (Blueprint $table) {
            $table->dropColumn('actividad_empresa');
        });
    }
};
