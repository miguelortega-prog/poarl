<?php
namespace App\Repositories;

use App\Repositories\Interfaces\CollectionNoticeRunFileRepositoryInterface;
use App\DTOs\Recaudo\Comunicados\RunStoredFileDto;
use App\Models\CollectionNoticeRunFile;

final class CollectionNoticeRunFileEloquentRepository implements CollectionNoticeRunFileRepositoryInterface
{
    public function create(int $runId, RunStoredFileDto $file): CollectionNoticeRunFile
    {
        return CollectionNoticeRunFile::query()->create([
            'collection_notice_run_id' => $runId,
            'notice_data_source_id'    => $file->noticeDataSourceId,
            'original_name'            => $file->originalName,
            'stored_name'              => $file->storedName,
            'disk'                     => $file->disk,
            'path'                     => $file->path,
            'size'                     => $file->size,
            'mime'                     => $file->mime,
            'ext'                      => $file->ext,
            'sha256'                   => $file->sha256,
            'uploaded_by'              => $file->uploadedBy,
        ]);
    }
}
