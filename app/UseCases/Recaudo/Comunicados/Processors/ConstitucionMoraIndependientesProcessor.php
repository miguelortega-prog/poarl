<?php

declare(strict_types=1);

namespace App\UseCases\Recaudo\Comunicados\Processors;

use App\Models\CollectionNoticeRun;
use App\Services\Recaudo\Comunicados\BaseCollectionNoticeProcessor;
use App\Services\Recaudo\DataSourceTableManager;
use App\UseCases\Recaudo\Comunicados\Steps\AddCityCodeToDettraStep;
use App\UseCases\Recaudo\Comunicados\Steps\AddEmailAndAddressFromPagplaStep;
use App\UseCases\Recaudo\Comunicados\Steps\AddEmailAndAddressToDettraStep;
use App\UseCases\Recaudo\Comunicados\Steps\AddNamesToDettraFromBasactStep;
use App\UseCases\Recaudo\Comunicados\Steps\CleanupDataSourcesStep;
use App\UseCases\Recaudo\Comunicados\Steps\CreateDettraIndexesStep;
use App\UseCases\Recaudo\Comunicados\Steps\CreatePagaplIndexesStep;
use App\UseCases\Recaudo\Comunicados\Steps\CreatePaglogIndexesStep;
use App\UseCases\Recaudo\Comunicados\Steps\CrossDettraWithPagaplStep;
use App\UseCases\Recaudo\Comunicados\Steps\CrossDettraWithPaglogDvStep;
use App\UseCases\Recaudo\Comunicados\Steps\CrossDettraWithPaglogStep;
use App\UseCases\Recaudo\Comunicados\Steps\ExportAndRemoveDettraWithoutContactDataStep;
use App\UseCases\Recaudo\Comunicados\Steps\ExportAndRemoveDettraWithoutNamesStep;
use App\UseCases\Recaudo\Comunicados\Steps\ExportDettraToExcelStep;
use App\UseCases\Recaudo\Comunicados\Steps\ExportExcludedDettraRecordsStep;
use App\UseCases\Recaudo\Comunicados\Steps\FilterDataSourcesByPeriodStep;
use App\UseCases\Recaudo\Comunicados\Steps\RemoveCrossedDettraRecordsStep;
use App\UseCases\Recaudo\Comunicados\Steps\FilterDettraByTipoCotizanteStep;
use App\UseCases\Recaudo\Comunicados\Steps\DefineTipoDeEnvioDettraStep;
use App\UseCases\Recaudo\Comunicados\Steps\SanitizeTipoDocFieldStep;
use App\UseCases\Recaudo\Comunicados\Steps\GenerateConsecutivosStep;
use App\UseCases\Recaudo\Comunicados\Steps\MarkRunAsCompletedStep;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;

/**
 * Procesador para el tipo de comunicado "CONSTITUCIÓN EN MORA - INDEPENDIENTES".
 *
 * Este comunicado procesa trabajadores independientes con póliza independiente.
 *
 * Pipeline:
 * 1. Filtrar DETTRA por tipo_cotizante y riesgo (independientes válidos)
 * 2. Filtrar PAGAPL y PAGLOG por periodo del run (optimización)
 * 3. Crear columnas e índices en DETTRA (composite_key, cruces, observaciones, nombres, codigo_ciudad, correo, direccion)
 * 4. Crear columnas e índices en PAGAPL (composite_key)
 * 5. Crear columnas e índices en PAGLOG (nit_periodo, composite_key_dv)
 * 6. Cruzar DETTRA con PAGAPL (identificar pagos aplicados)
 * 7. Cruzar DETTRA con PAGLOG sin DV (identificar pagos en log bancario)
 * 8. Cruzar DETTRA con PAGLOG con DV (identificar pagos en log bancario - alternativo)
 * 9. Exportar registros excluidos (trabajadores que cruzaron con recaudo)
 * 10. Eliminar de DETTRA los registros que cruzaron (ya exportados)
 * 11. Agregar nombres completos desde BASACT (nombre + apellidos)
 * 12. Exportar y eliminar registros sin nombres (no cruzaron con BASACT)
 * 13. Agregar código de ciudad (DIVIPOLA) a DETTRA
 * 14. Agregar correo y dirección válidos desde BASACT
 * 15. Agregar correo y dirección desde PAGAPL (fallback para registros sin datos)
 * 16. Exportar y eliminar registros sin datos de contacto (sin correo NI dirección)
 * 17. Definir tipo de envío (CORREO o FISICO)
 * 18. Sanitizar campo tipo_doc (C→CC, E→CE, F→PE, T→TI)
 * 19. Generar consecutivos únicos para cada comunicado
 * 20. Exportar DETTRA a Excel 97 (2 hojas: Independientes y Trabajadores Expuestos)
 * 21. Marcar run como completado
 * 22. Limpiar datos de data sources
 *
 * Data sources requeridos:
 * - BASACT: Base de activos (trabajadores independientes)
 * - PAGLOG: Pagos log bancario
 * - PAGPLA: Pagos planilla
 * - DETTRA: Detalle trabajadores (filtrado + llave compuesta)
 */
