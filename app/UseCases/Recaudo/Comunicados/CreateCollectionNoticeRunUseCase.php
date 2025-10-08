<?php

declare(strict_types=1);

namespace App\UseCases\Recaudo\Comunicados;

use App\DTOs\Recaudo\Comunicados\CreateCollectionNoticeRunDto;
use App\DTOs\Recaudo\Comunicados\RunStoredFileDto;
use App\Jobs\ConvertExcelToCsvJob;
use App\Jobs\ProcessCollectionRunValidation;
use App\Models\CollectionNoticeRun;
use App\Models\CollectionNoticeType;
use App\Repositories\Interfaces\CollectionNoticeRunFileRepositoryInterface;
use App\Repositories\Interfaces\CollectionNoticeRunRepositoryInterface;
use App\Services\Uploads\ChunkedUploadCleaner;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Log;
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

                $size = isset($file['size']) ? (int) $file['size'] : 0;
                if ($size <= 0) {
                    $size = (int) $tempDisk->size($tempPath);
                }

                $storedPaths[] = [$diskName, $relativePath];

                $tempDisk->delete($tempPath);
                $tempDirectory = trim(dirname($tempPath), '/');
                if ($tempDirectory !== '') {
                    $tempDisk->deleteDirectory($tempDirectory);
                }

                $fileRecord = $this->fileRepo->create(
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

                // Si es archivo Excel, despachar job de conversión a CSV
                if ($ext !== null && in_array($ext, ['xlsx', 'xls'], true)) {
                    Log::info('Despachando job de conversión Excel a CSV', [
                        'run_id' => $run->id,
                        'file_id' => $fileRecord->id,
                        'file_path' => $relativePath,
                        'size_mb' => round($size / 1024 / 1024, 2),
                        'period' => $run->period,
                    ]);

                    // El job auto-detectará la hoja basado en el periodo del run
                    ConvertExcelToCsvJob::dispatch($fileRecord->id);
                }
            }

            // Despachar Job de validación
            Log::info('Despachando job de validación para CollectionNoticeRun', [
                'run_id' => $run->id,
                'type_id' => $run->collection_notice_type_id,
            ]);

            ProcessCollectionRunValidation::dispatch($run->id);

            return ['run' => $run];
        }, 3);
        } catch (Throwable $e) {
            foreach ($storedPaths as [$cleanupDisk, $cleanupPath]) {
                $this->filesystem->disk($cleanupDisk)->delete($cleanupPath);
            }

            throw $e;
        } finally {
            // Limpiar uploads expirados
            $ttlMinutes = (int) config('chunked-uploads.collection_notices.cleanup_ttl_minutes');

            if ($ttlMinutes > 0) {
                $this->uploadCleaner->purgeExpired($ttlMinutes);
            }
        }
    }
}
