<?php

namespace App\UseCases\Recaudo\Comunicados;

use App\Repositories\Interfaces\CollectionNoticeRunFileRepositoryInterface;
use App\Repositories\Interfaces\CollectionNoticeRunRepositoryInterface;
use App\DTOs\Recaudo\Comunicados\CreateCollectionNoticeRunDto;
use App\DTOs\Recaudo\Comunicados\RunStoredFileDto;
use App\Models\CollectionNoticeType;
use App\Models\CollectionNoticeRun;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class CreateCollectionNoticeRunUseCase
{
    public function __construct(
        private readonly CollectionNoticeRunRepositoryInterface $runRepo,
        private readonly CollectionNoticeRunFileRepositoryInterface $fileRepo,
        private readonly DatabaseManager $db,
        private readonly FilesystemFactory $filesystem,
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
        $disk = $this->filesystem->disk($diskName);
        $tempDisk = $this->filesystem->disk('collection_temp');

        try {
            return $this->db->transaction(function () use ($dto, $disk, $diskName, $tempDisk, &$storedPaths) {
            // 2.1) Crear run
            $run = $this->runRepo->create($dto);

            // 2.2) Guardar cada archivo
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

                $readStream = $tempDisk->readStream($tempPath);

                if ($readStream === false) {
                    throw new RuntimeException('No fue posible leer el archivo temporal para su almacenamiento.');
                }

                $disk->put($relativePath, $readStream);

                if (is_resource($readStream)) {
                    fclose($readStream);
                }

                $storedPaths[] = [$diskName, $relativePath];

                $tempDisk->delete($tempPath);
                $tempDirectory = trim(dirname($tempPath), '/');
                if ($tempDirectory !== '') {
                    $tempDisk->deleteDirectory($tempDirectory);
                }

                $size = isset($file['size']) ? (int) $file['size'] : 0;
                if ($size <= 0) {
                    $size = (int) $tempDisk->size($tempPath);
                }

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

            // (opcional) despachar evento para encolar procesamiento
            // event(new CollectionNoticeRunCreated($run));

            return ['run' => $run];
        }, 3);
        } catch (Throwable $e) {
            foreach ($storedPaths as [$cleanupDisk, $cleanupPath]) {
                $this->filesystem->disk($cleanupDisk)->delete($cleanupPath);
            }

            throw $e;
        }
    }
}