final class ConstitucionMoraIndependientesProcessor extends BaseCollectionNoticeProcessor
{
    public function __construct(
        DataSourceTableManager $tableManager,
        FilesystemFactory $filesystem,
        private readonly FilterDettraByTipoCotizanteStep $filterDettraByTipoCotizanteStep,
        private readonly FilterDataSourcesByPeriodStep $filterDataSourcesByPeriodStep,
        private readonly CreateDettraIndexesStep $createDettraIndexesStep,
        private readonly CreatePagaplIndexesStep $createPagaplIndexesStep,
        private readonly CreatePaglogIndexesStep $createPaglogIndexesStep,
        private readonly CrossDettraWithPagaplStep $crossDettraWithPagaplStep,
        private readonly CrossDettraWithPaglogStep $crossDettraWithPaglogStep,
        private readonly CrossDettraWithPaglogDvStep $crossDettraWithPaglogDvStep,
        private readonly ExportExcludedDettraRecordsStep $exportExcludedDettraRecordsStep,
        private readonly RemoveCrossedDettraRecordsStep $removeCrossedDettraRecordsStep,
        private readonly AddNamesToDettraFromBasactStep $addNamesToDettraFromBasactStep,
        private readonly ExportAndRemoveDettraWithoutNamesStep $exportAndRemoveDettraWithoutNamesStep,
        private readonly AddCityCodeToDettraStep $addCityCodeToDettraStep,
        private readonly AddEmailAndAddressToDettraStep $addEmailAndAddressToDettraStep,
        private readonly AddEmailAndAddressFromPagplaStep $addEmailAndAddressFromPagplaStep,
        private readonly ExportAndRemoveDettraWithoutContactDataStep $exportAndRemoveDettraWithoutContactDataStep,
        private readonly DefineTipoDeEnvioDettraStep $defineTipoDeEnvioDettraStep,
        private readonly SanitizeTipoDocFieldStep $sanitizeTipoDocFieldStep,
        private readonly GenerateConsecutivosStep $generateConsecutivosStep,
        private readonly ExportDettraToExcelStep $exportDettraToExcelStep,
        private readonly MarkRunAsCompletedStep $markRunAsCompletedStep,
        private readonly CleanupDataSourcesStep $cleanupDataSourcesStep,
    ) {
        parent::__construct($tableManager, $filesystem);
        $this->initializeSteps();
    }

    /**
     * @return string
     */
    public function getName(): string
    {
        return 'Constitución en Mora - Independientes';
    }

