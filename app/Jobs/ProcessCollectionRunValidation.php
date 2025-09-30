<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\CollectionNoticeRun;
use App\Services\CollectionRun\CollectionRunValidationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
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
            $validationService->validate($run);

            Log::info('Validación de CollectionNoticeRun completada exitosamente', [
                'run_id' => $run->id,
            ]);
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