<?php

namespace App\Services\Recaudo\Comunicados;

use App\DTOs\Recaudo\Comunicados\CollectionRunUploadedFileDto;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use RuntimeException;
use Throwable;

final class CollectionRunUploadedFileSanitizer
{
    /**
     * @param array{path:string, original_name:string, size:int, mime:?string, extension:?string} $file
     */
    public function sanitizeFromArray(int $dataSourceId, array $file, array $requirement, int $maxFileSize): CollectionRunUploadedFileDto
    {
        $disk = Storage::disk('collection_temp');
        $path = (string) ($file['path'] ?? '');

        if ($path === '' || ! $disk->exists($path)) {
            throw new RuntimeException(__('El archivo temporal no está disponible para validación.'));
        }

        $size = (int) ($file['size'] ?? 0);

        if ($size <= 0) {
            $size = (int) $disk->size($path);
        }

        if ($size <= 0) {
            throw new RuntimeException(__('El archivo cargado está vacío.'));
        }

        if ($size > $maxFileSize) {
            throw new RuntimeException(__('El archivo excede el tamaño máximo permitido.'));
        }

        $originalName = (string) ($file['original_name'] ?? '');

        if ($originalName === '') {
            throw new RuntimeException(__('El nombre del archivo cargado es inválido.'));
        }

        $detectedExtension = strtolower((string) ($file['extension'] ?? pathinfo($originalName, PATHINFO_EXTENSION) ?? ''));

        if ($detectedExtension === '') {
            $detectedExtension = null;
        }

        $this->validateExtension($requirement, $detectedExtension);

        $mime = isset($file['mime']) && is_string($file['mime']) ? $file['mime'] : null;

        if (! $mime) {
            try {
                $mime = $disk->mimeType($path) ?: null;
            } catch (Throwable) {
                $mime = null;
            }
        }

        if ($mime !== null) {
            $this->validateMime($requirement, $mime);
        }

        return new CollectionRunUploadedFileDto(
            path: $path,
            originalName: $originalName,
            size: $size,
            mime: $mime,
            extension: $detectedExtension,
        );
    }

    public function sanitizeFromTemporaryUpload(
        TemporaryUploadedFile $uploadedFile,
        int $dataSourceId,
        array $requirement,
        int $maxFileSize
    ): CollectionRunUploadedFileDto {
        $originalName = $uploadedFile->getClientOriginalName() ?: $uploadedFile->getFilename();
        $baseName = pathinfo($originalName, PATHINFO_FILENAME) ?: 'insumo';
        $safeBase = Str::slug($baseName);

        if ($safeBase === '') {
            $safeBase = 'insumo_' . $dataSourceId;
        }

        $extension = $uploadedFile->getClientOriginalExtension();

        if (! $extension) {
            $extension = pathinfo($originalName, PATHINFO_EXTENSION) ?: null;
        }

        $extension = $extension ? strtolower((string) $extension) : null;

        $directory = 'completed/' . (string) Str::uuid();
        $storedName = $safeBase . ($extension ? '.' . $extension : '');

        $relativePath = $uploadedFile->storeAs($directory, $storedName, 'collection_temp');

        if (! is_string($relativePath) || $relativePath === '') {
            throw new RuntimeException('No fue posible guardar temporalmente el archivo recibido.');
        }

        $size = (int) $uploadedFile->getSize();

        if ($size <= 0) {
            $size = (int) Storage::disk('collection_temp')->size($relativePath);
        }

        if ($size > $maxFileSize) {
            $this->cleanup($relativePath);

            throw new RuntimeException('El archivo excede el tamaño máximo permitido.');
        }

        $prepared = [
            'path' => $relativePath,
            'original_name' => $originalName,
            'size' => $size,
            'mime' => $uploadedFile->getMimeType() ?: null,
            'extension' => $extension,
        ];

        try {
            return $this->sanitizeFromArray($dataSourceId, $prepared, $requirement, $maxFileSize);
        } catch (Throwable $exception) {
            $this->cleanup($relativePath);

            throw $exception;
        }
    }

