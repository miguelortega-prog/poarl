<?php

declare(strict_types=1);

namespace App\Services\Uploads;

use App\ValueObjects\Uploads\ChunkUploadEventPayload;
use App\ValueObjects\Uploads\UploadedFileMetadata;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Servicio centralizado para manejo de eventos de carga por chunks.
 *
 * Principios SOLID aplicados:
 * - Single Responsibility: Solo maneja eventos de upload
 * - Open/Closed: Extensible mediante herencia
 * - Dependency Inversion: Depende de abstracciones (Filesystem)
 */
final readonly class ChunkUploadEventHandler
{
    public function __construct(
        private FileMetadataNormalizer $normalizer,
        private UploadedFileValidator $validator,
        private Filesystem $disk,
    ) {
    }

    /**
     * Maneja el evento de inicio de carga (uploading).
     *
     * @return array{success: true, action: 'reset'}
     */
    public function handleUploading(ChunkUploadEventPayload $payload): array
    {
        // Solo registramos el evento, no mutamos estado en Livewire
        return [
            'success' => true,
            'action' => 'reset',
        ];
    }

    /**
     * Maneja el evento de chunk cargado exitosamente.
     *
     * @param array<string, mixed> $fileData
     *
     * @return array{success: true, action: 'store', metadata: UploadedFileMetadata}
     *
     * @throws InvalidArgumentException
     * @throws RuntimeException
     */
    public function handleUploaded(ChunkUploadEventPayload $payload, array $fileData): array
    {
        try {
            // Normalizar metadata
            $metadata = $this->normalizer->normalize($fileData);

            if ($metadata === null) {
                throw new InvalidArgumentException(
                    'La información del archivo cargado es inválida o está incompleta.'
                );
            }

            // Validar archivo con reglas OWASP
            $this->validator->validate($metadata);

            return [
                'success' => true,
                'action' => 'store',
                'metadata' => $metadata,
            ];
        } catch (InvalidArgumentException $e) {
            $this->logEvent('chunk_uploaded_validation_failed', $payload->dataSourceId, [
                'error' => $e->getMessage(),
                'file_data_keys' => array_keys($fileData),
            ]);

            // Limpiar archivo temporal si existe
            $path = $this->normalizer->extractPath($fileData);
            if ($path !== null) {
                $this->cleanupTemporaryFile($path);
            }

            throw new RuntimeException(
                'El archivo cargado no pasó las validaciones de seguridad: ' . $e->getMessage(),
                previous: $e
            );
        } catch (RuntimeException $e) {
            $this->logEvent('chunk_uploaded_storage_failed', $payload->dataSourceId, [
                'error' => $e->getMessage(),
            ]);

            // Limpiar archivo temporal si existe
            $path = $this->normalizer->extractPath($fileData);
            if ($path !== null) {
                $this->cleanupTemporaryFile($path);
            }

            throw $e;
        } catch (Throwable $e) {
            $this->logEvent('chunk_uploaded_unexpected_error', $payload->dataSourceId, [
                'error_class' => $e::class,
                'error_message' => $e->getMessage(),
            ]);

            report($e);

            throw new RuntimeException(
                'Ocurrió un error inesperado al procesar el archivo cargado.',
                previous: $e
            );
        }
    }

    /**
     * Maneja el evento de fallo en la carga.
     *
     * @return array{success: true, action: 'reset', error: string}
     */
    public function handleFailed(ChunkUploadEventPayload $payload): array
    {
        $errorMessage = $payload->message ?? 'Error desconocido durante la carga.';

        $this->logEvent('chunk_upload_failed', $payload->dataSourceId, [
            'status' => $payload->status,
            'error' => $errorMessage,
        ]);

        return [
            'success' => true,
            'action' => 'reset',
            'error' => $errorMessage,
        ];
    }

    /**
     * Maneja el evento de cancelación de carga.
     *
     * @return array{success: true, action: 'reset'}
     */
    public function handleCancelled(ChunkUploadEventPayload $payload): array
    {
        $this->logEvent('chunk_upload_cancelled', $payload->dataSourceId, [
            'status' => $payload->status,
            'message' => $payload->message,
        ]);

        return [
            'success' => true,
            'action' => 'reset',
        ];
    }

    /**
     * Maneja el evento de limpieza de archivo cargado.
     *
     * @return array{success: true, action: 'clear'}
     */
    public function handleCleared(ChunkUploadEventPayload $payload): array
    {
        $this->logEvent('chunk_upload_cleared', $payload->dataSourceId, [
            'status' => $payload->status,
            'file_path' => $payload->filePath,
        ]);

        // Limpiar archivo temporal si existe
        if ($payload->filePath !== null) {
            $this->cleanupTemporaryFile($payload->filePath);
        }

        return [
            'success' => true,
            'action' => 'clear',
        ];
    }

    /**
     * Maneja cualquier evento de ciclo de vida del upload.
     *
     * @param array<string, mixed>|null $fileData
     *
     * @return array{success: bool, action: string, metadata?: UploadedFileMetadata, error?: string}
     */
    public function handle(ChunkUploadEventPayload $payload, ?array $fileData = null): array
    {
        return match (true) {
            $payload->isUploading() => $this->handleUploading($payload),
            $payload->isCompleted() && $fileData !== null => $this->handleUploaded($payload, $fileData),
            $payload->isError() => $this->handleFailed($payload),
            $payload->isCancelled() => $this->handleCancelled($payload),
            default => $this->handleCleared($payload),
        };
    }

    /**
     * Limpia un archivo temporal del disco.
     */
    private function cleanupTemporaryFile(string $path): void
    {
        if (trim($path) === '') {
            return;
        }

        try {
            if ($this->disk->exists($path)) {
                $this->disk->delete($path);
            }

            $directory = trim(dirname($path), '/');

            if ($directory !== '' && $this->disk->exists($directory)) {
                $files = $this->disk->files($directory);

                if ($files === [] || count($files) === 0) {
                    $this->disk->deleteDirectory($directory);
                }
            }
        } catch (Throwable $e) {
            Log::warning('No fue posible limpiar el archivo temporal.', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Registra un evento en los logs.
     *
     * @param non-empty-string $event
     * @param positive-int $dataSourceId
     * @param array<string, mixed> $context
     */
    private function logEvent(string $event, int $dataSourceId, array $context = []): void
    {
        Log::info(
            sprintf('Collection notice chunk event: %s', $event),
            array_merge([
                'event' => $event,
                'data_source_id' => $dataSourceId,
                'handler' => self::class,
            ], $context)
        );
    }
}