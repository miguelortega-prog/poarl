<?php

declare(strict_types=1);

namespace App\UseCases\Recaudo\Comunicados\Processors;

use App\Models\CollectionNoticeRun;
use App\Services\Recaudo\Comunicados\BaseCollectionNoticeProcessor;
use App\Services\Recaudo\DataSourceTableManager;
use App\UseCases\Recaudo\Comunicados\Steps\CleanupDataSourcesStep;
use App\UseCases\Recaudo\Comunicados\Steps\FilterDettraByTipoCotizanteStep;
use App\UseCases\Recaudo\Comunicados\Steps\GenerateDettraCompositeKeyStep;
use App\UseCases\Recaudo\Comunicados\Steps\MarkRunAsCompletedStep;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;

/**
 * Procesador para el tipo de comunicado "CONSTITUCIÓN EN MORA - INDEPENDIENTES".
 *
 * Este comunicado procesa trabajadores independientes con póliza independiente.
 *
 * Pipeline:
 * 1. Filtrar DETTRA por tipo_cotizante y riesgo (independientes válidos)
 * 2. Generar llave compuesta en DETTRA (nit + periodo) con índice
 * 3. Marcar run como completado
 * 4. Limpiar datos de data sources
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
        private readonly GenerateDettraCompositeKeyStep $generateDettraCompositeKeyStep,
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
     * Este procesador realiza procesamiento mínimo:
     * - PASO 1: Filtrar DETTRA por tipo_cotizante y riesgo
     * - PASO 2: Generar llave compuesta en DETTRA (nit + periodo)
     * - PASO 3: Marcar run como completado
     * - PASO 4: Limpiar datos de data sources
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

            // Paso 2: Generar llave compuesta en DETTRA
            // Crea columna composite_key = nit + periodo del run
            // Genera índice para búsquedas rápidas en cruces posteriores
            $this->generateDettraCompositeKeyStep,

            // Paso 3: Marcar run como completado
            // Cambia el estado del run a 'completed'
            // Registra la duración total del procesamiento
            $this->markRunAsCompletedStep,

            // Paso 4: Limpiar datos de tablas data_source_
            // Elimina todos los registros de data_source_* para este run_id
            // para liberar espacio en disco después del procesamiento exitoso
            $this->cleanupDataSourcesStep,
        ];
    }
}
