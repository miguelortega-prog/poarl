<?php

declare(strict_types=1);

namespace App\UseCases\Recaudo\Comunicados\Processors;

use App\Models\CollectionNoticeRun;
use App\Services\Recaudo\Comunicados\BaseCollectionNoticeProcessor;
use App\Services\Recaudo\DataSourceTableManager;
use App\UseCases\Recaudo\Comunicados\Steps\AddCityCodeToBascarStep;
use App\UseCases\Recaudo\Comunicados\Steps\AddDivipolaToBascarStep;
use App\UseCases\Recaudo\Comunicados\Steps\AddEmailToBascarStep;
use App\UseCases\Recaudo\Comunicados\Steps\AddSequenceStep;
use App\UseCases\Recaudo\Comunicados\Steps\AppendBascarSinTrabajadoresStep;
use App\UseCases\Recaudo\Comunicados\Steps\CleanupDataSourcesStep;
use App\UseCases\Recaudo\Comunicados\Steps\CountDettraWorkersAndUpdateBascarStep;
use App\UseCases\Recaudo\Comunicados\Steps\CreateBascarIndexesStep;
use App\UseCases\Recaudo\Comunicados\Steps\CrearBaseTrabajadoresActivosStep;
use App\UseCases\Recaudo\Comunicados\Steps\CrossBascarWithPagaplStep;
use App\UseCases\Recaudo\Comunicados\Steps\DefineTipoDeEnvioStep;
use App\UseCases\Recaudo\Comunicados\Steps\ExcludePsiPersonaJuridicaStep;
use App\UseCases\Recaudo\Comunicados\Steps\ExcludeSinDatosContactoStep;
use App\UseCases\Recaudo\Comunicados\Steps\ExportBascarToExcelStep;
use App\UseCases\Recaudo\Comunicados\Steps\FilterDataByPeriodStep;
use App\UseCases\Recaudo\Comunicados\Steps\GenerateBascarCompositeKeyStep;
use App\UseCases\Recaudo\Comunicados\Steps\GeneratePagaplCompositeKeyStep;
use App\UseCases\Recaudo\Comunicados\Steps\IdentifyPsiStep;
use App\UseCases\Recaudo\Comunicados\Steps\MarkRunAsCompletedStep;
use App\UseCases\Recaudo\Comunicados\Steps\NormalizeDateFormatsStep;
use App\UseCases\Recaudo\Comunicados\Steps\RemoveCrossedBascarRecordsStep;
use App\UseCases\Recaudo\Comunicados\Steps\SanitizeCiuTomStep;
use App\UseCases\Recaudo\Comunicados\Steps\SanitizeNumericFieldsStep;
use App\UseCases\Recaudo\Comunicados\Steps\ValidateDataIntegrityStep;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;

/**
 * Procesador para el tipo de comunicado "CONSTITUCIÓN EN MORA - APORTANTES".
 *
 * Este comunicado realiza los siguientes cruces de datos:
 * 1. Lee base-cartera (BASCAR) y filtra por periodo
 * 2. Cruza con PAGAPL (pagos aplicados)
 * 3. Cruza con BAPRPO (base producción por póliza)
 * 4. Cruza con PAGPLA (pagos planilla)
 * 5. Cruza con DATPOL
 * 6. Cruza con DETTRA (detalle trabajadores)
 * 7. Genera archivos de salida
 *
 * Data sources requeridos:
 * - BASCAR: Base de cartera
 * - PAGAPL: Pagos aplicados
 * - BAPRPO: Base producción por póliza
 * - PAGPLA: Pagos planilla
 * - DATPOL: Datos de póliza
 * - DETTRA: Detalle de trabajadores
 */
