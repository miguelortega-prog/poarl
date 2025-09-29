<?php

namespace App\UseCases\Recaudo\Comunicados;

use App\DTOs\Recaudo\Comunicados\CreateCollectionNoticeRunDto;
use App\DTOs\Recaudo\Comunicados\RunStoredFileDto;
use App\Models\CollectionNoticeType;
use App\Models\CollectionNoticeRun;
use App\Repositories\Interfaces\CollectionNoticeRunFileRepositoryInterface;
use App\Repositories\Interfaces\CollectionNoticeRunRepositoryInterface;
use App\Services\Uploads\ChunkedUploadCleaner;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;
use function str_contains;

final class CreateCollectionNoticeRunUseCase
{
    public function __construct(
        private readonly CollectionNoticeRunRepositoryInterface $runRepo,
        private readonly CollectionNoticeRunFileRepositoryInterface $fileRepo,
        private readonly DatabaseManager $db,
        private readonly FilesystemFactory $filesystem,
        private readonly ChunkedUploadCleaner $uploadCleaner,
    ) {}

    /**
     * @return array{run: CollectionNoticeRun}
     */
    public function __invoke(CreateCollectionNoticeRunDto $dto): array
    {
        $type = CollectionNoticeType::query()
            ->with(['dataSources:id'])
            ->findOrFail($dto->collectionNoticeTypeId);

        $requiredIds = $type->dataSources->pluck('id')
            ->map(fn ($v) => (int) $v)
            ->values()
            ->all();
        
        $providedIds = array_keys($dto->files);

        sort($requiredIds);
        $providedIds = array_map('intval', $providedIds);
        sort($providedIds);

        if ($requiredIds !== $providedIds) {
            throw new RuntimeException('Los insumos no corresponden 1:1 con los requeridos por el tipo seleccionado.');
        }

        $storedPaths = [];
        $diskName = 'collection';

        /** @var FilesystemAdapter $disk */
        $disk = $this->filesystem->disk($diskName);

        /** @var FilesystemAdapter $tempDisk */
        $tempDisk = $this->filesystem->disk('collection_temp');

        try {
            $result = $this->db->transaction(function () use ($dto, $disk, $diskName, $tempDisk, &$storedPaths) {
                $run = $this->runRepo->create($dto);

                foreach ($dto->files as $dataSourceId => $file) {
                    if (! is_array($file)) {
                        throw new RuntimeException('Archivo inválido recibido durante la creación del comunicado.');
                    }

                    $tempPath = $file['path'] ?? null;
                    if (! is_string($tempPath) || $tempPath === '' || ! $tempDisk->exists($tempPath)) {
                        throw new RuntimeException('El archivo temporal no está disponible para su procesamiento.');
                    }

                    $originalName = (string) ($file['original_name'] ?? 'archivo');
                    $providedExt = (string) ($file['extension'] ?? '');
                    $ext = strtolower($providedExt ?: (pathinfo($originalName, PATHINFO_EXTENSION) ?: '')) ?: null;
                    $safeBase = Str::slug(pathinfo($originalName, PATHINFO_FILENAME));
                    $storedName = sprintf(
                        '%s_%s%s',
                        $safeBase ?: 'insumo',
                        now()->format('Ymd_His'),
                        $ext ? '.' . $ext : ''
                    );

                    $relativeDir = sprintf('collection_notice_runs/%d/%d', $run->id, (int) $dataSourceId);
                    $relativePath = $relativeDir . '/' . $storedName;

                    if (method_exists($disk, 'ensureDirectoryExists')) {
                        $disk->ensureDirectoryExists($relativeDir);
                    } else {
                        $disk->makeDirectory($relativeDir);
                    }

                    $size = isset($file['size']) ? (int) $file['size'] : 0;
                    if ($size <= 0) {
                        $size = (int) $tempDisk->size($tempPath);
                    }

                    $this->transferUploadedFile($tempDisk, $tempPath, $disk, $relativePath);

                    $this->cleanupTemporaryArtifacts($tempDisk, $tempPath);

                    $storedPaths[] = [$diskName, $relativePath];

                    $this->fileRepo->create(
                        $run->id,
                        new RunStoredFileDto(
                            noticeDataSourceId: (int) $dataSourceId,
                            originalName: $originalName,
                            storedName: $storedName,
                            disk: $diskName,
                            path: $relativePath,
                            size: $size,
                            mime: isset($file['mime']) && $file['mime'] !== '' ? (string) $file['mime'] : null,
                            ext: $ext,
                            uploadedBy: $dto->requestedById,
                        )
                    );
                }

                return ['run' => $run];
            }, 3);
        } catch (Throwable $e) {
            foreach ($storedPaths as [$cleanupDisk, $cleanupPath]) {
                $this->filesystem->disk($cleanupDisk)->delete($cleanupPath);
            }

            throw $e;
        }

        $ttlMinutes = (int) config('chunked-uploads.collection_notices.cleanup_ttl_minutes');

        if ($ttlMinutes > 0) {
            $this->uploadCleaner->purgeExpired($ttlMinutes);
        }

        /** @var array{run: CollectionNoticeRun} $result */
        return $result;
    }

    /**
     * @throws RuntimeException
     */
    private function transferUploadedFile(
        FilesystemAdapter $sourceDisk,
        string $sourcePath,
        FilesystemAdapter $targetDisk,
        string $targetPath
    ): void {
        $sourceAbsolute = $this->resolveAbsolutePath($sourceDisk, $sourcePath);
        $targetAbsolute = $this->resolveAbsolutePath($targetDisk, $targetPath);

        if ($sourceAbsolute !== null && $targetAbsolute !== null && $this->attemptFilesystemRename($sourceAbsolute, $targetAbsolute)) {
            return;
        }

        $readStream = $sourceDisk->readStream($sourcePath);

        if ($readStream === false) {
            throw new RuntimeException('No fue posible leer el archivo temporal para su almacenamiento.');
        }

        try {
            $targetDisk->writeStream($targetPath, $readStream);
        } finally {
            if (is_resource($readStream)) {
                fclose($readStream);
            }
        }

        $sourceDisk->delete($sourcePath);
    }

    /**
     * Limpia los directorios temporales asociados a una carga ya procesada.
     */
    private function cleanupTemporaryArtifacts(FilesystemAdapter $disk, string $originalPath): void
    {
        $directory = trim(dirname($originalPath), '/');

        $hasSeparator = str_contains($directory, '/') || str_contains($directory, '\\');

        if ($directory === '' || $directory === '.' || ! $hasSeparator) {
            return;
        }

        if ($disk->exists($directory)) {
            try {
                $disk->deleteDirectory($directory);
            } catch (Throwable) {
                // Ignorar errores de limpieza; no comprometen la creación del comunicado.
            }
        }
    }

    /**
     * @return string|null Ruta absoluta si el adaptador la soporta, null en caso contrario.
     */
    private function resolveAbsolutePath(FilesystemAdapter $disk, string $path): ?string
    {
        if ($path === '' || ! method_exists($disk, 'path')) {
            return null;
        }

        try {
            return $disk->path($path);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Intenta mover el archivo usando la operación nativa del sistema de archivos.
     */
    private function attemptFilesystemRename(string $source, string $destination): bool
    {
        $destinationDirectory = dirname($destination);

        if (! is_dir($destinationDirectory) && ! @mkdir($destinationDirectory, 0755, true) && ! is_dir($destinationDirectory)) {
            return false;
        }

        return @rename($source, $destination);
    }
}