    public function normalizeFromChunkPayload(array $file): ?CollectionRunUploadedFileDto
    {
        $path = isset($file['path']) ? (string) $file['path'] : '';
        $originalName = isset($file['original_name']) ? (string) $file['original_name'] : '';
        $size = isset($file['size']) ? (int) $file['size'] : 0;

        if ($path !== '' && $size <= 0 && Storage::disk('collection_temp')->exists($path)) {
            $size = (int) Storage::disk('collection_temp')->size($path);
        }

        if ($path === '' || $originalName === '' || $size <= 0) {
            return null;
        }

        $mime = isset($file['mime']) && is_string($file['mime']) ? $file['mime'] : null;
        $extension = isset($file['extension']) && is_string($file['extension']) ? strtolower($file['extension']) : null;

        if ($extension !== null && $extension === '') {
            $extension = null;
        }

        return new CollectionRunUploadedFileDto(
            path: $path,
            originalName: $originalName,
            size: $size,
            mime: $mime,
            extension: $extension,
        );
    }

    public function cleanup(string $path): void
    {
        if ($path === '') {
            return;
        }

        $disk = Storage::disk('collection_temp');
        $disk->delete($path);

        $directory = trim(dirname($path), '/');

        if ($directory !== '') {
            $disk->deleteDirectory($directory);
        }
    }

    /**
     * @return array{id:int, name:string, code:string, extension:?string}
     */
    public function resolveRequirement(int $dataSourceId, array $dataSources): array
    {
        foreach ($dataSources as $dataSource) {
            if ((int) ($dataSource['id'] ?? 0) === $dataSourceId) {
                return $dataSource;
            }
        }

        throw new RuntimeException(__('El insumo seleccionado no es válido.'));
    }

    public function allowedExtensionsFromRequirement(string $extension): array
    {
        return match (strtolower($extension)) {
            'csv' => ['csv', 'txt'],
            'xls' => ['xls'],
            'xlsx' => ['xlsx', 'xls'],
            default => ['csv', 'xls', 'xlsx'],
        };
    }

    public function allowedMimesFromRequirement(string $extension): array
    {
        return match (strtolower($extension)) {
            'csv' => ['text/csv', 'text/plain', 'application/vnd.ms-excel'],
            'xls' => ['application/vnd.ms-excel'],
            'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/vnd.ms-excel'],
            default => [
                'text/csv',
                'text/plain',
                'application/vnd.ms-excel',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ],
        };
    }

    public function extensionErrorMessage(string $extension): string
    {
        return match (strtolower($extension)) {
            'csv' => __('Formato inválido. Este insumo solo acepta archivos CSV o TXT.'),
            'xls' => __('Formato inválido. Este insumo solo acepta archivos XLS.'),
            'xlsx' => __('Formato inválido. Este insumo solo acepta archivos XLSX o XLS.'),
            default => __('Formato inválido. Este insumo permite archivos CSV, XLS o XLSX.'),
        };
    }

    public function formatBytes(int $bytes): string
    {
        if ($bytes <= 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $value = (float) $bytes;
        $index = 0;

        while ($value >= 1024 && $index < count($units) - 1) {
            $value /= 1024;
            $index += 1;
        }

        $decimals = $value >= 10 || $index === 0 ? 0 : 1;

        return number_format($value, $decimals, ',', '.') . ' ' . $units[$index];
    }

    private function validateExtension(array $requirement, ?string $extension): void
    {
        $requirementExtension = strtolower((string) ($requirement['extension'] ?? ''));

        if ($extension === null || $extension === '') {
            return;
        }

        $allowed = $this->allowedExtensionsFromRequirement($requirementExtension);

        if (! in_array($extension, $allowed, true)) {
            throw new RuntimeException($this->extensionErrorMessage($requirementExtension));
        }
    }

    private function validateMime(array $requirement, string $mime): void
    {
        $requirementExtension = strtolower((string) ($requirement['extension'] ?? ''));
        $allowed = $this->allowedMimesFromRequirement($requirementExtension);

        if (! in_array(strtolower($mime), $allowed, true)) {
            throw new RuntimeException(__('El tipo de archivo cargado no está permitido para este insumo.'));
        }
    }
}