final class ConstitucionMoraAportantesProcessor extends BaseCollectionNoticeProcessor
{
    public function __construct(
        DataSourceTableManager $tableManager,
        FilesystemFactory $filesystem,
        private readonly ValidateDataIntegrityStep $validateDataStep,
        private readonly SanitizeNumericFieldsStep $sanitizeNumericFieldsStep,
        private readonly SanitizeCiuTomStep $sanitizeCiuTomStep,
        private readonly CreateBascarIndexesStep $createBascarIndexesStep,
        private readonly NormalizeDateFormatsStep $normalizeDateFormatsStep,
        private readonly FilterDataByPeriodStep $filterDataByPeriodStep,
        private readonly GenerateBascarCompositeKeyStep $generateBascarKeysStep,
        private readonly GeneratePagaplCompositeKeyStep $generatePagaplKeysStep,
        private readonly CrossBascarWithPagaplStep $crossBascarPagaplStep,
        private readonly RemoveCrossedBascarRecordsStep $removeCrossedBascarStep,
        private readonly IdentifyPsiStep $identifyPsiStep,
        private readonly ExcludePsiPersonaJuridicaStep $excludePsiPersonaJuridicaStep,
        private readonly CountDettraWorkersAndUpdateBascarStep $countDettraWorkersStep,
        private readonly CrearBaseTrabajadoresActivosStep $crearBaseTrabajadoresActivosStep,
        private readonly AppendBascarSinTrabajadoresStep $appendBascarSinTrabajadoresStep,
        private readonly AddCityCodeToBascarStep $addCityCodeToBascarStep,
        private readonly AddEmailToBascarStep $addEmailToBascarStep,
        private readonly AddDivipolaToBascarStep $addDivipolaToBascarStep,
        private readonly DefineTipoDeEnvioStep $defineTipoDeEnvioStep,
        private readonly ExcludeSinDatosContactoStep $excludeSinDatosContactoStep,
        private readonly AddSequenceStep $addSequenceStep,
        private readonly ExportBascarToExcelStep $exportBascarToExcelStep,
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
        return 'Constitución en Mora - Aportantes';
    }

