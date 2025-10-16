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
        Schema::create('city_depto', function (Blueprint $table) {
            $table->id();
            $table->string('depto_code', 2);
            $table->string('city_code', 3);
            $table->string('name_city');
            $table->string('name_depto');
            $table->timestamps();

            // Índices para mejorar performance en búsquedas
            $table->index('depto_code');
            $table->index('city_code');
            $table->index(['depto_code', 'city_code']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('city_depto');
    }
};
