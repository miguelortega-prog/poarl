<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Drop existing tables
        Schema::dropIfExists('data_source_bascar');
        Schema::dropIfExists('data_source_baprpo');
        Schema::dropIfExists('data_source_datpol');

        // Recrear BASCAR con todas las 56 columnas reales
        Schema::create('data_source_bascar', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('run_id')->index();

            // Columnas del CSV (56 columnas reales, 2 vacías al final)
            $table->text('compania')->nullable();
            $table->text('cod_loc')->nullable();
            $table->text('cod_vendedor')->nullable();
            $table->text('nom_vendedor')->nullable();
            $table->text('cod_ramo')->nullable();
            $table->text('nom_ramo')->nullable();
            $table->text('cod_producto')->nullable();
            $table->text('num_poliza')->nullable();
            $table->text('num_poliza_ppal')->nullable();
            $table->text('num_certificado')->nullable();
            $table->text('desc_endoso')->nullable();
            $table->text('num_factura')->nullable();
            $table->text('cod_subproducto')->nullable();
            $table->text('nom_subproducto')->nullable();
            $table->text('tipo_poliza')->nullable();
            $table->text('num_tomador_ppal')->nullable();
            $table->text('nom_tomador_ppal')->nullable();
            $table->text('num_tomador')->nullable();
            $table->text('nom_tomador')->nullable();
            $table->text('num_asegurado')->nullable();
            $table->text('ident_asegurado')->nullable();
            $table->text('nom_asegurado')->nullable();
            $table->text('correo')->nullable();
            $table->text('fecha_inicio_vig')->nullable();
            $table->text('fecha_finalizacion')->nullable();
            $table->text('fecha_expedicion')->nullable();
            $table->text('valor_total_fact')->nullable();
            $table->text('valor_dolar_fact')->nullable();
            $table->text('dias_mora')->nullable();
            $table->text('coaseguro')->nullable();
            $table->text('nom_coaseguro')->nullable();
            $table->text('referido')->nullable();
            $table->text('forma_pago')->nullable();
            $table->text('periodicidad')->nullable();
            $table->text('cobertura')->nullable();
            $table->text('estado')->nullable();
            $table->text('valor_comision')->nullable();
            $table->text('porc_comision')->nullable();
            $table->text('convenio_soat')->nullable();
            $table->text('nom_convenio_soat')->nullable();
            $table->text('estado_juridico')->nullable();
            $table->text('num_placa')->nullable();
            $table->text('benef_oner')->nullable();
            $table->text('iden_oner')->nullable();
            $table->text('nombre_oner')->nullable();
            $table->text('dir_oner')->nullable();
            $table->text('poliza_finan')->nullable();
            $table->text('estado_factura')->nullable();
            $table->text('contrato')->nullable();
            $table->text('email_tom')->nullable();
            $table->text('cel_tom')->nullable();
            $table->text('dir_tom')->nullable();
            $table->text('ciu_tom')->nullable();
            $table->text('tel_tom')->nullable();
            $table->text('val_ini')->nullable();
            $table->text('fe')->nullable();
            $table->text('col_57')->nullable(); // Columna vacía 57
            $table->text('col_58')->nullable(); // Columna vacía 58

            $table->timestamp('created_at')->useCurrent();

            $table->index(['run_id', 'num_tomador']);
            $table->index(['run_id', 'num_poliza']);
        });

        // Recrear BAPRPO con 2 columnas
        Schema::create('data_source_baprpo', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('run_id')->index();

            $table->text('tomador')->nullable();
            $table->text('pol_independiente')->nullable();

            $table->timestamp('created_at')->useCurrent();
        });

        // Recrear DATPOL con 45 columnas
        Schema::create('data_source_datpol', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('run_id')->index();

            $table->text('key')->nullable();
            $table->text('cod_agencia')->nullable();
            $table->text('num_poli')->nullable();
            $table->text('nro_documto')->nullable();
            $table->text('nom_benef')->nullable();
            $table->text('cpos_benef')->nullable();
            $table->text('dom_benef')->nullable();
            $table->text('tel_benef')->nullable();
            $table->text('tipo_benef')->nullable();
            $table->text('cod_prod')->nullable();
            $table->text('clase_aportan')->nullable();
            $table->text('total_traba')->nullable();
            $table->text('clase_afili')->nullable();
            $table->text('act_empre')->nullable();
            $table->text('arp')->nullable();
            $table->text('valor_aporte')->nullable();
            $table->text('fecha_vig_pol')->nullable();
            $table->text('fec_anu_pol')->nullable();
            $table->text('cod_end')->nullable();
            $table->text('sub_cod_end')->nullable();
            $table->text('fecha_emi_end')->nullable();
            $table->text('cod_usr')->nullable();
            $table->text('fecha_origen')->nullable();
            $table->text('canal_davivir')->nullable();
            $table->text('canal_secundar')->nullable();
            $table->text('clase_riesgo')->nullable();
            $table->text('actividad')->nullable();
            $table->text('centralizado')->nullable();
            $table->text('cliente_importante')->nullable();
            $table->text('codigo_pyme')->nullable();
            $table->text('codigo_asesor')->nullable();
            $table->text('cod_producto')->nullable();
            $table->text('bean')->nullable();
            $table->text('acti_ries')->nullable();
            $table->text('ocupacion')->nullable();
            $table->text('localidad')->nullable();
            $table->text('vigencia')->nullable();
            $table->text('polic_vig')->nullable();
            $table->text('estado')->nullable();
            $table->text('cod_dpto')->nullable();
            $table->text('cod_ciudad')->nullable();
            $table->text('dpto')->nullable();
            $table->text('ciudad')->nullable();
            $table->text('wres_dep')->nullable();
            $table->text('wres_inde')->nullable();

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
        Schema::dropIfExists('data_source_bascar');
        Schema::dropIfExists('data_source_baprpo');
        Schema::dropIfExists('data_source_datpol');
    }
};
