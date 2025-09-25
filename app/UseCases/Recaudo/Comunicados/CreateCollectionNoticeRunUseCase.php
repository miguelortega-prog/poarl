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

        return $this->db->transaction(function () use ($dto, $disk, $diskName, &$storedPaths) {
            // 2.1) Crear run
            $run = $this->runRepo->create($dto);

            // 2.2) Guardar cada archivo
            foreach ($dto->files as $dataSourceId => $file) {
                // Datos base
                $originalName = $file->getClientOriginalName() ?? 'archivo';
                $ext = pathinfo($originalName, PATHINFO_EXTENSION) ?: null;
                $safeBase = Str::slug(pathinfo($originalName, PATHINFO_FILENAME));
                $storedName = sprintf('%s_%s.%s',
                    $safeBase ?: 'insumo',
                    now()->format('Ymd_His'),
                    $ext ?: 'bin'
                );

                $relativeDir = sprintf('collection_notice_runs/%d/%d', $run->id, (int) $dataSourceId);
                $relativePath = $relativeDir . '/' . $storedName;

                // subir
                $disk->putFileAs($relativeDir, $file, $storedName);
                $storedPaths[] = [$diskName, $relativePath];

                $sha256 = null;
                $realPath = $file->getRealPath();
                if ($realPath && is_readable($realPath)) {
                    $sha256 = hash_file('sha256', $realPath) ?: null;
                }

                // persistir metadata
                $this->fileRepo->create(
                    $run->id,
                    new RunStoredFileDto(
                        noticeDataSourceId: (int) $dataSourceId,
                        originalName: $originalName,
                        storedName: $storedName,
                        disk: $diskName,
                        path: $relativePath,
                        size: (int) $file->getSize(),
                        mime: $file->getMimeType(),
                        ext: $ext ? strtolower($ext) : null,
                        sha256: $sha256,
                        uploadedBy: $dto->requestedById,
                    )
                );
            }

            // (opcional) despachar evento para encolar procesamiento
            // event(new CollectionNoticeRunCreated($run));

            return ['run' => $run];
        }, 3);
    }
}
