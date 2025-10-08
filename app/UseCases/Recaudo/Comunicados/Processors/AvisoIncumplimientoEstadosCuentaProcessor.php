<?php

declare(strict_types=1);

namespace App\UseCases\Recaudo\Comunicados\Processors;

use App\Contracts\Recaudo\Comunicados\ProcessingStepInterface;
use App\Services\Recaudo\Comunicados\BaseCollectionNoticeProcessor;
use App\UseCases\Recaudo\Comunicados\Steps\LoadDataSourceFilesStep;
use App\UseCases\Recaudo\Comunicados\Steps\ValidateDataIntegrityStep;

/**
 * Procesador para comunicados de Aviso de Incumplimiento por Estados de Cuenta.
 *
 * Este procesador maneja la lógica específica para generar avisos de
 * incumplimiento basados en estados de cuenta con inconsistencias.
 *
 * Data sources requeridos:
 * - base_cartera (BASCAR): Base de cartera
 * - datpol: Datos de pólizas
 * - estados_cuenta_inconsistencia (ESCUIN): Estados de cuenta con inconsistencias
 */
final class AvisoIncumplimientoEstadosCuentaProcessor extends BaseCollectionNoticeProcessor
{
    public function __construct(
        private readonly LoadDataSourceFilesStep $loadFilesStep,
        private readonly ValidateDataIntegrityStep $validateDataStep,
    ) {
        $this->initializeSteps();
    }

    /**
     * @return string
     */
    public function getName(): string
    {
        return 'Aviso de Incumplimiento por Estados de Cuenta';
    }

    /**
     * Define los pasos del pipeline para este procesador.
     *
     * @return array<int, ProcessingStepInterface>
     */
    protected function defineSteps(): array
    {
        return [
            $this->loadFilesStep,
            $this->validateDataStep,
            // Aquí se agregarán más pasos según la lógica específica:
            // - CrossReferenceDataStep
            // - GenerateNoticesStep
            // - ExportResultsStep
            // etc.
        ];
    }
}