    /**
     * @param CollectionNoticeRun $run
     *
     * @return bool
     */
    public function canProcess(CollectionNoticeRun $run): bool
    {
        // Validar que el tipo de comunicado sea el correcto
        if ($run->type?->processor_type !== 'constitucion_mora_independientes') {
            return false;
        }

        // Validar que todos los data sources requeridos estén presentes
        $requiredDataSources = ['BASACT', 'PAGLOG', 'PAGPLA', 'DETTRA'];
        $uploadedDataSources = $run->files->pluck('dataSource.code')->filter()->toArray();

        foreach ($requiredDataSources as $required) {
            if (!in_array($required, $uploadedDataSources, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Define los pasos del pipeline para este procesador.
     *
     * IMPORTANTE: Los datos ya fueron cargados por los jobs previos:
     * - LoadExcelWithCopyJob (x4): convirtió Excel a CSV y cargó BASACT, PAGLOG, PAGPLA, DETTRA
     *
     * Este procesador implementa cruces para identificar pagos:
     * - PASO 1: Filtrar DETTRA por tipo_cotizante y riesgo
     * - PASO 2: Filtrar PAGAPL y PAGLOG por periodo del run (optimización)
     * - PASO 3: Crear columnas e índices en DETTRA (composite_key, cruces, observaciones, nombres, codigo_ciudad, correo, direccion)
     * - PASO 4: Crear columnas e índices en PAGAPL (composite_key)
     * - PASO 5: Crear columnas e índices en PAGLOG (nit_periodo, composite_key_dv)
     * - PASO 6: Cruzar DETTRA con PAGAPL (identificar pagos aplicados)
     * - PASO 7: Cruzar DETTRA con PAGLOG sin DV (identificar pagos en log bancario)
     * - PASO 8: Cruzar DETTRA con PAGLOG con DV (identificar pagos en log bancario - alternativo)
     * - PASO 9: Exportar registros excluidos (trabajadores que cruzaron con recaudo)
     * - PASO 10: Eliminar de DETTRA los registros que cruzaron (ya exportados)
     * - PASO 11: Agregar nombres completos desde BASACT (nombre + apellidos)
     * - PASO 12: Exportar y eliminar registros sin nombres (no cruzaron con BASACT)
     * - PASO 13: Agregar código de ciudad (DIVIPOLA) a DETTRA
     * - PASO 14: Agregar correo y dirección válidos desde BASACT
     * - PASO 15: Agregar correo y dirección desde PAGAPL (fallback)
     * - PASO 16: Exportar y eliminar registros sin datos de contacto
     * - PASO 17: Definir tipo de envío (CORREO o FISICO)
     * - PASO 18: Sanitizar campo tipo_doc
     * - PASO 19: Generar consecutivos únicos
     * - PASO 20: Exportar DETTRA a Excel 97
     * - PASO 21: Marcar run como completado
     * - PASO 22: Limpiar datos de data sources
     *
     * @return array<int, \App\Contracts\Recaudo\Comunicados\ProcessingStepInterface>
     */
    protected function defineSteps(): array
    {
        return [
            // Paso 1: Filtrar DETTRA por tipo_cotizante y riesgo
            // Mantiene solo registros de trabajadores independientes válidos:
            // - tipo_cotizante IN ('3', '59') AND riesgo IN ('1', '2', '3')
            // - O tipo_cotizante = '16' (cualquier riesgo)
            // Elimina el resto de registros de DETTRA
            $this->filterDettraByTipoCotizanteStep,

            // Paso 2: Filtrar PAGAPL y PAGLOG por periodo del run
            // Optimización: Elimina registros con periodo diferente al del run
            // ya que estos nunca cruzarán en los pasos posteriores
            // Mejora performance y reduce volumen de datos
            $this->filterDataSourcesByPeriodStep,

            // Paso 3: Preparar estructura de DETTRA (columnas e índices)
            // Crea columnas: composite_key, cruce_pagapl, cruce_paglog, cruce_paglog_dv, observacion_trabajadores, nombres, codigo_ciudad, correo, direccion
            // Crea índices: idx_dettra_run_id, idx_dettra_nit, idx_dettra_composite_key, etc.
            // Genera composite_key = NIT + periodo del run
            $this->createDettraIndexesStep,

            // Paso 4: Preparar estructura de PAGAPL (columnas e índices)
            // Crea columna: composite_key
            // Crea índices: idx_pagapl_run_id, idx_pagapl_identifi, idx_pagapl_composite_key
            // Genera composite_key = Identifi + Periodo (de la tabla PAGAPL)
            $this->createPagaplIndexesStep,

            // Paso 5: Preparar estructura de PAGLOG (columnas e índices)
            // Crea columnas: nit_periodo, composite_key_dv
            // Crea índices: idx_paglog_run_id, idx_paglog_nit_empresa, etc.
            // Genera nit_periodo = NIT_EMPRESA + PERIODO_PAGO (sin DV)
            // Genera composite_key_dv = NIT_con_DV + PERIODO_PAGO (con DV calculado)
            $this->createPaglogIndexesStep,

            // Paso 6: Cruzar DETTRA con PAGAPL
            // Busca DETTRA.composite_key en PAGAPL.composite_key
            // Si cruza: marca cruce_pagapl y observacion = "Cruza con recaudo"
            // Identifica trabajadores que ya realizaron el pago (pagos aplicados)
            $this->crossDettraWithPagaplStep,

            // Paso 7: Cruzar DETTRA con PAGLOG (sin DV)
            // Busca DETTRA.composite_key en PAGLOG.nit_periodo
            // Si cruza: marca cruce_paglog y observacion = "Cruza con recaudo"
            // Identifica trabajadores con pagos registrados en log bancario
            $this->crossDettraWithPaglogStep,

            // Paso 8: Cruzar DETTRA con PAGLOG (con DV)
            // Busca DETTRA.composite_key en PAGLOG.composite_key_dv
            // Si cruza: marca cruce_paglog_dv y observacion = "Cruza con recaudo"
            // Cruce adicional para NITs almacenados con dígito de verificación
            $this->crossDettraWithPaglogDvStep,

            // Paso 9: Exportar registros excluidos a CSV
            // Genera archivo excluidos_{run_id}.csv con trabajadores que cruzaron
            // Formato: FECHA_CRUCE;NUMERO_ID_APORTANTE;PERIODO;TIPO_COMUNICADO;VALOR;MOTIVO_EXCLUSION
            // Solo incluye registros con observacion_trabajadores LIKE '%Cruza con recaudo%'
            $this->exportExcludedDettraRecordsStep,

            // Paso 10: Eliminar de DETTRA los registros que cruzaron
            // Elimina trabajadores con observacion_trabajadores LIKE '%Cruza con recaudo%'
            // Estos ya fueron exportados al archivo de excluidos
            // DETTRA quedará solo con trabajadores que SÍ deben recibir comunicado
            $this->removeCrossedDettraRecordsStep,

            // Paso 11: Agregar nombres completos desde BASACT
            // Cruza DETTRA.nit con BASACT.identificacion_trabajador
            // Concatena: 1_nombre + 2_nombre + 1_apellido + 2_apellido → DETTRA.nombres
            // Solo procesa trabajadores que quedan en DETTRA (en mora)
            $this->addNamesToDettraFromBasactStep,

            // Paso 12: Exportar y eliminar registros sin nombres
            // Identifica registros con nombres IS NULL o nombres = ''
            // Los agrega al archivo excluidos_{run_id}.csv con motivo "Sin Nombres"
            // Elimina estos registros de DETTRA (no se puede personalizar comunicado)
            $this->exportAndRemoveDettraWithoutNamesStep,

            // Paso 13: Agregar código de ciudad (DIVIPOLA) a DETTRA
            // Concatena cod_depto_empresa (2 dígitos) + cod_ciudad_empresa (3 dígitos)
            // Genera código DIVIPOLA completo de 5 dígitos
            // Ejemplo: Departamento "5" + Ciudad "1" → "05001" (Medellín, Antioquia)
            $this->addCityCodeToDettraStep,

            // Paso 14: Agregar correo y dirección válidos desde BASACT
            // Cruza DETTRA.nit con BASACT.identificacion_trabajador
            // Obtiene correo_trabajador (validado) y direccion_trabajador (validada)
            // Excluye correos de @segurosbolivar.com y direcciones inválidas
            $this->addEmailAndAddressToDettraStep,

            // Paso 15: Agregar correo y dirección desde PAGPLA (fallback)
            // Para registros que quedaron sin correo o dirección en BASACT
            // Cruza DETTRA.nit con PAGPLA.identificacion_aportante
            // Busca el PRIMER registro de PAGPLA (cualquier periodo) que cumpla validaciones
            // Si encuentra dirección válida, también actualiza codigo_ciudad (DIVIPOLA)
            $this->addEmailAndAddressFromPagplaStep,

            // Paso 16: Exportar y eliminar registros sin datos de contacto
            // Identifica registros con correo IS NULL AND direccion IS NULL
            // Los agrega al archivo excluidos_{run_id}.csv con motivo "Sin datos de contacto"
            // Elimina estos registros de DETTRA (no hay forma de contactarlos)
            // Quedan solo trabajadores con al menos un medio de contacto
            $this->exportAndRemoveDettraWithoutContactDataStep,

            // Paso 17: Definir tipo de envío para comunicados
            // Asigna tipo_de_envio según disponibilidad de datos de contacto:
            // - CORREO: registros con correo IS NOT NULL
            // - FISICO: registros con correo IS NULL AND direccion IS NOT NULL
            $this->defineTipoDeEnvioDettraStep,

            // Paso 18: Sanitizar campo tipo_doc
            // Normaliza valores de tipo_doc a códigos estándar:
            // C→CC (Cédula Ciudadanía), E→CE (Cédula Extranjería)
            // F→PE (Permiso Especial), T→TI (Tarjeta Identidad)
            $this->sanitizeTipoDocFieldStep,

            // Paso 19: Generar consecutivos únicos para comunicados
            // Formato: CON-{TIPO_DOC}-{NIT}-{FECHA}-{SERIAL}
            // Ejemplo: CON-CC-1024546789-20251003-00001
            // Serial ascendente de 5 dígitos (00001 hasta 99999)
            $this->generateConsecutivosStep,

            // Paso 20: Exportar DETTRA a Excel 97 (.xls)
            // Genera archivo con 2 hojas:
            // - Hoja 1 (Independientes): Datos del trabajador con cálculo de valor por tasa de riesgo
            // - Hoja 2 (Trabajadores Expuestos): Detalle con riesgo en números romanos
            // Nombre: Constitucion_en_mora_independientes_{periodo}.xls
            $this->exportDettraToExcelStep,

            // Paso 21: Marcar run como completado
            // Cambia el estado del run a 'completed'
            // Registra la duración total del procesamiento
            $this->markRunAsCompletedStep,

            // Paso 22: Limpiar datos de tablas data_source_
            // Elimina todos los registros de data_source_* para este run_id
            // para liberar espacio en disco después del procesamiento exitoso
            $this->cleanupDataSourcesStep,
        ];
    }
}
