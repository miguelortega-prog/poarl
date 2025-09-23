<?php

namespace Database\Seeders;

use App\Models\NoticeDataSource;
use App\Models\NoticeDataSourceColumn;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class NoticeDataSourceSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        DB::transaction(function () {
            $now = now();

            $catalog = [
                [
                    'code'        => 'BASCAR',
                    'name'        => 'base cartera',
                    'columns'     => [
                        'COMPANIA','COD_LOC','COD_VENDEDOR','NOM_VENDEDOR','COD_RAMO','NOM_RAMO','COD_PRODUCTO','NUM_POLIZA','NUM_POLIZA_PPAL','NUM_CERTIFICADO','DESC_ENDOSO','NUM_FACTURA','COD_SUBPRODUCTO','NOM_SUBPRODUCTO','TIPO_POLIZA','NUM_TOMADOR_PPAL','NOM_TOMADOR_PPAL','NUM_TOMADOR','NOM_TOMADOR','NUM_ASEGURADO','IDENT_ASEGURADO','NOM_ASEGURADO','CORREO','FECHA_INICIO_VIG','FECHA_FINALIZACION','FECHA_EXPEDICION','VALOR_TOTAL_FACT','VALOR_DOLAR_FACT','DIAS_MORA','COASEGURO','NOM_COASEGURO','REFERIDO','FORMA_PAGO','PERIODICIDAD','COBERTURA','ESTADO','VALOR_COMISION','PORC_COMISION','CONVENIO_SOAT','NOM_CONVENIO_SOAT','ESTADO_JURIDICO','NUM_PLACA','BENEF_ONER','IDEN_ONER','NOMBRE_ONER','DIR_ONER','POLIZA_FINAN','ESTADO_FACTURA','CONTRATO','EMAIL_TOM','CEL_TOM','DIR_TOM','CIU_TOM','TEL_TOM','VAL_INI','FE',
                    ],
                ],
                [
                    'code'        => 'PAGAPL',
                    'name'        => 'pagos aplicados',
                    'columns'     => [
                        'Poliza','T.Doc','Identifi','Tomador','Fecha Pago','Aportes','Siniestros','Intereses','Saldo','Valor Pagado','Periodo','Fec Cruce','Fec. Reca','Planilla','Operador','Usuario',
                    ],
                ],                
                [
                    'code'        => 'PAGLOG',
                    'name'        => 'pagos log bancario',
                    'columns'     => [
                        'Nit Empresa','Planilla','Fecha Pago','Periodo Pago','Valor','Fecha Proceso','Operador','Error','Producto',
                    ],
                ],
                [
                    'code'        => 'BAPRPO',
                    'name'        => 'base produccion por poliza',
                    'columns'     => [
                        'TOMADOR',
                        'POL_INDEPENDIENTE',
                    ],
                ],
                [
                    'code'        => 'PAGPLA',
                    'name'        => 'pagos planilla',
                    'columns'     => [
                        'MODALIDAD_PLANILLA','TOTAL_AFILIADOS','IDENTIFICACION_APORTANTE','EMAIL','TIPO_APORTANTE','NUMERO PLANILA','DIRECCION','CODIGO_CIUDAD','CODIGO_DEPARTAMENTO','TELEFONO','FAX','PERIODO_PAGO','TIPO_PLANILLA','FECHA_PAGO','CODIGO_OPERADOR',
                    ],
                ],
                [
                    'code'        => 'DATPOL',
                    'name'        => 'datpol',
                    'columns'     => [
                        'KEY','COD_AGENCIA','NUM_POLI','NRO_DOCUMTO','NOM_BENEF','CPOS_BENEF','DOM_BENEF','TEL_BENEF','TIPO_BENEF','COD_PROD','CLASE_APORTAN','TOTAL_TRABA','CLASE_AFILI','ACT_EMPRE','ARP','VALOR_APORTE','FECHA_VIG_POL','FEC_ANU_POL','COD_END','SUB_COD_END','FECHA_EMI_END','COD_USR','FECHA_ORIGEN','CANAL_DAVIVIR','CANAL_SECUNDAR','CLASE_RIESGO','ACTIVIDAD','CENTRALIZADO','CLIENTE_IMPORTANTE','CODIGO_PYME','CODIGO_ASESOR','COD_PRODUCTO','BEAN','ACTI_RIES','OCUPACION','LOCALIDAD','VIGENCIA','POLIC_VIG','ESTADO','COD_DPTO','COD_CIUDAD','DPTO','CIUDAD','WRES_DEP','WRES_INDE',
                    ],
                ],
                [
                    'code'        => 'DETTRA',
                    'name'        => 'detalle trabajadores',
                    'columns'     => [
                        'ACTI_RIES','CPOS_RIES','KEY','COD_RIES','NUM_POLI','NIT','TIPO_DOC','TIPO_COTIZANTE','FECHA_INI_COBERT','ESTADO','RIESGO','SEXO','FECH_NACIM','DESC_RIES','DIRE_RIES','CLAS_RIES','ACTI_DESC','COD_DPTO_TRABAJADOR','COD_CIUDAD_TRABAJADOR','DPTO_TRABAJADOR','CIUDAD_TRABAJADOR','BEAN','NRO_DOCUMTO','CPOS_BENEF','NOM_BENEF','ACTIVIDAD_EMPRESA','ESTADO_EMPRESA','SALARIO','RANGO_SALARIO','EDAD','RANGO_EDAD','COD_DPTO_EMPRESA','COD_CIUDAD_EMPRESA','DPTO_EMPRESA','CIUDAD_EMPRESA','CIIU','Grupo_Actual','Grupo_Actual_cod','Sector_Fasecolda',
                    ],
                ],
                [
                    'code'        => 'DIRMIN',
                    'name'        => 'directorio de ministerios',
                    'columns'     => [
                        'CIUDAD','DIRECION TERRITORIAL','NOMBRE DIRECTOR:','DIRECCION SEDE:','CORREO ELECTRONICO',
                    ],
                ],
                [
                    'code'        => 'ARCTOT',
                    'name'        => 'archivos totales',
                    'columns'     => [
                        'NIT','NOMBRE','PERIODO','POLIZA','TOTAL_DOC','VALOR_DOC','DOC_COTZ','TOTAL_ANP','VALOR_ANP','TOTAL_PNA','VALOR_PNA','TOTAL_DIF_BASE','VALORDIF_BASE','TOTAL_DIA_0','VALOR_DIA_0','TOTAL_PLANILLA<0','TOTAL_TRABAJADORES_PERIODO','TOTAL_TRABAJADORES_VIGENTES',
                    ],
                ],
                [
                    'code'        => 'DEC3033',
                    'name'        => 'decreto 3033',
                    'columns'     => [
                        'TIPO_ADMINISTRADORA','COD_ADMINISTRADORA','NOMBRE_ADMINISTRADORA','TIPO_DOCUMENTO_APORTANTE','NUMERO_ APORTANTE','RAZON_SOCIAL_APORTANTE','ID_DEPARTAMENTO','ID_MUNICIPIO','DIRECCION','TIPO_DOCUMENTO_COTIZANTE','NUMERO_ COTIZANTE','CONCEPTO','ANIO_INICIO','MES_INICIO','ANIO_FINAL','MES_FINAL','VALOR_CONCEPTO','TIPO_DE_ACCION','FECHA_ACCION','DESCRIPCIÓN_CONCEPTO',
                    ],
                ],
                [
                    'code'        => 'ESCUIN',
                    'name'        => 'estados de cuenta por incosistencia',
                    'columns'     => [
                        '#','Mes','Nit','Razón Social','Valor Estado de Cuenta','Valor Último estado de cuenta','Caso','Analista','Estado','Observación',
                    ],
                ],
                [
                    'code'        => 'BASACT',
                    'name'        => 'Base activos',
                    'columns'     => [
                        'RAMO','NUM_POLI','NIT_EMPRESA','NOMBRE_EMPRESA','CODIGO_DEPARTAMENTO_EMPRESA','CODIGO_MUNICIPIO_EMPRESA','CODIGO_CENTRO_TRABAJO_EMPRESA','NOMBRE_CENTRO_TRABAJO_EMPRESA','RIESGO_EMPRESA','CODIGO_ACTIVIDAD_ECONOMICA_EMPRESA','NOMBRE_ACTIVIDAD_ECONOMICA_EMPRESA','DIRECCION_EMPRESA','TEL_EMPRESA','CORREO_EMPRESA','TIPO_ID_TRABAJADOR','IDENTIFICACION_TRABAJADOR','1_NOMBRE_TRABAJADOR','2_NOMBRE_TRABAJADOR','1_APELLIDO_TRABAJADOR','2_APELLIDO_TRABAJADOR','NOMBRE COMPLETO','EDAD','SEXO','CODIGO_CENTRO_TRABAJO_TRABAJADOR','NOMBRE_CENTRO_TRABAJO_TRABAJADOR','DEPARTAMENTO_CENTRO_TRABAJO_TRABAJADOR','CIUDAD_CENTRO_TRABAJO_TRABAJADOR','RIESGO_CENTRO_TRABAJO','CODIGO_ACTIVIDAD_ECONOMICA_CENTRO_TRABAJO_TRABAJADOR','NOMBRE_ACTIVIDAD_ECONOMICA_CENTRO_DE_TRABAJO_TRABAJADOR','DIRECCION_TRABAJADOR','TEL_TRABAJADOR','CORREO_TRABAJADOR','SALARIO_TRABAJADOR','CARGO_TRABAJADOR','TIPO_COTIZANTE','FECHA_CREACION','FECHA_INI_COBERT','FECHA_RETIRO','FECH_NACIM','FECHA_REPORTE','EPS','FONDO DE PENSIONES','ESTADO(ING-RET)','BEAN(SI-NO)',
                    ],
                ],                
            ];

            foreach ($catalog as $def) {
                $columns = $def['columns'] ?? [];
                $numColumns = count($columns);
                $code = $def['code'];
                $name = $def['name'];
                unset($def['columns']);

                $source = NoticeDataSource::updateOrCreate(
                    ['code' => $def['code']],
                    ['name' => $def['name'], 'num_columns' => $numColumns]
                );

                $rows = array_map(function (string $colName) use ($source, $now) {
                    return [
                        'notice_data_source_id' => $source->id,
                        'column_name'           => $colName,
                        'created_at'            => $now,
                        'updated_at'            => $now,
                    ];
                }, $columns);

                NoticeDataSourceColumn::upsert(
                    $rows,
                    ['notice_data_source_id', 'column_name'],
                    ['updated_at']
                );

                NoticeDataSourceColumn::where('notice_data_source_id', $source->id)
                    ->whereNotIn('column_name', $columns)
                    ->delete();
            }
        });
    }
}
