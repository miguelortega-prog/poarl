<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\CollectionNoticeRun;
use App\Services\CollectionRun\CollectionRunValidationService;
use Illuminate\Bus\Batch;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Job para procesar la validación de archivos de un CollectionNoticeRun.
 *
 * Principios SOLID aplicados:
 * - Single Responsibility: Solo coordina la validación, delega lógica al Service
 * - Dependency Inversion: Depende de abstracciones (Service)
 * - Open/Closed: Extensible mediante el Service
 *
 * Cumple con PSR-12 y tipado fuerte.
 */
final class ProcessCollectionRunValidation implements ShouldQueue
{
    use Queueable;
    use InteractsWithQueue;
    use SerializesModels;

    /**
     * Número de intentos antes de marcar como fallido.
     */
    public int $tries = 3;

    /**
     * Tiempo máximo de ejecución en segundos (15 minutos para archivos grandes).
     */
    public int $timeout = 900;

    /**
     * Tiempo en segundos antes de reintentar después de un fallo.
     */
    public int $backoff = 30;

    /**
     * Constructor del Job.
     *
     * @param int $collectionNoticeRunId ID del run a validar
     */
    public function __construct(
        private readonly int $collectionNoticeRunId
    ) {
        $this->onQueue('validation');
    }

    /**
     * Ejecuta el job de validación.
     *
     * @throws Throwable
     */
    public function handle(CollectionRunValidationService $validationService): void
    {
        Log::info('Iniciando validación de CollectionNoticeRun', [
            'job' => self::class,
            'run_id' => $this->collectionNoticeRunId,
            'attempt' => $this->attempts(),
        ]);

        try {
            // Buscar el run
            $run = CollectionNoticeRun::query()
                ->with(['files.dataSource.columns', 'type'])
                ->findOrFail($this->collectionNoticeRunId);

            // Validar que el run esté en estado correcto
            if (!in_array($run->status, ['pending', 'validating'], true)) {
                Log::warning('CollectionNoticeRun no está en estado válido para validación', [
                    'run_id' => $run->id,
                    'status' => $run->status,
                ]);

                return;
            }

            // Ejecutar validación mediante el servicio
            $validationSuccess = $validationService->validate($run);

            Log::info('Validación de CollectionNoticeRun completada', [
                'run_id' => $run->id,
                'success' => $validationSuccess,
            ]);

            // Si la validación fue exitosa, disparar jobs de carga OPTIMIZADOS en paralelo
            if ($validationSuccess) {
                Log::info('Disparando jobs OPTIMIZADOS de carga (Excel→CSV→COPY) en paralelo', [
                    'run_id' => $run->id,
                    'period' => $run->period,
                ]);

                // Crear batch de jobs optimizados basados en los archivos del run
                $loadJobs = [];

                // Archivos CSV (usar job antiguo que ya funciona bien)
                $csvFiles = $run->files()->whereIn('ext', ['csv'])->get();
                if ($csvFiles->isNotEmpty()) {
                    $loadJobs[] = new LoadCsvDataSourcesJob($run->id);
                }

                // Archivos Excel (usar nuevo job optimizado con COPY)
                $excelFiles = $run->files()->whereIn('ext', ['xlsx', 'xls'])->with('dataSource')->get();
                foreach ($excelFiles as $file) {
                    $loadJobs[] = new LoadExcelWithCopyJob($file->id, $file->dataSource->code);
                }

                Log::info('Jobs de carga creados', [
                    'run_id' => $run->id,
                    'total_jobs' => count($loadJobs),
                    'csv_jobs' => $csvFiles->count() > 0 ? 1 : 0,
                    'excel_jobs' => $excelFiles->count(),
                ]);

                // Despachar batch de jobs
                Bus::batch($loadJobs)
                ->name("Carga OPTIMIZADA de Data Sources - Run #{$run->id}")
                ->then(function (Batch $batch) use ($run) {
                    // Cuando TODOS los jobs de carga completen, disparar procesamiento SQL
                    Log::info('Todos los archivos cargados (OPTIMIZADO), iniciando procesamiento SQL', [
                        'run_id' => $run->id,
                        'batch_id' => $batch->id,
                    ]);

                    ProcessCollectionDataJob::dispatch($run->id);
                })
                ->catch(function (Batch $batch, Throwable $e) use ($run) {
                    // Si algún job de carga falla
                    Log::error('Error en carga OPTIMIZADA de data sources', [
                        'run_id' => $run->id,
                        'batch_id' => $batch->id,
                        'error' => $e->getMessage(),
                    ]);

                    $run->update([
                        'status' => 'failed',
                        'failed_at' => now(),
                        'errors' => [
                            'message' => 'Error durante la carga optimizada de archivos',
                            'details' => $e->getMessage(),
                            'batch_id' => $batch->id,
                        ],
                    ]);
                })
                ->allowFailures(false) // Si uno falla, detener todo
                ->onQueue('validation')
                ->dispatch();
            }
        } catch (Throwable $exception) {
            Log::error('Error al validar CollectionNoticeRun', [
                'run_id' => $this->collectionNoticeRunId,
                'error' => $exception->getMessage(),
                'trace' => $exception->getTraceAsString(),
                'attempt' => $this->attempts(),
            ]);

            // Re-lanzar excepción para que Laravel maneje los reintentos
            throw $exception;
        }
    }

    /**
     * Maneja el fallo del job después de todos los intentos.
     */
    public function failed(?Throwable $exception): void
    {
        Log::critical('Job de validación falló después de todos los intentos', [
            'job' => self::class,
            'run_id' => $this->collectionNoticeRunId,
            'error' => $exception?->getMessage(),
            'attempts' => $this->tries,
        ]);

        try {
            $run = CollectionNoticeRun::find($this->collectionNoticeRunId);

            if ($run !== null) {
                $run->update([
                    'status' => 'validation_failed',
                    'failed_at' => now(),
                    'errors' => [
                        'message' => 'La validación falló después de múltiples intentos.',
                        'details' => $exception?->getMessage() ?? 'Error desconocido',
                        'trace' => $exception?->getTraceAsString(),
                    ],
                ]);
            }
        } catch (Throwable $e) {
            Log::emergency('No se pudo actualizar el estado del run después del fallo', [
                'run_id' => $this->collectionNoticeRunId,
                'error' => $e->getMessage(),
            ]);

            report($e);
        }
    }

    /**
     * Determina las etiquetas para monitoreo en Horizon.
     *
     * @return array<int, string>
     */
    public function tags(): array
    {
        return [
            'collection_notice_run:' . $this->collectionNoticeRunId,
            'validation',
        ];
    }
}