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
        // Tabla para BASCAR (Base Cartera)
        Schema::create('data_source_bascar', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('run_id');
            $table->string('num_tomador', 50)->nullable();
            $table->string('fecha_inicio_vig', 20)->nullable();
            $table->decimal('valor_total_fact', 15, 2)->nullable();
            $table->string('periodo', 6)->nullable(); // Calculado
            $table->string('composite_key', 100)->nullable(); // Calculado
            $table->jsonb('data')->nullable(); // Resto de columnas
            $table->timestamp('created_at')->useCurrent();

            $table->index('run_id');
            $table->index(['run_id', 'periodo']);
            $table->index(['run_id', 'composite_key']);
        });

        // Tabla para PAGAPL (Pagos Aplicados)
        Schema::create('data_source_pagapl', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('run_id');
            $table->string('identificacion', 50)->nullable();
            $table->string('periodo', 6)->nullable();
            $table->decimal('valor', 15, 2)->nullable();
            $table->string('composite_key', 100)->nullable(); // Calculado
            $table->jsonb('data')->nullable(); // Resto de columnas
            $table->timestamp('created_at')->useCurrent();

            $table->index('run_id');
            $table->index(['run_id', 'periodo']);
            $table->index(['run_id', 'composite_key']);
        });

        // Tabla para BAPRPO (Base Producción por Póliza)
        Schema::create('data_source_baprpo', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('run_id');
            $table->jsonb('data'); // Todas las columnas
            $table->timestamp('created_at')->useCurrent();

            $table->index('run_id');
        });

        // Tabla para PAGPLA (Pagos Planilla)
        Schema::create('data_source_pagpla', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('run_id');
            $table->jsonb('data'); // Todas las columnas
            $table->timestamp('created_at')->useCurrent();

            $table->index('run_id');
        });

        // Tabla para DATPOL (Datpol)
        Schema::create('data_source_datpol', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('run_id');
            $table->jsonb('data'); // Todas las columnas
            $table->timestamp('created_at')->useCurrent();

            $table->index('run_id');
        });

        // Tabla para DETTRA (Detalle Trabajadores)
        Schema::create('data_source_dettra', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('run_id');
            $table->jsonb('data'); // Todas las columnas
            $table->timestamp('created_at')->useCurrent();

            $table->index('run_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('data_source_bascar');
        Schema::dropIfExists('data_source_pagapl');
        Schema::dropIfExists('data_source_baprpo');
        Schema::dropIfExists('data_source_pagpla');
        Schema::dropIfExists('data_source_datpol');
        Schema::dropIfExists('data_source_dettra');
    }
};
