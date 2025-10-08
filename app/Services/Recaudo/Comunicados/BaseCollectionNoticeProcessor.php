<?php

declare(strict_types=1);

namespace App\Services\Recaudo\Comunicados;

use App\Contracts\Recaudo\Comunicados\CollectionNoticeProcessorInterface;
use App\Contracts\Recaudo\Comunicados\ProcessingStepInterface;
use App\Models\CollectionNoticeRun;
use App\Services\Recaudo\DataSourceTableManager;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Clase base abstracta para procesadores de comunicados.
 *
 * Implementa el patrón Pipeline para ejecutar una serie de pasos
 * de procesamiento de forma ordenada y con manejo de errores.
 */
abstract class BaseCollectionNoticeProcessor implements CollectionNoticeProcessorInterface
{
    /**
     * @var array<int, ProcessingStepInterface>
     */
    protected array $steps = [];

    /**
     * Constructor.
     *
     * @param DataSourceTableManager $tableManager
     * @param FilesystemFactory $filesystem
     */
    public function __construct(
        protected DataSourceTableManager $tableManager,
        protected FilesystemFactory $filesystem
    ) {
    }

    /**
     * Corrige permisos de un archivo para asegurar acceso por www-data.
     *
     * Este método es útil cuando los archivos son creados por procesos
     * que corren como root (ej: jobs de Horizon ejecutados desde CLI).
     *
     * @param string $absolutePath Ruta absoluta al archivo
     * @return void
     */
    public static function fixFilePermissions(string $absolutePath): void
    {
        if (!file_exists($absolutePath)) {
            return;
        }

        // Establecer permisos 644 (rw-r--r--)
        @chmod($absolutePath, 0644);

        // Intentar cambiar owner a www-data si es posible
        // (solo funcionará si el proceso actual es root)
        @chown($absolutePath, 'www-data');
        @chgrp($absolutePath, 'www-data');

        // También corregir permisos del directorio padre si es necesario
        $parentDir = dirname($absolutePath);
        if (file_exists($parentDir)) {
            @chmod($parentDir, 0755);
            @chown($parentDir, 'www-data');
            @chgrp($parentDir, 'www-data');
        }
    }

    /**
     * Procesa el run ejecutando todos los pasos del pipeline.
     *
     * @param CollectionNoticeRun $run
     *
     * @return void
     *
     * @throws RuntimeException
     */
    public function process(CollectionNoticeRun $run): void
    {
        $startTime = microtime(true);

        Log::info('Iniciando procesamiento de comunicado', [
            'processor' => $this->getName(),
            'run_id' => $run->id,
            'type_id' => $run->collection_notice_type_id,
        ]);

        try {
            DB::beginTransaction();

            // Ejecutar pipeline de pasos
            $this->executePipeline($run);

            // Calcular duración
            $duration = (int) ((microtime(true) - $startTime) * 1000);

            // Marcar como completado
            $run->update([
                'status' => 'completed',
                'completed_at' => now(),
                'duration_ms' => $duration,
            ]);

            DB::commit();

            Log::info('Procesamiento de comunicado completado exitosamente', [
                'processor' => $this->getName(),
                'run_id' => $run->id,
                'duration_ms' => $duration,
            ]);

            // TODO: Habilitar cleanup cuando el procesamiento esté 100% funcional
            // Limpiar datos de BD y archivos de insumos después del commit
            // $this->cleanup($run);
        } catch (Throwable $exception) {
            DB::rollBack();

            $duration = (int) ((microtime(true) - $startTime) * 1000);

            $run->update([
                'status' => 'failed',
                'failed_at' => now(),
                'duration_ms' => $duration,
                'errors' => [
                    'message' => 'Error durante el procesamiento del comunicado.',
                    'details' => $exception->getMessage(),
                ],
            ]);

            Log::error('Error al procesar comunicado', [
                'processor' => $this->getName(),
                'run_id' => $run->id,
                'error' => $exception->getMessage(),
                'trace' => $exception->getTraceAsString(),
            ]);

            // En caso de error, mantener datos de BD para debugging
            Log::warning('Datos de BD mantenidos para debugging', [
                'run_id' => $run->id,
            ]);

            throw $exception;
        }
    }

