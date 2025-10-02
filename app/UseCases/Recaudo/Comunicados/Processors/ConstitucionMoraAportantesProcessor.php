<?php

declare(strict_types=1);

namespace App\UseCases\Recaudo\Comunicados\Processors;

use App\Models\CollectionNoticeRun;
use App\Services\Recaudo\Comunicados\BaseCollectionNoticeProcessor;
use App\Services\Recaudo\DataSourceTableManager;
use App\UseCases\Recaudo\Comunicados\Steps\CrossBascarWithPagaplStep;
use App\UseCases\Recaudo\Comunicados\Steps\FilterBascarByPeriodStep;
use App\UseCases\Recaudo\Comunicados\Steps\GenerateBascarCompositeKeyStep;
use App\UseCases\Recaudo\Comunicados\Steps\GeneratePagaplCompositeKeyStep;
use App\UseCases\Recaudo\Comunicados\Steps\LoadDataSourceFilesStep;
use App\UseCases\Recaudo\Comunicados\Steps\LoadPagaplSheetByPeriodStep;
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
        private readonly LoadDataSourceFilesStep $loadFilesStep,
        private readonly ValidateDataIntegrityStep $validateDataStep,
        private readonly FilterBascarByPeriodStep $filterBascarStep,
        private readonly GenerateBascarCompositeKeyStep $generateBascarKeysStep,
        private readonly LoadPagaplSheetByPeriodStep $loadPagaplStep,
        private readonly GeneratePagaplCompositeKeyStep $generatePagaplKeysStep,
        private readonly CrossBascarWithPagaplStep $crossBascarPagaplStep,
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
     * @return array<int, \App\Contracts\Recaudo\Comunicados\ProcessingStepInterface>
     */
    protected function defineSteps(): array
    {
        return [
            // Paso 1: Cargar metadata de archivos
            $this->loadFilesStep,

            // Paso 2: Validar integridad de datos
            $this->validateDataStep,

            // Paso 3: Filtrar BASCAR por periodo
            $this->filterBascarStep,

            // Paso 4: Generar llaves compuestas en BASCAR (NUM_TOMADOR + periodo)
            $this->generateBascarKeysStep,

            // Paso 5: Cargar hoja de PAGAPL correspondiente al periodo
            $this->loadPagaplStep,

            // Paso 6: Generar llaves compuestas en PAGAPL (Identificación + Periodo)
            $this->generatePagaplKeysStep,

            // Paso 7: Cruzar BASCAR con PAGAPL y generar archivo de excluidos
            $this->crossBascarPagaplStep,

            // TODO: Paso 8 - Cruzar con BAPRPO (base producción por póliza)
            // TODO: Paso 7 - Cruzar con PAGPLA (pagos planilla)
            // TODO: Paso 8 - Cruzar con DATPOL
            // TODO: Paso 9 - Cruzar con DETTRA (detalle trabajadores)
            // TODO: Paso 10 - Generar archivos de salida
        ];
    }
}
