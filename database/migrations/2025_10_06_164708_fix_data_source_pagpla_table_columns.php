<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Corrige la tabla data_source_pagpla que tenía columnas incorrectas (mezcladas con PAGAPL).
     * Las columnas correctas son las del seeder NoticeDataSourceSeeder para PAGPLA (Pagos Planilla).
     */
    public function up(): void
    {
        // Drop y recrear la tabla con las columnas correctas
        Schema::dropIfExists('data_source_pagpla');

        Schema::create('data_source_pagpla', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('run_id')->index();

            // Columnas según seeder NoticeDataSourceSeeder (PAGPLA - Pagos Planilla)
            $table->text('modalidad_planilla')->nullable();
            $table->text('total_afiliados')->nullable();
            $table->text('identificacion_aportante')->nullable();
            $table->text('email')->nullable(); // ← Columna que faltaba
            $table->text('tipo_aportante')->nullable();
            $table->text('numero_planila')->nullable(); // Nota: typo original del seeder
            $table->text('direccion')->nullable();
            $table->text('codigo_ciudad')->nullable();
            $table->text('codigo_departamento')->nullable();
            $table->text('telefono')->nullable();
            $table->text('fax')->nullable();
            $table->text('periodo_pago')->nullable();
            $table->text('tipo_planilla')->nullable();
            $table->text('fecha_pago')->nullable();
            $table->text('codigo_operador')->nullable();
            $table->text('sheet_name')->nullable(); // Para tracking de hojas Excel

            $table->timestamp('created_at')->useCurrent();

            // Índices útiles
            $table->index(['run_id', 'identificacion_aportante']);
            $table->index(['run_id', 'periodo_pago']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('data_source_pagpla');

        // Recrear con la estructura anterior (incorrecta, pero para rollback)
        Schema::create('data_source_pagpla', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('run_id')->index();

            $table->text('poliza')->nullable();
            $table->text('tipo_documento')->nullable();
            $table->text('identificacion')->nullable();
            $table->text('tomador')->nullable();
            $table->text('fecha_pago')->nullable();
            $table->text('aportes')->nullable();
            $table->text('siniestros')->nullable();
            $table->text('intereses')->nullable();
            $table->text('saldo')->nullable();
            $table->text('valor_pagado')->nullable();
            $table->text('periodo')->nullable();
            $table->text('fec_cruce')->nullable();
            $table->text('fec_reca')->nullable();
            $table->text('planilla')->nullable();
            $table->text('operador')->nullable();
            $table->text('usuario')->nullable();
            $table->text('concepto')->nullable();
            $table->text('sheet_name')->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index(['run_id', 'poliza']);
            $table->index(['run_id', 'identificacion']);
        });
    }
};