    /**
     * Ejecuta el pipeline de pasos de procesamiento.
     *
     * @param CollectionNoticeRun $run
     *
     * @return void
     */
    protected function executePipeline(CollectionNoticeRun $run): void
    {
        foreach ($this->steps as $step) {
            Log::info('Ejecutando paso de procesamiento', [
                'step' => $step->getName(),
                'run_id' => $run->id,
            ]);

            $stepStartTime = microtime(true);

            $step->execute($run);

            $stepDuration = (int) ((microtime(true) - $stepStartTime) * 1000);

            Log::info('Paso completado', [
                'step' => $step->getName(),
                'run_id' => $run->id,
                'duration_ms' => $stepDuration,
            ]);
        }
    }

    /**
     * Valida que el run puede ser procesado.
     *
     * @param CollectionNoticeRun $run
     *
     * @return bool
     */
    public function canProcess(CollectionNoticeRun $run): bool
    {
        // Verificar que el run esté en estado "validated"
        if ($run->status !== 'validated') {
            Log::warning('Run no está en estado validated', [
                'run_id' => $run->id,
                'status' => $run->status,
            ]);

            return false;
        }

        // Verificar que tenga el tipo correcto
        if ($run->type === null || $run->type->processor_type === null) {
            Log::warning('Run no tiene tipo de procesador asignado', [
                'run_id' => $run->id,
            ]);

            return false;
        }

        return true;
    }

    /**
     * Define los pasos del pipeline para este procesador.
     *
     * Los procesadores concretos deben implementar este método
     * para definir su secuencia específica de pasos.
     *
     * @return array<int, ProcessingStepInterface>
     */
    abstract protected function defineSteps(): array;

    /**
     * Inicializa los pasos del pipeline.
     *
     * Este método debe ser llamado en el constructor de las clases concretas.
     *
     * @return void
     */
    protected function initializeSteps(): void
    {
        $this->steps = $this->defineSteps();
    }

    /**
     * Limpia datos de BD y archivos de insumos al finalizar el procesamiento.
     *
     * Solo limpia si el run fue completado exitosamente.
     * Los archivos de resultados se mantienen.
     *
     * @param CollectionNoticeRun $run
     *
     * @return void
     */
    protected function cleanup(CollectionNoticeRun $run): void
    {
        if ($run->status !== 'completed') {
            Log::debug('Cleanup omitido - run no completado', [
                'run_id' => $run->id,
                'status' => $run->status,
            ]);

            return;
        }

        Log::info('Iniciando cleanup de datos', [
            'run_id' => $run->id,
        ]);

        try {
            // Limpiar datos de tablas staging
            $deletedRows = $this->tableManager->cleanupRunData($run->id);

            Log::info('Datos de BD limpiados', [
                'run_id' => $run->id,
                'deleted_rows' => $deletedRows,
            ]);

            // Limpiar archivos de insumos (mantener solo resultados)
            $disk = $this->filesystem->disk('collection');
            $runDir = sprintf('collection_notice_runs/%d', $run->id);
            $resultsDir = $runDir . '/results';

            // Verificar que existe el directorio del run
            if ($disk->exists($runDir)) {
                // Obtener todos los archivos del run
                $allFiles = $disk->allFiles($runDir);

                // Eliminar solo archivos que NO estén en /results/
                foreach ($allFiles as $file) {
                    if (!str_starts_with($file, $resultsDir . '/')) {
                        $disk->delete($file);
                        Log::debug('Archivo de insumo eliminado', [
                            'run_id' => $run->id,
                            'file' => $file,
                        ]);
                    }
                }

                Log::info('Archivos de insumos limpiados', [
                    'run_id' => $run->id,
                    'results_preserved' => true,
                ]);
            }
        } catch (Throwable $exception) {
            // No fallar el job si el cleanup falla
            Log::error('Error durante cleanup (no crítico)', [
                'run_id' => $run->id,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
