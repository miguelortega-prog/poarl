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
        // Tabla para BASACT (Base Activos)
        Schema::create('data_source_basact', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('run_id');

            // Columnas específicas para cruces y filtros
            $table->string('nit_empresa', 50)->nullable();
            $table->string('identificacion_trabajador', 50)->nullable();
            $table->string('num_poli', 50)->nullable();
            $table->string('fecha_ini_cobert', 20)->nullable();
            $table->string('periodo', 6)->nullable(); // Calculado desde fecha_ini_cobert

            // Resto de columnas en JSONB
            $table->jsonb('data')->nullable();

            $table->timestamp('created_at')->useCurrent();

            // Índices para mejorar performance en cruces
            $table->index('run_id');
            $table->index(['run_id', 'nit_empresa']);
            $table->index(['run_id', 'identificacion_trabajador']);
            $table->index(['run_id', 'periodo']);
        });

        // Tabla para PAGLOG (Pagos Log Bancario)
        Schema::create('data_source_paglog', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('run_id');

            // Columnas específicas para cruces y filtros
            $table->string('nit_empresa', 50)->nullable();
            $table->string('periodo_pago', 20)->nullable();
            $table->decimal('valor', 15, 2)->nullable();
            $table->string('fecha_pago', 20)->nullable();
            $table->string('planilla', 50)->nullable();
            $table->string('composite_key', 100)->nullable(); // Para cruces compuestos

            // Resto de columnas en JSONB
            $table->jsonb('data')->nullable();

            $table->timestamp('created_at')->useCurrent();

            // Índices para mejorar performance en cruces
            $table->index('run_id');
            $table->index(['run_id', 'nit_empresa']);
            $table->index(['run_id', 'periodo_pago']);
            $table->index(['run_id', 'composite_key']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('data_source_basact');
        Schema::dropIfExists('data_source_paglog');
    }
};
