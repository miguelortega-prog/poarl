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
        Schema::dropIfExists('data_source_basact');
        Schema::dropIfExists('data_source_paglog');

        // Recrear BASACT con todas las 45 columnas como TEXT (patrón Excel)
        Schema::create('data_source_basact', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('run_id')->index();

            // Columnas del Excel BASACT (45 columnas)
            $table->text('ramo')->nullable();
            $table->text('num_poli')->nullable();
            $table->text('nit_empresa')->nullable();
            $table->text('nombre_empresa')->nullable();
            $table->text('codigo_departamento_empresa')->nullable();
            $table->text('codigo_municipio_empresa')->nullable();
            $table->text('codigo_centro_trabajo_empresa')->nullable();
            $table->text('nombre_centro_trabajo_empresa')->nullable();
            $table->text('riesgo_empresa')->nullable();
            $table->text('codigo_actividad_economica_empresa')->nullable();
            $table->text('nombre_actividad_economica_empresa')->nullable();
            $table->text('direccion_empresa')->nullable();
            $table->text('tel_empresa')->nullable();
            $table->text('correo_empresa')->nullable();
            $table->text('tipo_id_trabajador')->nullable();
            $table->text('identificacion_trabajador')->nullable();
            $table->text('1_nombre_trabajador')->nullable();
            $table->text('2_nombre_trabajador')->nullable();
            $table->text('1_apellido_trabajador')->nullable();
            $table->text('2_apellido_trabajador')->nullable();
            $table->text('nombre_completo')->nullable();
            $table->text('edad')->nullable();
            $table->text('sexo')->nullable();
            $table->text('codigo_centro_trabajo_trabajador')->nullable();
            $table->text('nombre_centro_trabajo_trabajador')->nullable();
            $table->text('departamento_centro_trabajo_trabajador')->nullable();
            $table->text('ciudad_centro_trabajo_trabajador')->nullable();
            $table->text('riesgo_centro_trabajo')->nullable();
            $table->text('codigo_actividad_economica_centro_trabajo_trabajador')->nullable();
            $table->text('nombre_actividad_economica_centro_de_trabajo_trabajador')->nullable();
            $table->text('direccion_trabajador')->nullable();
            $table->text('tel_trabajador')->nullable();
            $table->text('correo_trabajador')->nullable();
            $table->text('salario_trabajador')->nullable();
            $table->text('cargo_trabajador')->nullable();
            $table->text('tipo_cotizante')->nullable();
            $table->text('fecha_creacion')->nullable();
            $table->text('fecha_ini_cobert')->nullable();
            $table->text('fecha_retiro')->nullable();
            $table->text('fech_nacim')->nullable();
            $table->text('fecha_reporte')->nullable();
            $table->text('eps')->nullable();
            $table->text('fondo_de_pensiones')->nullable();
            $table->text('estado_ing_ret')->nullable(); // ESTADO(ING-RET)
            $table->text('bean_si_no')->nullable(); // BEAN(SI-NO)
            $table->text('sheet_name')->nullable();

            $table->timestamp('created_at')->useCurrent();

            // Índices para cruces (basados en la lógica del procesador)
            $table->index(['run_id', 'nit_empresa']);
            $table->index(['run_id', 'identificacion_trabajador']);
            $table->index(['run_id', 'num_poli']);
        });

        // Recrear PAGLOG con todas las 9 columnas como TEXT (patrón Excel)
        Schema::create('data_source_paglog', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('run_id')->index();

            // Columnas del Excel PAGLOG (9 columnas)
            $table->text('nit_empresa')->nullable();
            $table->text('planilla')->nullable();
            $table->text('fecha_pago')->nullable();
            $table->text('periodo_pago')->nullable();
            $table->text('valor')->nullable();
            $table->text('fecha_proceso')->nullable();
            $table->text('operador')->nullable();
            $table->text('error')->nullable();
            $table->text('producto')->nullable();
            $table->text('sheet_name')->nullable();

            $table->timestamp('created_at')->useCurrent();

            // Índices para cruces (basados en la lógica del procesador)
            $table->index(['run_id', 'nit_empresa']);
            $table->index(['run_id', 'periodo_pago']);
            $table->index(['run_id', 'planilla']);
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