    /**
     * @param CollectionNoticeRun $run
     *
     * @return bool
     */
    public function canProcess(CollectionNoticeRun $run): bool
    {
        // Validar que el tipo de comunicado sea el correcto
        if ($run->type?->processor_type !== 'constitucion_mora_aportantes') {
            return false;
        }

        // Validar que todos los data sources requeridos estén presentes
        $requiredDataSources = ['BASCAR', 'PAGAPL', 'BAPRPO', 'PAGPLA', 'DATPOL', 'DETTRA'];
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
     * - LoadExcelWithCopyJob: convirtió Excel a CSV y cargó BASCAR, DETTRA, PAGAPL, PAGPLA
     *   (El binario Go excel_to_csv RESPETA el formato original del Excel)
     *   (Las fechas deben venir con año de 4 dígitos: D/M/YYYY o DD/MM/YYYY)
     * - LoadCsvDataSourcesJob: cargó BAPRPO, DATPOL
     *
     * Este procesador SOLO realiza:
     * - PASO 1: Validar que los datos se cargaron correctamente
     * - PASO 2: Preparar estructura BASCAR (columnas e índices)
     * - PASO 3: Sanitizar campos numéricos (formato europeo → estándar)
     * - PASO 4: Sanitizar CIU_TOM (convertir nombres de ciudades a códigos)
     * - PASOS 5+: Filtrado por periodo y transformaciones SQL
     *
     * @return array<int, \App\Contracts\Recaudo\Comunicados\ProcessingStepInterface>
     */
    protected function defineSteps(): array
    {
        return [
            // === FASE 1: VALIDACIÓN Y PREPARACIÓN ===

            // Paso 1: Validar integridad de datos en BD
            // Verifica que los jobs previos cargaron correctamente:
            // - BASCAR, BAPRPO, DATPOL (LoadCsvDataSourcesJob)
            // - DETTRA, PAGAPL, PAGPLA (LoadExcelWithCopyJob)
            $this->validateDataStep,

            // Paso 2: Preparar estructura de BASCAR (columnas e índices)
            // IMPORTANTE: Este paso crea TODAS las columnas e índices necesarios de forma idempotente
            // Esto garantiza que los pasos posteriores puedan asumir que la estructura ya está lista
            // Columnas creadas: composite_key, tipo_de_envio, consecutivo, email, divipola, direccion,
            //                   city_code, departamento, cantidad_trabajadores, observacion_trabajadores, psi
            // Índices creados: idx_bascar_tipo_envio, idx_bascar_num_tomador, idx_bascar_run_id,
            //                  idx_bascar_run_num_tomador, idx_bascar_psi, idx_bascar_composite_key
            $this->createBascarIndexesStep,

            // === FASE 2: SANITIZACIÓN DE DATOS ===

            // Paso 2.5: Normalizar formatos de fechas desde Excel
            // PROBLEMA: La librería excelize de Go formatea automáticamente las fechas de Excel a formato MM-DD-YY.
            // - Excel guarda fechas como números seriales
            // - excelize las convierte a texto pero usa formato estadounidense con año corto
            // - Ejemplo: 6/01/2024 → 09-01-25
            // SOLUCIÓN: Este step convierte el formato de vuelta a D/M/YYYY.
            // Conversiones: MM-DD-YY → D/M/YYYY (09-01-25 → 9/1/2025)
            // Tablas: data_source_bascar (fecha_inicio_vig, fecha_finalizacion, fecha_expedicion)
            //         data_source_dettra (fecha_ini_cobert, fech_nacim)
            $this->normalizeDateFormatsStep,

            // === FASE 3: FILTRADO DE DATOS POR PERIODO ===

            // Paso 3: Filtrar datos por periodo del run
            // - Si periodo = "Todos Los Periodos": No filtra nada
            // - Si periodo = YYYYMM: Filtra BASCAR por FECHA_INICIO_VIG
            //   IMPORTANTE: Las fechas DEBEN venir con año de 4 dígitos del Excel original (D/M/YYYY o DD/MM/YYYY)
            //   El binario Go excel_to_csv respeta el formato original sin modificarlo
            $this->filterDataByPeriodStep,

            // === FASE 4: SANITIZACIÓN DE DATOS (DESPUÉS DE FILTRAR) ===

            // Paso 4: Sanitizar CIU_TOM (convertir nombres de ciudades a códigos)
            // Algunos registros tienen el NOMBRE de la ciudad en lugar del código DIVIPOLA
            // Busca en tabla city_depto y actualiza si hay coincidencia única:
            // - "MEDELLIN" → busca en city_depto.name_city
            // - Si encuentra 1 coincidencia → actualiza CIU_TOM = "05001"
            // - Si encuentra 0 o múltiples coincidencias → deja vacío (ambiguo)
            // IMPORTANTE: Se ejecuta DESPUÉS del filtrado por periodo para procesar menos registros
            $this->sanitizeCiuTomStep,

            // Paso 5: Sanitizar campos numéricos (formato europeo → estándar)
            // Limpia campos numéricos que vienen con separadores europeos:
            // - Entrada: "1.234.567,89" (punto = miles, coma = decimal)
            // - Salida: "1234567.89" (sin separador de miles, punto = decimal)
            // Procesa por chunks de 1000 registros en PHP para validación individual
            // IMPORTANTE: Se ejecuta DESPUÉS del filtrado para procesar menos registros
            // Actualmente sanitiza: BASCAR.valor_total_fact
            $this->sanitizeNumericFieldsStep,

            // === FASE 4: TRANSFORMACIÓN Y CRUCE DE DATOS SQL ===

            // Paso 6: Generar llaves compuestas en BASCAR (SQL UPDATE)
            $this->generateBascarKeysStep,

            // Paso 7: Generar llaves compuestas en PAGAPL (SQL UPDATE)
            $this->generatePagaplKeysStep,

            // Paso 8: Cruzar BASCAR con PAGAPL y generar archivo de excluidos (SQL + tabla temporal)
            $this->crossBascarPagaplStep,

            // Paso 9: Eliminar de BASCAR los registros que cruzaron con PAGAPL (SQL DELETE)
            $this->removeCrossedBascarStep,

            // Paso 10: Identificar PSI (Póliza de Seguro Independiente)
            // Cruza BASCAR.NUM_TOMADOR con BAPRPO.tomador para obtener pol_independiente
            $this->identifyPsiStep,

            // Paso 11: Excluir PSI Persona Jurídica (9 dígitos)
            // Excluye registros con PSI='S' y NUM_TOMADOR de 9 dígitos
            // Los agrega al archivo de excluidos y los elimina de BASCAR
            $this->excludePsiPersonaJuridicaStep,

            // Paso 12: Contar trabajadores de DETTRA y actualizar BASCAR (SQL UPDATE)
            $this->countDettraWorkersStep,

            // Paso 13: Crear base de trabajadores activos (CSV detalle)
            // Cruza DETTRA.NRO_DOCUMTO con BASCAR.NUM_TOMADOR
            // Genera archivo detalle_trabajadores{run_id}.csv
            $this->crearBaseTrabajadoresActivosStep,

            // Paso 14: Agregar BASCAR sin trabajadores al detalle
            // Filtra BASCAR con observacion_trabajadores = 'Sin trabajadores activos'
            // Los agrega al archivo detalle_trabajadores{run_id}.csv con valores por defecto
            $this->appendBascarSinTrabajadoresStep,

            // Paso 15: Agregar código de ciudad y departamento a BASCAR
            // Cruza BASCAR.NUM_TOMADOR con DATPOL.NRO_DOCUMTO
            // Concatena DATPOL.cod_dpto + DATPOL.cod_ciudad → BASCAR.city_code
            // Copia DATPOL.cod_dpto → BASCAR.departamento
            $this->addCityCodeToBascarStep,

            // Paso 16: Agregar email a BASCAR desde PAGPLA
            // Cruza BASCAR.NUM_TOMADOR con PAGPLA.identificacion_aportante
            // Obtiene PAGPLA.email y valida formato, establece NULL para emails inválidos
            $this->addEmailToBascarStep,

            // Paso 17: Agregar DIVIPOLA y dirección a BASCAR desde PAGPLA
            // Cruza BASCAR.NUM_TOMADOR con PAGPLA.identificacion_aportante
            // Concatena codigo_departamento (LPAD 2) + codigo_ciudad (LPAD 3) → divipola
            // Obtiene direccion desde PAGPLA
            $this->addDivipolaToBascarStep,

            // Paso 18: Definir tipo de envío de correspondencia
            // Si tiene email → tipo_de_envio = "Correo"
            // Si NO tiene email PERO tiene direccion → tipo_de_envio = "Fisico"
            // Si no tiene ninguno → tipo_de_envio = NULL
            $this->defineTipoDeEnvioStep,

            // Paso 19: Excluir registros sin datos de contacto
            // Filtra registros con tipo_de_envio IS NULL
            // Los agrega al archivo de excluidos con motivo "Sin datos de contacto"
            // Elimina estos registros de BASCAR
            $this->excludeSinDatosContactoStep,

            // Paso 20: Agregar consecutivo a BASCAR
            // Genera consecutivo con formato: CON-{IDENT_ASEGURADO}-{NUM_TOMADOR}-{YYYYMMDD}-{SECUENCIA}
            // SECUENCIA: número secuencial de 5 dígitos (00001, 00002, ...)
            $this->addSequenceStep,

            // Paso 21: Exportar BASCAR a Excel 97 (.xls)
            // Genera archivo(s) Excel con 2 hojas:
            // - Hoja 1 (Empresas): Data de BASCAR (16 columnas)
            // - Hoja 2 (Expuestos): Data de detalle_trabajadores + TIPO DE ENVIO
            // Si supera 65,535 filas, crea archivos adicionales (_parte2, _parte3, etc.)
            $this->exportBascarToExcelStep,

            // Paso 22: Marcar run como completado
            // Cambia el estado del run a 'completed'
            // Registra la duración total del procesamiento
            $this->markRunAsCompletedStep,

            // Paso 23: Limpiar datos de tablas data_source_
            // Elimina todos los registros de data_source_* para este run_id
            // para liberar espacio en disco después del procesamiento exitoso
            $this->cleanupDataSourcesStep,
        ];
    }
}
