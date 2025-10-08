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
        $this->onQueue('default');
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
            // Nota: 'validated' se incluye porque el run puede haber sido validado previamente
            // pero aún necesita ejecutar la carga de datos
            if (!in_array($run->status, ['pending', 'validating', 'validated'], true)) {
                Log::warning('CollectionNoticeRun no está en estado válido para validación/carga', [
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

            // Si la validación fue exitosa, disparar jobs de carga SECUENCIALMENTE
            if ($validationSuccess) {
                Log::info('Iniciando carga SECUENCIAL de data sources (CSV → Excel → SQL)', [
                    'run_id' => $run->id,
                    'period' => $run->period,
                ]);

                // Obtener archivos CSV y Excel
                $csvFiles = $run->files()->whereIn('ext', ['csv'])->with('dataSource')->get();
                $excelFiles = $run->files()->whereIn('ext', ['xlsx', 'xls'])->with('dataSource')->get();

                if ($csvFiles->isNotEmpty() || $excelFiles->isNotEmpty()) {
                    // Crear chain: CSV jobs → Excel jobs → ProcessCollectionDataJob
                    $chain = [];

                    // Agregar un job por cada archivo CSV
                    foreach ($csvFiles as $file) {
                        $chain[] = new LoadCsvDataSourcesJob($file->id, $file->dataSource->code);
                    }

                    // Agregar un job por cada archivo Excel
                    foreach ($excelFiles as $file) {
                        $chain[] = new LoadExcelWithCopyJob($file->id, $file->dataSource->code);
                    }

                    // Agregar job de procesamiento SQL al final
                    $chain[] = new ProcessCollectionDataJob($run->id);

                    Log::info('Chain de jobs creado (ejecución SECUENCIAL)', [
                        'run_id' => $run->id,
                        'csv_jobs' => $csvFiles->count(),
                        'excel_jobs' => $excelFiles->count(),
                        'processing_jobs' => 1,
                        'total_jobs' => count($chain),
                    ]);

                    // Despachar el chain (se ejecutarán UNO TRAS OTRO)
                    Bus::chain($chain)
                        ->onQueue('default')
                        ->catch(function (Throwable $e) use ($run) {
                            Log::error('Error en chain de carga de data sources', [
                                'run_id' => $run->id,
                                'error' => $e->getMessage(),
                                'trace' => $e->getTraceAsString(),
                            ]);

                            $run->update([
                                'status' => 'failed',
                                'failed_at' => now(),
                                'errors' => [
                                    'message' => 'Error durante la carga secuencial de archivos',
                                    'details' => $e->getMessage(),
                                ],
                            ]);
                        })
                        ->dispatch();
                } else {
                    Log::warning('No hay archivos para procesar', [
                        'run_id' => $run->id,
                    ]);
                }
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