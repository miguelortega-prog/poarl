<?php

declare(strict_types=1);

namespace App\UseCases\Recaudo\Comunicados\Processors;

use App\Models\CollectionNoticeRun;
use App\Services\Recaudo\Comunicados\BaseCollectionNoticeProcessor;
use App\Services\Recaudo\DataSourceTableManager;
use App\UseCases\Recaudo\Comunicados\Steps\CountDettraWorkersAndUpdateBascarStep;
use App\UseCases\Recaudo\Comunicados\Steps\CrossBascarWithPagaplStep;
use App\UseCases\Recaudo\Comunicados\Steps\FilterBascarByPeriodStep;
use App\UseCases\Recaudo\Comunicados\Steps\GenerateBascarCompositeKeyStep;
use App\UseCases\Recaudo\Comunicados\Steps\GeneratePagaplCompositeKeyStep;
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
     * NOTA: Los pasos de carga de archivos (LoadDataSourceFilesStep, LoadPagaplSheetByPeriodStep, LoadDettraAllSheetsStep)
     * ya NO se ejecutan aquí porque se manejan en jobs paralelos antes de este procesador.
     *
     * Este procesador solo ejecuta operaciones SQL puras sobre datos ya cargados en BD.
     *
     * @return array<int, \App\Contracts\Recaudo\Comunicados\ProcessingStepInterface>
     */
    protected function defineSteps(): array
    {
        return [
            // Paso 1: Validar integridad de datos en BD (verificar que todos los archivos se cargaron)
            $this->validateDataStep,

            // Paso 2: Filtrar BASCAR por periodo (SQL UPDATE)
            $this->filterBascarStep,

            // Paso 3: Generar llaves compuestas en BASCAR (SQL UPDATE)
            $this->generateBascarKeysStep,

            // Paso 4: Generar llaves compuestas en PAGAPL (SQL UPDATE)
            $this->generatePagaplKeysStep,

            // Paso 5: Cruzar BASCAR con PAGAPL y generar archivo de excluidos (SQL + tabla temporal)
            $this->crossBascarPagaplStep,

            // Paso 6: Eliminar de BASCAR los registros que cruzaron con PAGAPL (SQL DELETE)
            $this->removeCrossedBascarStep,

            // Paso 7: Contar trabajadores de DETTRA y actualizar BASCAR (SQL UPDATE)
            $this->countDettraWorkersStep,

            // TODO: Paso 8 - Cruzar con BAPRPO (base producción por póliza)
            // TODO: Paso 9 - Cruzar con PAGPLA (pagos planilla)
            // TODO: Paso 10 - Cruzar con DATPOL
            // TODO: Paso 11 - Generar archivos de salida
        ];
    }
}
