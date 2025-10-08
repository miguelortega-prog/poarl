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

        if (!in_array($run->status, [
            CollectionNoticeRunStatus::PENDING->value,
            CollectionNoticeRunStatus::VALIDATION_FAILED->value,
            CollectionNoticeRunStatus::FAILED->value,
            CollectionNoticeRunStatus::CANCELLED->value,
        ], true)) {
            throw new RuntimeException(__('Solo puedes eliminar comunicados en estado: Pendiente, Validación fallida, Fallido o Cancelado.'));
        }

        // Recolectar referencias de archivos de entrada (files)
        $inputFileReferences = $run->files
            ->map(static fn ($file) => new StoredFileReferenceDto(
                disk: (string) $file->disk,
                path: (string) $file->path,
            ))
            ->all();

        // Recolectar referencias de archivos de resultados (resultFiles)
        $resultFileReferences = $run->resultFiles
            ->map(static fn ($file) => new StoredFileReferenceDto(
                disk: (string) $file->disk,
                path: (string) $file->path,
            ))
            ->all();

        // Combinar todos los archivos a eliminar
        $allFileReferences = array_merge($inputFileReferences, $resultFileReferences);

        $this->db->transaction(function () use ($run): void {
            $this->repository->delete($run);
        });

        $this->deleteStoredFiles($allFileReferences);
    }

    /**
     * @param  array<int, StoredFileReferenceDto>  $files
     */
    private function deleteStoredFiles(array $files): void
    {
        // Eliminar archivos
        foreach ($files as $file) {
            $disk = $this->filesystem->disk($file->disk);

            if ($file->path === '') {
                continue;
            }

            if ($disk->exists($file->path) && ! $disk->delete($file->path)) {
                throw new RuntimeException(__('No fue posible eliminar uno de los archivos asociados al comunicado.'));
            }
        }

        // Intentar eliminar directorios vacíos
        // Agrupar directorios por disco
        $directoriesByDisk = [];
        foreach ($files as $file) {
            $directory = dirname($file->path);
            if ($directory !== '.') {
                if (!isset($directoriesByDisk[$file->disk])) {
                    $directoriesByDisk[$file->disk] = [];
                }
                if (!in_array($directory, $directoriesByDisk[$file->disk], true)) {
                    $directoriesByDisk[$file->disk][] = $directory;
                }
            }
        }

        // Eliminar directorios vacíos por disco
        foreach ($directoriesByDisk as $diskName => $diskDirectories) {
            $disk = $this->filesystem->disk($diskName);
            foreach ($diskDirectories as $directory) {
                if ($disk->exists($directory)) {
                    $dirFiles = $disk->allFiles($directory);
                    if (empty($dirFiles)) {
                        $disk->deleteDirectory($directory);
                    }
                }
            }
        }
    }
}
