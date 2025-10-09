<?php

declare(strict_types=1);

namespace App\UseCases\Recaudo\Comunicados\Processors;

use App\Models\CollectionNoticeRun;
use App\Services\Recaudo\Comunicados\BaseCollectionNoticeProcessor;
use App\Services\Recaudo\DataSourceTableManager;
use App\UseCases\Recaudo\Comunicados\Steps\MarkRunAsCompletedStep;
use App\UseCases\Recaudo\Comunicados\Steps\SanitizeNumericFieldsStep;
use App\UseCases\Recaudo\Comunicados\Steps\ValidateDataIntegrityStep;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;

/**
 * Procesador para el tipo de comunicado "CONSTITUCIÓN EN MORA - INDEPENDIENTES".
 *
 * Este comunicado procesa trabajadores independientes con póliza independiente.
 *
 * Data sources requeridos:
 * - BASACT: Base de activos (trabajadores independientes)
 * - PAGLOG: Pagos log bancario
 * - PAGPLA: Pagos planilla
 * - DETTRA: Detalle trabajadores
 *
 * TODO: Definir lógica de cruces y transformaciones específicas
 */
final class ConstitucionMoraIndependientesProcessor extends BaseCollectionNoticeProcessor
{
    public function __construct(
        DataSourceTableManager $tableManager,
        FilesystemFactory $filesystem,
        private readonly ValidateDataIntegrityStep $validateDataStep,
        private readonly SanitizeNumericFieldsStep $sanitizeNumericFieldsStep,
        private readonly MarkRunAsCompletedStep $markRunAsCompletedStep,
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
            // === FASE 1: VALIDACIÓN Y SANITIZACIÓN ===

            // Paso 1: Validar integridad de datos en BD
            // Verifica que los jobs previos cargaron correctamente:
            // - BASACT, PAGLOG, PAGPLA, DETTRA (LoadExcelWithCopyJob)
            $this->validateDataStep,

            // Paso 2: Sanitizar campos numéricos (formato europeo → estándar)
            // Limpia campos numéricos que vienen con separadores europeos
            $this->sanitizeNumericFieldsStep,

            // === FASE 2: TRANSFORMACIONES Y CRUCES (TODO) ===

            // TODO: Paso 3: Filtrar datos por periodo del run
            // TODO: Paso 4: Generar llaves compuestas en BASACT
            // TODO: Paso 5: Generar llaves compuestas en PAGLOG
            // TODO: Paso 6: Cruzar BASACT con PAGLOG y generar archivo de excluidos
            // TODO: Paso 7: Eliminar de BASACT los registros que cruzaron con PAGLOG
            // TODO: Paso 8: Agregar datos de contacto (email, dirección, divipola)
            // TODO: Paso 9: Definir tipo de envío de correspondencia
            // TODO: Paso 10: Excluir registros sin datos de contacto
            // TODO: Paso 11: Agregar consecutivo
            // TODO: Paso 12: Exportar a Excel

            // === FASE 3: FINALIZACIÓN ===

            // Paso N: Marcar run como completado
            $this->markRunAsCompletedStep,
        ];
    }
}
