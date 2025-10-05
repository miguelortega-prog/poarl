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
        // Drop existing tables
        Schema::dropIfExists('data_source_pagapl');
        Schema::dropIfExists('data_source_pagpla');
        Schema::dropIfExists('data_source_dettra');

        // Recrear PAGAPL con máximo de columnas encontradas (17 columnas)
        Schema::create('data_source_pagapl', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('run_id')->index();

            // Columnas comunes encontradas en los sheets
            $table->text('poliza')->nullable();
            $table->text('t_doc')->nullable();
            $table->text('identifi')->nullable();
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
            $table->index(['run_id', 'identifi']);
        });

        // Recrear PAGPLA con máximo de columnas encontradas (18 columnas)
        Schema::create('data_source_pagpla', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('run_id')->index();

            // Columnas comunes encontradas en los sheets
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

        // Recrear DETTRA con 40 columnas (38 de datos + 1 vacía + sheet_name)
        Schema::create('data_source_dettra', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('run_id')->index();

            $table->text('acti_ries')->nullable();
            $table->text('cpos_ries')->nullable();
            $table->text('key')->nullable();
            $table->text('cod_ries')->nullable();
            $table->text('num_poli')->nullable();
            $table->text('nit')->nullable();
            $table->text('tipo_doc')->nullable();
            $table->text('tipo_cotizante')->nullable();
            $table->text('fecha_ini_cobert')->nullable();
            $table->text('estado')->nullable();
            $table->text('riesgo')->nullable();
            $table->text('sexo')->nullable();
            $table->text('fech_nacim')->nullable();
            $table->text('desc_ries')->nullable();
            $table->text('dire_ries')->nullable();
            $table->text('clas_ries')->nullable();
            $table->text('acti_desc')->nullable();
            $table->text('cod_dpto_trabajador')->nullable();
            $table->text('cod_ciudad_trabajador')->nullable();
            $table->text('dpto_trabajador')->nullable();
            $table->text('ciudad_trabajador')->nullable();
            $table->text('bean')->nullable();
            $table->text('nro_documto')->nullable();
            $table->text('cpos_benef')->nullable();
            $table->text('nom_benef')->nullable();
            $table->text('estado_empresa')->nullable();
            $table->text('salario')->nullable();
            $table->text('rango_salario')->nullable();
            $table->text('edad')->nullable();
            $table->text('rango_edad')->nullable();
            $table->text('cod_dpto_empresa')->nullable();
            $table->text('cod_ciudad_empresa')->nullable();
            $table->text('dpto_empresa')->nullable();
            $table->text('ciudad_empresa')->nullable();
            $table->text('ciiu')->nullable();
            $table->text('grupo_actual')->nullable();
            $table->text('grupo_actual_cod')->nullable();
            $table->text('sector_fasecolda')->nullable();
            $table->text('col_empty')->nullable(); // Columna vacía del CSV
            $table->text('sheet_name')->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index(['run_id', 'num_poli']);
            $table->index(['run_id', 'nro_documto']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('data_source_pagapl');
        Schema::dropIfExists('data_source_pagpla');
        Schema::dropIfExists('data_source_dettra');
    }
};
