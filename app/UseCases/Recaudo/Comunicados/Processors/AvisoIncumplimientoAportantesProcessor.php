<?php

declare(strict_types=1);

namespace App\UseCases\Recaudo\Comunicados\Processors;

use App\Models\CollectionNoticeRun;
use App\Services\Recaudo\Comunicados\BaseCollectionNoticeProcessor;
use App\Services\Recaudo\DataSourceTableManager;
use App\UseCases\Recaudo\Comunicados\Steps\CleanupDataSourcesStep;
use App\UseCases\Recaudo\Comunicados\Steps\MarkRunAsCompletedStep;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;

/**
 * Procesador para el tipo de comunicado "AVISO DE INCUMPLIMIENTO - APORTANTES".
 *
 * Este comunicado notifica a aportantes sobre incumplimientos en el pago de sus obligaciones.
 *
 * Pipeline inicial (básico - 2 pasos):
 * 1. Marcar run como completado
 * 2. Limpiar datos de data sources
 *
 * Data sources requeridos:
 * - Por definir según instrucciones
 *
 * TODO: Implementar steps del pipeline según especificaciones del negocio
 */
final class AvisoIncumplimientoAportantesProcessor extends BaseCollectionNoticeProcessor
{
    public function __construct(
        DataSourceTableManager $tableManager,
        FilesystemFactory $filesystem,
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
        return 'Aviso de Incumplimiento - Aportantes';
    }

    /**
     * @param CollectionNoticeRun $run
     *
     * @return bool
     */
    public function canProcess(CollectionNoticeRun $run): bool
    {
        // Validar que el tipo de comunicado sea el correcto
        if ($run->type?->processor_type !== 'aviso_incumplimiento_aportantes') {
            return false;
        }

        // TODO: Definir data sources requeridos según instrucciones
        // Por ahora, no validamos archivos específicos
        // $requiredDataSources = ['DATA_SOURCE_1', 'DATA_SOURCE_2'];
        // $uploadedDataSources = $run->files->pluck('dataSource.code')->filter()->toArray();
        //
        // foreach ($requiredDataSources as $required) {
        //     if (!in_array($required, $uploadedDataSources, true)) {
        //         return false;
        //     }
        // }

        return true;
    }

    /**
     * Define los pasos del pipeline para este procesador.
     *
     * Pipeline básico inicial con solo pasos de finalización.
     * Los steps de procesamiento se agregarán según las instrucciones del negocio.
     *
     * IMPORTANTE: Los datos ya fueron cargados por los jobs previos:
     * - LoadExcelWithCopyJob: Para archivos .xlsx
     * - LoadCsvDataSourcesJob: Para archivos .csv
     *
     * @return array<int, \App\Contracts\Recaudo\Comunicados\ProcessingStepInterface>
     */
    protected function defineSteps(): array
    {
        return [
            // Paso 1: Marcar run como completado
            // Cambia el estado del run a 'completed'
            // Registra la duración total del procesamiento
            $this->markRunAsCompletedStep,

            // Paso 2: Limpiar datos de tablas data_source_
            // Elimina todos los registros de data_source_* para este run_id
            // para liberar espacio en disco después del procesamiento exitoso
            $this->cleanupDataSourcesStep,
        ];
    }
}
