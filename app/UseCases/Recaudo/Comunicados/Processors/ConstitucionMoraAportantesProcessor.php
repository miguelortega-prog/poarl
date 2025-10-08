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
// use App\UseCases\Recaudo\Comunicados\Steps\CleanupDataSourcesStep;
use App\UseCases\Recaudo\Comunicados\Steps\CountDettraWorkersAndUpdateBascarStep;
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
use App\UseCases\Recaudo\Comunicados\Steps\RemoveCrossedBascarRecordsStep;
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
        // private readonly CleanupDataSourcesStep $cleanupDataSourcesStep,
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
     * - LoadCsvDataSourcesJob: cargó BASCAR, BAPRPO, DATPOL
     * - LoadExcelWithCopyJob (x3): convirtió Excel a CSV y cargó DETTRA, PAGAPL, PAGPLA
     *
     * Este procesador SOLO realiza:
     * - PASO 1: Validar que los datos se cargaron correctamente
     * - PASO 2: Sanitizar campos numéricos (formato europeo → estándar)
     * - PASOS 3+: Transformaciones SQL (filtros, cruces, generación de archivos)
     *
     * @return array<int, \App\Contracts\Recaudo\Comunicados\ProcessingStepInterface>
     */
    protected function defineSteps(): array
    {
        return [
            // === FASE 1: VALIDACIÓN DE DATOS CARGADOS ===

            // Paso 1: Validar integridad de datos en BD
            // Verifica que los jobs previos cargaron correctamente:
            // - BASCAR, BAPRPO, DATPOL (LoadCsvDataSourcesJob)
            // - DETTRA, PAGAPL, PAGPLA (LoadExcelWithCopyJob)
            $this->validateDataStep,

            // Paso 2: Sanitizar campos numéricos (formato europeo → estándar)
            // Limpia campos numéricos que vienen con separadores europeos:
            // - Entrada: "1.234.567,89" (punto = miles, coma = decimal)
            // - Salida: "1234567.89" (sin separador de miles, punto = decimal)
            // Actualmente sanitiza: BASCAR.valor_total_fact
            $this->sanitizeNumericFieldsStep,

            // === FASE 2: FILTRADO DE DATOS POR PERIODO ===

            // Paso 3: Filtrar datos por periodo del run
            // - Si periodo = "Todos Los Periodos": No filtra nada
            // - Si periodo = YYYYMM: Filtra DETTRA por FECHA_INICIO_VIG
            $this->filterDataByPeriodStep,

            // === FASE 3: TRANSFORMACIÓN Y CRUCE DE DATOS SQL ===

            // Paso 4: Generar llaves compuestas en BASCAR (SQL UPDATE)
            $this->generateBascarKeysStep,

            // Paso 5: Generar llaves compuestas en PAGAPL (SQL UPDATE)
            $this->generatePagaplKeysStep,

            // Paso 6: Cruzar BASCAR con PAGAPL y generar archivo de excluidos (SQL + tabla temporal)
            $this->crossBascarPagaplStep,

            // Paso 7: Eliminar de BASCAR los registros que cruzaron con PAGAPL (SQL DELETE)
            $this->removeCrossedBascarStep,

            // Paso 8: Identificar PSI (Póliza de Seguro Independiente)
            // Cruza BASCAR.NUM_TOMADOR con BAPRPO.tomador para obtener pol_independiente
            $this->identifyPsiStep,

            // Paso 9: Excluir PSI Persona Jurídica (9 dígitos)
            // Excluye registros con PSI='S' y NUM_TOMADOR de 9 dígitos
            // Los agrega al archivo de excluidos y los elimina de BASCAR
            $this->excludePsiPersonaJuridicaStep,

            // Paso 10: Contar trabajadores de DETTRA y actualizar BASCAR (SQL UPDATE)
            $this->countDettraWorkersStep,

            // Paso 11: Crear base de trabajadores activos (CSV detalle)
            // Cruza DETTRA.NRO_DOCUMTO con BASCAR.NUM_TOMADOR
            // Genera archivo detalle_trabajadores{run_id}.csv
            $this->crearBaseTrabajadoresActivosStep,

            // Paso 12: Agregar BASCAR sin trabajadores al detalle
            // Filtra BASCAR con observacion_trabajadores = 'Sin trabajadores activos'
            // Los agrega al archivo detalle_trabajadores{run_id}.csv con valores por defecto
            $this->appendBascarSinTrabajadoresStep,

            // Paso 13: Agregar código de ciudad y departamento a BASCAR
            // Cruza BASCAR.NUM_TOMADOR con DATPOL.NRO_DOCUMTO
            // Concatena DATPOL.cod_dpto + DATPOL.cod_ciudad → BASCAR.city_code
            // Copia DATPOL.cod_dpto → BASCAR.departamento
            $this->addCityCodeToBascarStep,

            // Paso 14: Agregar email a BASCAR desde PAGPLA
            // Cruza BASCAR.NUM_TOMADOR con PAGPLA.identificacion_aportante
            // Obtiene PAGPLA.email y valida formato, establece NULL para emails inválidos
            $this->addEmailToBascarStep,

            // Paso 15: Agregar DIVIPOLA y dirección a BASCAR desde PAGPLA
            // Cruza BASCAR.NUM_TOMADOR con PAGPLA.identificacion_aportante
            // Concatena codigo_departamento (LPAD 2) + codigo_ciudad (LPAD 3) → divipola
            // Obtiene direccion desde PAGPLA
            $this->addDivipolaToBascarStep,

            // Paso 16: Definir tipo de envío de correspondencia
            // Si tiene email → tipo_de_envio = "Correo"
            // Si NO tiene email PERO tiene direccion → tipo_de_envio = "Fisico"
            // Si no tiene ninguno → tipo_de_envio = NULL
            $this->defineTipoDeEnvioStep,

            // Paso 17: Excluir registros sin datos de contacto
            // Filtra registros con tipo_de_envio IS NULL
            // Los agrega al archivo de excluidos con motivo "Sin datos de contacto"
            // Elimina estos registros de BASCAR
            $this->excludeSinDatosContactoStep,

            // Paso 18: Agregar consecutivo a BASCAR
            // Genera consecutivo con formato: CON-{IDENT_ASEGURADO}-{NUM_TOMADOR}-{YYYYMMDD}-{SECUENCIA}
            // SECUENCIA: número secuencial de 5 dígitos (00001, 00002, ...)
            $this->addSequenceStep,

            // Paso 19: Exportar BASCAR a Excel 97 (.xls)
            // Genera archivo(s) Excel con 2 hojas:
            // - Hoja 1: Data de BASCAR (16 columnas)
            // - Hoja 2: Data de detalle_trabajadores (CSV)
            // Si supera 65,535 filas, crea archivos adicionales (_parte2, _parte3, etc.)
            $this->exportBascarToExcelStep,

            // Paso 20: Marcar run como completado
            // Cambia el estado del run a 'completed'
            // Registra la duración total del procesamiento
            $this->markRunAsCompletedStep,

            // TODO: Paso 21 (COMENTADO): Limpiar datos de tablas data_source_
            // Descomentar cuando sea pertinente eliminar los datos después del procesamiento
            // Elimina todos los registros de data_source_* para liberar espacio
            // $this->cleanupDataSourcesStep,
        ];
    }
}
