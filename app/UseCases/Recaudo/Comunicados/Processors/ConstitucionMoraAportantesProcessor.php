<?php

declare(strict_types=1);

namespace App\UseCases\Recaudo\Comunicados\Processors;

use App\Models\CollectionNoticeRun;
use App\Services\Recaudo\Comunicados\BaseCollectionNoticeProcessor;
use App\Services\Recaudo\DataSourceTableManager;
use App\UseCases\Recaudo\Comunicados\Steps\ConvertExcelToCSVStep;
use App\UseCases\Recaudo\Comunicados\Steps\CountDettraWorkersAndUpdateBascarStep;
use App\UseCases\Recaudo\Comunicados\Steps\CrossBascarWithPagaplStep;
use App\UseCases\Recaudo\Comunicados\Steps\FilterBascarByPeriodStep;
use App\UseCases\Recaudo\Comunicados\Steps\GenerateBascarCompositeKeyStep;
use App\UseCases\Recaudo\Comunicados\Steps\GeneratePagaplCompositeKeyStep;
use App\UseCases\Recaudo\Comunicados\Steps\LoadCsvDataSourcesStep;
use App\UseCases\Recaudo\Comunicados\Steps\LoadExcelCSVsStep;
use App\UseCases\Recaudo\Comunicados\Steps\RemoveCrossedBascarRecordsStep;
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
        private readonly LoadCsvDataSourcesStep $loadCsvDataSourcesStep,
        private readonly ConvertExcelToCSVStep $convertExcelToCSVStep,
        private readonly LoadExcelCSVsStep $loadExcelCSVsStep,
        private readonly ValidateDataIntegrityStep $validateDataStep,
        private readonly FilterBascarByPeriodStep $filterBascarStep,
        private readonly GenerateBascarCompositeKeyStep $generateBascarKeysStep,
        private readonly GeneratePagaplCompositeKeyStep $generatePagaplKeysStep,
        private readonly CrossBascarWithPagaplStep $crossBascarPagaplStep,
        private readonly RemoveCrossedBascarRecordsStep $removeCrossedBascarStep,
        private readonly CountDettraWorkersAndUpdateBascarStep $countDettraWorkersStep,
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
     * FLUJO OPTIMIZADO CON GO STREAMING + POSTGRESQL COPY:
     *
     * FASE 1: CARGA DE DATOS
     * - Paso 1: Cargar CSVs directos con COPY (BASCAR, BAPRPO, DATPOL)
     * - Paso 2: Convertir Excel a CSV con Go streaming (DETTRA, PAGAPL, PAGPLA) - PASO CRÍTICO
     * - Paso 3: Cargar CSVs generados con COPY (DETTRA, PAGAPL, PAGPLA)
     * - Paso 4: Validar integridad de datos
     *
     * FASE 2: TRANSFORMACIÓN SQL
     * - Paso 5: TODO - Depurar tablas (eliminar registros no necesarios)
     * - Paso 6-11: Operaciones SQL de cruce y transformación
     *
     * @return array<int, \App\Contracts\Recaudo\Comunicados\ProcessingStepInterface>
     */
    protected function defineSteps(): array
    {
        return [
            // === FASE 1: CARGA DE DATOS A BD ===

            // Paso 1: Cargar archivos CSV directos (BASCAR, BAPRPO, DATPOL) con PostgreSQL COPY
            $this->loadCsvDataSourcesStep,

            // Paso 2: CRÍTICO - Convertir Excel a CSV usando Go streaming (DETTRA, PAGAPL, PAGPLA)
            // Este es el paso más pesado: archivos 190MB+ procesados sin cargar todo en memoria
            $this->convertExcelToCSVStep,

            // Paso 3: Cargar CSVs generados en Paso 2 usando PostgreSQL COPY
            $this->loadExcelCSVsStep,

            // Paso 4: Validar integridad de datos en BD (verificar que todos los archivos se cargaron)
            $this->validateDataStep,

            // === FASE 2: TRANSFORMACIÓN Y CRUCE DE DATOS SQL ===

            // Paso 5: TODO - Depurar tablas (eliminar registros que no se usarán)
            // PENDIENTE DE IMPLEMENTAR

            // Paso 6: Generar llaves compuestas en BASCAR (SQL UPDATE)
            $this->generateBascarKeysStep,

            // Paso 7: Generar llaves compuestas en PAGAPL (SQL UPDATE)
            $this->generatePagaplKeysStep,

            // Paso 8: Cruzar BASCAR con PAGAPL y generar archivo de excluidos (SQL + tabla temporal)
            $this->crossBascarPagaplStep,

            // Paso 9: Eliminar de BASCAR los registros que cruzaron con PAGAPL (SQL DELETE)
            $this->removeCrossedBascarStep,

            // Paso 10: TODO - Nuevo cruce (pendiente definición de reglas)

            // Paso 11: Contar trabajadores de DETTRA y actualizar BASCAR (SQL UPDATE)
            $this->countDettraWorkersStep,

            // TODO: Pasos subsecuentes pendientes de definición
        ];
    }
}
