<?php

namespace App\UseCases\Recaudo\Comunicados;

use App\DTOs\Recaudo\Comunicados\DeleteCollectionNoticeRunDto;
use App\DTOs\Recaudo\Comunicados\StoredFileReferenceDto;
use App\Enums\Recaudo\CollectionNoticeRunStatus;
use App\Models\CollectionNoticeRun;
use App\Repositories\Interfaces\CollectionNoticeRunRepositoryInterface;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use RuntimeException;

final class DeleteCollectionNoticeRunUseCase
{
    public function __construct(
        private readonly CollectionNoticeRunRepositoryInterface $repository,
        private readonly DatabaseManager $db,
        private readonly FilesystemFactory $filesystem,
    ) {
    }

    public function __invoke(DeleteCollectionNoticeRunDto $dto): void
    {
        $run = $this->repository->findWithFiles($dto->runId);

        if ($run === null) {
            throw (new ModelNotFoundException())->setModel(CollectionNoticeRun::class, [$dto->runId]);
        }

        if ($run->status !== CollectionNoticeRunStatus::READY->value) {
            throw new RuntimeException(__('Solo puedes eliminar comunicados en estado listo.'));
        }

        $fileReferences = $run->files
            ->map(static fn ($file) => new StoredFileReferenceDto(
                disk: (string) $file->disk,
                path: (string) $file->path,
            ))
            ->all();

        $this->db->transaction(function () use ($run): void {
            $this->repository->delete($run);
        });

        $this->deleteStoredFiles($fileReferences);
    }

    /**
     * @param  array<int, StoredFileReferenceDto>  $files
     */
    private function deleteStoredFiles(array $files): void
    {
        foreach ($files as $file) {
            $disk = $this->filesystem->disk($file->disk);

            if ($file->path === '') {
                continue;
            }

            if ($disk->exists($file->path) && ! $disk->delete($file->path)) {
                throw new RuntimeException(__('No fue posible eliminar uno de los archivos asociados al comunicado.'));
            }
        }
    }
}
