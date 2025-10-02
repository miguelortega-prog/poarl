<?php

declare(strict_types=1);

namespace App\UseCases\Recaudo\Comunicados\Steps;

use App\Contracts\Recaudo\Comunicados\ProcessingStepInterface;
use App\DTOs\Recaudo\Comunicados\ProcessingContextDto;
use Illuminate\Support\Facades\Log;

/**
 * Paso para validar la integridad de los datos cargados.
 *
 * Verifica que todos los data sources esperados estén presentes
 * y que los datos tengan la estructura correcta.
 */
final class ValidateDataIntegrityStep implements ProcessingStepInterface
{
    /**
     * @param ProcessingContextDto $context
     *
     * @return ProcessingContextDto
     */
    public function execute(ProcessingContextDto $context): ProcessingContextDto
    {
        $run = $context->run;
        $loadedData = $context->data;

        // Obtener data sources esperados del tipo de comunicado
        $expectedDataSources = $run->type->dataSources->pluck('code')->toArray();
        $loadedDataSources = array_keys($loadedData);

        Log::info('Validando integridad de datos', [
            'run_id' => $run->id,
            'expected' => $expectedDataSources,
            'loaded' => $loadedDataSources,
        ]);

        // Verificar que todos los data sources esperados estén cargados
        $missingDataSources = array_diff($expectedDataSources, $loadedDataSources);

        if ($missingDataSources !== []) {
            return $context->addError(
                sprintf(
                    'Faltan data sources requeridos: %s',
                    implode(', ', $missingDataSources)
                )
            );
        }

        // TODO: Aquí se agregarían más validaciones:
        // - Verificar que no haya duplicados
        // - Validar rangos de valores
        // - Verificar relaciones entre data sources
        // etc.

        Log::info('Validación de integridad completada exitosamente', [
            'run_id' => $run->id,
        ]);

        return $context->addStepResult($this->getName(), [
            'validation_passed' => true,
            'data_sources_validated' => count($loadedDataSources),
        ]);
    }

    /**
     * @return string
     */
    public function getName(): string
    {
        return 'Validar integridad de datos';
    }

    /**
     * @param ProcessingContextDto $context
     *
     * @return bool
     */
    public function shouldExecute(ProcessingContextDto $context): bool
    {
        // Solo ejecutar si hay datos cargados
        return $context->data !== [];
    }
}
