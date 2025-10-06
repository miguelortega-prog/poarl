<?php

declare(strict_types=1);

namespace App\UseCases\Recaudo\Comunicados\Processors;

use App\Models\CollectionNoticeRun;
use App\Services\Recaudo\Comunicados\BaseCollectionNoticeProcessor;
use App\Services\Recaudo\DataSourceTableManager;
use App\UseCases\Recaudo\Comunicados\Steps\CountDettraWorkersAndUpdateBascarStep;
use App\UseCases\Recaudo\Comunicados\Steps\CrearBaseTrabajadoresActivosStep;
use App\UseCases\Recaudo\Comunicados\Steps\CrossBascarWithPagaplStep;
use App\UseCases\Recaudo\Comunicados\Steps\ExcludePsiPersonaJuridicaStep;
use App\UseCases\Recaudo\Comunicados\Steps\FilterBascarByPeriodStep;
use App\UseCases\Recaudo\Comunicados\Steps\FilterDataByPeriodStep;
use App\UseCases\Recaudo\Comunicados\Steps\GenerateBascarCompositeKeyStep;
use App\UseCases\Recaudo\Comunicados\Steps\GeneratePagaplCompositeKeyStep;
use App\UseCases\Recaudo\Comunicados\Steps\IdentifyPsiStep;
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
        private readonly ValidateDataIntegrityStep $validateDataStep,
        private readonly FilterDataByPeriodStep $filterDataByPeriodStep,
        private readonly FilterBascarByPeriodStep $filterBascarStep,
        private readonly GenerateBascarCompositeKeyStep $generateBascarKeysStep,
        private readonly GeneratePagaplCompositeKeyStep $generatePagaplKeysStep,
        private readonly CrossBascarWithPagaplStep $crossBascarPagaplStep,
        private readonly RemoveCrossedBascarRecordsStep $removeCrossedBascarStep,
        private readonly IdentifyPsiStep $identifyPsiStep,
        private readonly ExcludePsiPersonaJuridicaStep $excludePsiPersonaJuridicaStep,
        private readonly CountDettraWorkersAndUpdateBascarStep $countDettraWorkersStep,
        private readonly CrearBaseTrabajadoresActivosStep $crearBaseTrabajadoresActivosStep,
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
     * - PASOS 2+: Transformaciones SQL (filtros, cruces, generación de archivos)
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

            // === FASE 2: FILTRADO DE DATOS POR PERIODO ===

            // Paso 2: Filtrar datos por periodo del run
            // - Si periodo = "Todos Los Periodos": No filtra nada
            // - Si periodo = YYYYMM: Filtra DETTRA por FECHA_INICIO_VIG
            $this->filterDataByPeriodStep,

            // === FASE 3: TRANSFORMACIÓN Y CRUCE DE DATOS SQL ===

            // Paso 3: Generar llaves compuestas en BASCAR (SQL UPDATE)
            $this->generateBascarKeysStep,

            // Paso 4: Generar llaves compuestas en PAGAPL (SQL UPDATE)
            $this->generatePagaplKeysStep,

            // Paso 5: Cruzar BASCAR con PAGAPL y generar archivo de excluidos (SQL + tabla temporal)
            $this->crossBascarPagaplStep,

            // Paso 6: Eliminar de BASCAR los registros que cruzaron con PAGAPL (SQL DELETE)
            $this->removeCrossedBascarStep,

            // Paso 7: Identificar PSI (Póliza de Seguro Independiente)
            // Cruza BASCAR.NUM_TOMADOR con BAPRPO.tomador para obtener pol_independiente
            $this->identifyPsiStep,

            // Paso 8: Excluir PSI Persona Jurídica (9 dígitos)
            // Excluye registros con PSI='S' y NUM_TOMADOR de 9 dígitos
            // Los agrega al archivo de excluidos y los elimina de BASCAR
            $this->excludePsiPersonaJuridicaStep,

            // Paso 9: Contar trabajadores de DETTRA y actualizar BASCAR (SQL UPDATE)
            $this->countDettraWorkersStep,

            // Paso 10: Crear base de trabajadores activos (CSV detalle)
            // Cruza DETTRA.NRO_DOCUMTO con BASCAR.NUM_TOMADOR
            // Genera archivo detalle_trabajadores{run_id}.csv
            $this->crearBaseTrabajadoresActivosStep,

            // TODO: Pasos subsecuentes pendientes de definición
        ];
    }
}
