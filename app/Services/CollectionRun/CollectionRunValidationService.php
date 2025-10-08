<?php

declare(strict_types=1);

namespace App\Services\CollectionRun;

use App\Models\CollectionNoticeRun;
use App\Models\CollectionNoticeRunFile;
use App\Services\CollectionRun\Validators\FileStructureValidator;
use App\Services\NotificationService;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Servicio para validar archivos de un CollectionNoticeRun.
 *
 * Principios SOLID aplicados:
 * - Single Responsibility: Solo valida estructura de archivos
 * - Open/Closed: Extensible mediante validadores específicos
 * - Dependency Inversion: Depende de abstracciones (FilesystemFactory)
 * - Interface Segregation: Valida solo lo necesario en esta fase
 *
 * Cumple con PSR-12 y tipado fuerte.
 * Implementa prácticas OWASP: validación de entrada, logging seguro.
 */
final readonly class CollectionRunValidationService
{
    public function __construct(
        private FilesystemFactory $filesystem,
        private FileStructureValidator $fileValidator,
        private NotificationService $notificationService
    ) {
    }

    /**
     * Valida todos los archivos de un CollectionNoticeRun.
     *
     * @param CollectionNoticeRun $run
     *
     * @return bool True si la validación fue exitosa, false si falló
     *
     * @throws Throwable
     */
    public function validate(CollectionNoticeRun $run): bool
    {
        // Actualizar estado a validating
        $run->update([
            'status' => 'validating',
            'started_at' => now(),
        ]);

        Log::info('Iniciando validación de archivos', [
            'run_id' => $run->id,
            'type_id' => $run->collection_notice_type_id,
            'files_count' => $run->files->count(),
        ]);

        $validationErrors = [];
        $startTime = microtime(true);

        try {
            DB::beginTransaction();

            // Obtener los data sources esperados para este tipo de comunicado
            $expectedDataSourceIds = $run->type->dataSources->pluck('id')->toArray();

            foreach ($run->files as $file) {
                try {
                    // PRIMERO: Validar que el data source del archivo pertenece al tipo de comunicado
                    if (!in_array($file->notice_data_source_id, $expectedDataSourceIds, true)) {
                        throw new RuntimeException(
                            sprintf(
                                'Este archivo corresponde al insumo "%s" que no es válido para este tipo de comunicado. Los insumos esperados son: %s.',
                                $file->dataSource->name ?? 'Desconocido',
                                implode(', ', $run->type->dataSources->pluck('name')->toArray())
                            )
                        );
                    }

                    // SEGUNDO: Validar estructura del archivo
                    $this->validateFile($file);

                    Log::info('Archivo validado exitosamente', [
                        'run_id' => $run->id,
                        'file_id' => $file->id,
                        'data_source_id' => $file->notice_data_source_id,
                    ]);
                } catch (RuntimeException $exception) {
                    $errorMessage = $exception->getMessage(); // No sanitizar aún para debugging

                    $validationErrors[] = [
                        'file_id' => $file->id,
                        'file_name' => $file->original_name,
                        'data_source_id' => $file->notice_data_source_id,
                        'data_source_name' => $file->dataSource->name ?? 'Desconocido',
                        'error' => $errorMessage,
                    ];

                    Log::warning('Archivo falló validación', [
                        'run_id' => $run->id,
                        'file_id' => $file->id,
                        'file_name' => $file->original_name,
                        'data_source' => $file->dataSource->name ?? 'Desconocido',
                        'error' => $errorMessage,
                    ]);
                }
            }

            $duration = (int) ((microtime(true) - $startTime) * 1000);

            // Si hay errores de validación, marcar como validation_failed
            if ($validationErrors !== []) {
                $run->update([
                    'status' => 'validation_failed',
                    'failed_at' => now(),
                    'duration_ms' => $duration,
                    'errors' => [
                        'message' => 'Uno o más archivos no pasaron la validación de estructura.',
                        'files' => $validationErrors,
                    ],
                ]);

                DB::commit();

                Log::error('Validación de CollectionNoticeRun falló', [
                    'run_id' => $run->id,
                    'errors_count' => count($validationErrors),
                ]);

                // Enviar notificación de fallo
                $this->sendFailureNotification($run, $validationErrors);

                // NO lanzar excepción aquí, retornar false
                // Los errores ya están guardados en la BD
                return false;
            }

            // Validación exitosa
            $run->update([
                'status' => 'validated',
                'validated_at' => now(),
                'duration_ms' => $duration,
            ]);

            DB::commit();

            Log::info('Validación de CollectionNoticeRun completada exitosamente', [
                'run_id' => $run->id,
                'duration_ms' => $duration,
            ]);

            // Enviar notificación de éxito
            $this->sendSuccessNotification($run);

            // Retornar true para indicar validación exitosa
            return true;
        } catch (Throwable $exception) {
            DB::rollBack();

            $duration = (int) ((microtime(true) - $startTime) * 1000);

            // Marcar como validation_failed
            $run->update([
                'status' => 'validation_failed',
                'failed_at' => now(),
                'duration_ms' => $duration,
                'errors' => [
                    'message' => 'Error inesperado durante la validación.',
                    'details' => $this->sanitizeErrorMessage($exception->getMessage()),
                ],
            ]);

            Log::error('Error inesperado al validar CollectionNoticeRun', [
                'run_id' => $run->id,
                'error' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }

    /**
     * Valida un archivo individual.
     *
     * @throws RuntimeException Si el archivo no pasa la validación
     */
    private function validateFile(CollectionNoticeRunFile $file): void
    {
        // Obtener disco y verificar existencia del archivo
        $disk = $this->filesystem->disk($file->disk);

        if (!$disk->exists($file->path)) {
            throw new RuntimeException(
                sprintf('El archivo no existe en la ruta especificada: %s', $file->path)
            );
        }

        // Obtener columnas esperadas desde la base de datos
        $expectedColumns = $file->dataSource
            ->columns
            ->pluck('column_name')
            ->toArray();

        if ($expectedColumns === []) {
            throw new RuntimeException(
                sprintf(
                    'No hay columnas definidas para el insumo "%s"',
                    $file->dataSource->name ?? 'Desconocido'
                )
            );
        }

        // Validar estructura del archivo
        $this->fileValidator->validate($disk, $file, $expectedColumns);
    }

    /**
     * Sanitiza mensajes de error para evitar filtración de información sensible.
     * Práctica OWASP: No exponer rutas del sistema, queries, etc.
     */
    private function sanitizeErrorMessage(string $message): string
    {
        // Remover rutas absolutas del sistema
        $sanitized = preg_replace('#/[a-zA-Z0-9_\-./]+#', '[PATH]', $message);

        // Limitar longitud del mensaje
        if ($sanitized !== null && strlen($sanitized) > 500) {
            $sanitized = substr($sanitized, 0, 500) . '...';
        }

        return $sanitized ?? $message;
    }

    /**
     * Envía notificación de validación exitosa.
     */
    private function sendSuccessNotification(CollectionNoticeRun $run): void
    {
        try {
            $this->notificationService->create([
                'user_id' => $run->requested_by_id,
                'type' => 'collection_run_validated',
                'title' => 'Validación completada exitosamente',
                'message' => sprintf(
                    'Los archivos del comunicado "%s" han sido validados correctamente y están listos para procesamiento.',
                    $run->type->name ?? 'Comunicado'
                ),
                'data' => [
                    'run_id' => $run->id,
                    'type_id' => $run->collection_notice_type_id,
                    'files_count' => $run->files->count(),
                    'duration_ms' => $run->duration_ms,
                ],
            ]);

            Log::info('Notificación de validación exitosa enviada', [
                'run_id' => $run->id,
                'user_id' => $run->requested_by_id,
            ]);
        } catch (Throwable $exception) {
            Log::error('Error al enviar notificación de validación exitosa', [
                'run_id' => $run->id,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Envía notificación de validación fallida.
     *
     * @param array<int, array{file_id: int, file_name: string, data_source_id: int, data_source_name: string, error: string}> $errors
     */
    private function sendFailureNotification(CollectionNoticeRun $run, array $errors): void
    {
        try {
            $errorCount = count($errors);
            $fileNames = array_slice(array_column($errors, 'file_name'), 0, 3);
            $filesList = implode(', ', $fileNames);

            if ($errorCount > 3) {
                $filesList .= sprintf(' y %d más', $errorCount - 3);
            }

            $this->notificationService->create([
                'user_id' => $run->requested_by_id,
                'type' => 'collection_run_validation_failed',
                'title' => 'Validación fallida',
                'message' => sprintf(
                    'La validación del comunicado "%s" falló. %d archivo(s) con errores: %s. Por favor revisa los detalles en la sección de Comunicados.',
                    $run->type->name ?? 'Comunicado',
                    $errorCount,
                    $filesList
                ),
                'data' => [
                    'run_id' => $run->id,
                    'type_id' => $run->collection_notice_type_id,
                    'error_count' => $errorCount,
                    'errors' => $errors,
                ],
            ]);

            Log::info('Notificación de validación fallida enviada', [
                'run_id' => $run->id,
                'user_id' => $run->requested_by_id,
                'error_count' => $errorCount,
            ]);
        } catch (Throwable $exception) {
            Log::error('Error al enviar notificación de validación fallida', [
                'run_id' => $run->id,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}