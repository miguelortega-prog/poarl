<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Contracts\Recaudo\Comunicados\CollectionNoticeProcessorInterface;
use App\Models\CollectionNoticeRun;
use App\Services\NotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Job para procesar los datos de un comunicado de recaudo.
 *
 * Este job se ejecuta después de que la validación de archivos
 * sea exitosa. Resuelve el procesador adecuado según el tipo
 * de comunicado y ejecuta su lógica específica.
 */
final class ProcessCollectionNoticeRunData implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Número de intentos del job.
     *
     * @var int
     */
    public int $tries = 3;

    /**
     * Tiempo máximo de ejecución (30 minutos).
     *
     * @var int
     */
    public int $timeout = 1800;

    /**
     * @param int $runId ID del run a procesar
     */
    public function __construct(
        private readonly int $runId
    ) {
        $this->onQueue('collection-notices');
    }

    /**
     * Ejecuta el job de procesamiento.
     *
     * @param NotificationService $notificationService
     *
     * @return void
     */
    public function handle(NotificationService $notificationService): void
    {
        // Aumentar el límite de memoria para procesar archivos grandes (Excel ~200-300MB)
        ini_set('memory_limit', '4096M');

        Log::info('Iniciando job de procesamiento de datos', [
            'job' => self::class,
            'run_id' => $this->runId,
            'memory_limit' => ini_get('memory_limit'),
        ]);

        try {
            $run = CollectionNoticeRun::with(['type', 'files.dataSource', 'requestedBy'])
                ->findOrFail($this->runId);

            // Verificar que el run esté validado
            if ($run->status !== 'validated') {
                Log::warning('Run no está en estado validated, omitiendo procesamiento', [
                    'run_id' => $run->id,
                    'status' => $run->status,
                ]);

                return;
            }

            // Cambiar estado a processing
            $run->update([
                'status' => 'processing',
                'started_at' => now(),
            ]);

            Log::info('Run cambiado a estado processing', [
                'run_id' => $run->id,
            ]);

            // Resolver el procesador desde el tipo de comunicado
            $processor = $this->resolveProcessor($run);

            // Validar que el procesador puede procesar este run
            if (!$processor->canProcess($run)) {
                throw new RuntimeException(
                    sprintf('El procesador "%s" no puede procesar el run #%d', $processor->getName(), $run->id)
                );
            }

            // Ejecutar el procesamiento
            $processor->process($run);

            // Enviar notificación de éxito
            $notificationService->notifyProcessingSuccess($run);

            Log::info('Job de procesamiento completado exitosamente', [
                'job' => self::class,
                'run_id' => $run->id,
                'processor' => $processor->getName(),
            ]);
        } catch (Throwable $exception) {
            Log::error('Error en job de procesamiento de datos', [
                'job' => self::class,
                'run_id' => $this->runId,
                'error' => $exception->getMessage(),
                'trace' => $exception->getTraceAsString(),
            ]);

            // Intentar cargar el run para notificar error
            try {
                $run = CollectionNoticeRun::with('requestedBy')->find($this->runId);
                if ($run !== null) {
                    $notificationService->notifyProcessingFailure($run, $exception->getMessage());
                }
            } catch (Throwable $notificationException) {
                Log::error('Error al enviar notificación de fallo', [
                    'run_id' => $this->runId,
                    'error' => $notificationException->getMessage(),
                ]);
            }

            throw $exception;
        }
    }

    /**
     * Resuelve el procesador adecuado para el tipo de comunicado.
     *
     * @param CollectionNoticeRun $run
     *
     * @return CollectionNoticeProcessorInterface
     *
     * @throws RuntimeException Si no se encuentra el procesador
     */
    private function resolveProcessor(CollectionNoticeRun $run): CollectionNoticeProcessorInterface
    {
        $processorType = $run->type?->processor_type;

        if ($processorType === null) {
            throw new RuntimeException(
                sprintf('El tipo de comunicado #%d no tiene un procesador asignado', $run->collection_notice_type_id)
            );
        }

        // Obtener mapeo de procesadores desde configuración
        $processors = config('collection-notices.processors', []);

        if (!isset($processors[$processorType])) {
            throw new RuntimeException(
                sprintf('No existe procesador registrado para el tipo "%s"', $processorType)
            );
        }

        $processorClass = $processors[$processorType];

        if (!class_exists($processorClass)) {
            throw new RuntimeException(
                sprintf('La clase del procesador "%s" no existe', $processorClass)
            );
        }

        $processor = app($processorClass);

        if (!$processor instanceof CollectionNoticeProcessorInterface) {
            throw new RuntimeException(
                sprintf(
                    'El procesador "%s" debe implementar %s',
                    $processorClass,
                    CollectionNoticeProcessorInterface::class
                )
            );
        }

        Log::info('Procesador resuelto exitosamente', [
            'run_id' => $run->id,
            'processor_type' => $processorType,
            'processor_class' => $processorClass,
        ]);

        return $processor;
    }

    /**
     * Maneja el fallo del job.
     *
     * @param Throwable $exception
     *
     * @return void
     */
    public function failed(Throwable $exception): void
    {
        Log::error('Job de procesamiento falló definitivamente', [
            'job' => self::class,
            'run_id' => $this->runId,
            'error' => $exception->getMessage(),
        ]);
    }
}

