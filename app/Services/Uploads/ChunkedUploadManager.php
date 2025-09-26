<?php

namespace App\Services\Uploads;

use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

class ChunkedUploadManager
{
    private const PENDING_DIRECTORY = 'pending';
    private const COMPLETED_DIRECTORY = 'completed';

    public function __construct(private readonly FilesystemFactory $filesystem)
    {
    }

    /**
     * @param array{original_name:?string, size:?int, mime:?string, extension:?string} $metadata
     *
     * @return array{completed: bool, file?: array{path: string, original_name: string, size: int, mime:?string, extension:?string}}
     */
    public function appendChunk(string $uploadId, int $chunkIndex, int $totalChunks, UploadedFile $chunk, array $metadata = []): array
    {
        $disk = $this->getDisk();

        if ($uploadId === '' || $totalChunks <= 0 || $chunkIndex < 0 || $chunkIndex >= $totalChunks) {
            throw new RuntimeException('Los datos del chunk recibido son inválidos.');
        }

        $basePath = $this->buildPendingPath($uploadId);
        $this->ensureDirectoryExists($disk, $basePath);

        if ($chunkIndex === 0) {
            $this->storeMetadata($disk, $basePath, $metadata);
        }

        $chunkName = $this->chunkFileName($chunkIndex);

        $disk->putFileAs($basePath, $chunk, $chunkName);

        if ($chunkIndex + 1 < $totalChunks) {
            return ['completed' => false];
        }

        $fileInfo = $this->assembleChunks($disk, $basePath, $uploadId);

        return [
            'completed' => true,
            'file' => $fileInfo,
        ];
    }

    protected function assembleChunks(Filesystem $disk, string $basePath, string $uploadId): array
    {
        $metadata = $this->readMetadata($disk, $basePath);

        $originalName = $metadata['original_name'] ?? 'archivo';
        $extension = $metadata['extension'] ?? (pathinfo($originalName, PATHINFO_EXTENSION) ?: null);
        $safeBase = Str::slug(pathinfo($originalName, PATHINFO_FILENAME)) ?: $uploadId;
        $finalName = $safeBase . ($extension ? '.' . strtolower($extension) : '');

        $targetDirectory = $this->buildCompletedPath($uploadId);
        $this->ensureDirectoryExists($disk, $targetDirectory);
        $targetPath = $targetDirectory . '/' . $finalName;

        $absoluteTarget = $disk->path($targetPath);
        $targetHandle = fopen($absoluteTarget, 'w+b');

        if ($targetHandle === false) {
            throw new RuntimeException('No fue posible abrir el archivo de destino para completar la carga.');
        }

        $chunkFiles = array_filter(
            $disk->files($basePath),
            static fn (string $file) => str_ends_with($file, '.part')
        );

        sort($chunkFiles);

        foreach ($chunkFiles as $chunkPath) {
            $sourceHandle = fopen($disk->path($chunkPath), 'rb');

            if ($sourceHandle === false) {
                fclose($targetHandle);
                throw new RuntimeException('No fue posible leer un fragmento del archivo para completarlo.');
            }

            stream_copy_to_stream($sourceHandle, $targetHandle);
            fclose($sourceHandle);
        }

        fflush($targetHandle);
        fclose($targetHandle);

        foreach ($chunkFiles as $chunkPath) {
            $disk->delete($chunkPath);
        }

        $disk->delete($basePath . '/meta.json');
        $disk->deleteDirectory($basePath);

        $size = $metadata['size'] ?? null;
        if (! $size) {
            $size = (int) $disk->size($targetPath);
        }

        return [
            'path' => $targetPath,
            'original_name' => $originalName,
            'size' => (int) $size,
            'mime' => $metadata['mime'] ?? null,
            'extension' => $extension ? strtolower($extension) : null,
        ];
    }

    protected function storeMetadata(Filesystem $disk, string $basePath, array $metadata): void
    {
        $payload = json_encode([
            'original_name' => $metadata['original_name'] ?? null,
            'size' => isset($metadata['size']) ? (int) $metadata['size'] : null,
            'mime' => $metadata['mime'] ?? null,
            'extension' => $metadata['extension'] ?? null,
        ], JSON_THROW_ON_ERROR);

        $disk->put($basePath . '/meta.json', $payload);
    }

    /**
     * @return array{original_name:?string, size:?int, mime:?string, extension:?string}
     */
    protected function readMetadata(Filesystem $disk, string $basePath): array
    {
        $metaPath = $basePath . '/meta.json';

        if (! $disk->exists($metaPath)) {
            return [
                'original_name' => null,
                'size' => null,
                'mime' => null,
                'extension' => null,
            ];
        }

        $contents = $disk->get($metaPath);

        try {
            /** @var array{original_name:?string, size:?int, mime:?string, extension:?string} $decoded */
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            Log::warning('No fue posible leer los metadatos de una carga fragmentada.', [
                'upload' => $basePath,
                'exception' => $e,
            ]);

            return [
                'original_name' => null,
                'size' => null,
                'mime' => null,
                'extension' => null,
            ];
        }

        return $decoded;
    }

    protected function chunkFileName(int $chunkIndex): string
    {
        return str_pad((string) $chunkIndex, 6, '0', STR_PAD_LEFT) . '.part';
    }

    protected function buildPendingPath(string $uploadId): string
    {
        return self::PENDING_DIRECTORY . '/' . $uploadId;
    }

    protected function buildCompletedPath(string $uploadId): string
    {
        return self::COMPLETED_DIRECTORY . '/' . $uploadId;
    }

    protected function getDisk(): Filesystem
    {
        return $this->filesystem->disk('collection_temp');
    }

    protected function ensureDirectoryExists(Filesystem $disk, string $path): void
    {
        if ($path === '') {
            return;
        }

        if (method_exists($disk, 'ensureDirectoryExists')) {
            $disk->ensureDirectoryExists($path);

            return;
        }

        $disk->makeDirectory($path);
    }
}
